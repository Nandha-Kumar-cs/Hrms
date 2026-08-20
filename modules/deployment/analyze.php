<?php
/**
 * Step 2 — receive the upload, validate it, and show the PREVIEW.
 *
 * This page never writes to the application root. It stores the package in the
 * hardened deployment storage area and renders what a deployment WOULD do; the
 * confirm button posts to deploy.php.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
// Load the engine directly — see the note in details.php. Also guarantees
// DEPLOYMENT_ENABLED and the deploy_* helpers exist even if bootstrap.php has
// been replaced by a package that predates this module.
require_once __DIR__ . '/../../includes/deployer.php';
require_login();
require_permission('deployment', 'deploy');

$self = BASE_URL . '/modules/deployment/index.php';

if (!DEPLOYMENT_ENABLED)  { flash('error', 'The deployment module is disabled.'); redirect($self); }
if (!is_super_admin())    { flash('error', 'Only a Super Admin can deploy packages.'); redirect($self); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect($self);
verify_csrf($_POST['csrf_token'] ?? '');
if (!deploy_storage_ready()) { flash('error', 'Deployment storage is not writable.'); redirect($self); }

// ─── Validate the upload ──────────────────────────────────────────────────────
$f = $_FILES['package'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $map = [
        UPLOAD_ERR_INI_SIZE   => 'The file is larger than the server allows.',
        UPLOAD_ERR_FORM_SIZE  => 'The file is larger than the form allows.',
        UPLOAD_ERR_PARTIAL    => 'The upload was interrupted.',
        UPLOAD_ERR_NO_FILE    => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the upload.',
    ];
    flash('error', $map[$f['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'The upload failed.');
    redirect($self);
}
if (!is_uploaded_file($f['tmp_name'])) { flash('error', 'Invalid upload.'); redirect($self); }
if ((int) $f['size'] <= 0 || (int) $f['size'] > DEPLOYMENT_MAX_ZIP_BYTES) {
    flash('error', 'The package must be between 1 byte and ' . (int) (DEPLOYMENT_MAX_ZIP_BYTES / 1048576) . ' MB.');
    redirect($self);
}

// Extension AND sniffed MIME — the client-supplied name is never trusted for
// anything but display.
$origName = (string) ($f['name'] ?? 'package.zip');
if (strtolower((string) pathinfo($origName, PATHINFO_EXTENSION)) !== 'zip') {
    flash('error', 'Only .zip packages are accepted.');
    redirect($self);
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string) $finfo->file($f['tmp_name']);
if (!in_array($mime, ['application/zip', 'application/x-zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
    flash('error', 'That file is not a ZIP archive (detected: ' . h($mime) . ').');
    redirect($self);
}

// Store under a server-generated name; the original is kept only as a label.
$safeLabel = preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
$safeLabel = substr($safeLabel, -120) ?: 'package.zip';
$stamp     = date('Ymd-His');
$stored    = deploy_packages_dir() . '/' . $stamp . '-' . bin2hex(random_bytes(4)) . '.zip';

if (!@move_uploaded_file($f['tmp_name'], $stored)) {
    flash('error', 'The package could not be stored.');
    redirect($self);
}
@chmod($stored, 0600);

$pkgSize = (int) filesize($stored);
$pkgHash = hash_file('sha256', $stored) ?: null;

deploy_audit('PACKAGE_UPLOADED', '-', 'Uploaded ' . $safeLabel,
             ['size' => $pkgSize, 'sha256' => substr((string) $pkgHash, 0, 16)]);

// ─── Analyze ──────────────────────────────────────────────────────────────────
$analysis = deploy_analyze($stored);
if (!$analysis['ok']) {
    @unlink($stored);
    deploy_audit('PACKAGE_ANALYZED', '-', 'Rejected ' . $safeLabel . ': ' . $analysis['error']);
    flash('error', $analysis['error'] ?: 'The package could not be analyzed.');
    redirect($self);
}

deploy_audit('PACKAGE_ANALYZED', '-', 'Analyzed ' . $safeLabel,
             ['total' => $analysis['summary']['total'],
              'add' => $analysis['summary']['add'],
              'update' => $analysis['summary']['update'],
              'skip' => $analysis['summary']['skip']]);

// Hand the confirm step a token rather than a path, so deploy.php can never be
// pointed at an arbitrary file on the server.
$_SESSION['deploy_pending'] = [
    'file'    => $stored,
    'label'   => $safeLabel,
    'size'    => $pkgSize,
    'sha256'  => $pkgHash,
    'summary' => $analysis['summary'],
    'has_sql' => $analysis['has_sql'],
    'token'   => bin2hex(random_bytes(16)),
    'at'      => time(),
];

$page_title = 'Deployment Preview';
require_once __DIR__ . '/../../includes/header.php';

$isLive  = deploy_is_live();
$s       = $analysis['summary'];
$willDo  = $s['add'] + $s['update'];
?>

<style>
.dp-badge { display:inline-block; padding:2px 9px; border-radius:11px; font-size:11px; font-weight:700; }
.dp-ADD       { background:#d4edda; color:#1a7a40; }
.dp-UPDATE    { background:#cfe2ff; color:#084298; }
.dp-SKIP      { background:#e2e3e5; color:#444; }
.dp-PROTECTED { background:#fff3cd; color:#856404; }
.dp-SQL       { background:#f8d7da; color:#842029; }
.dp-stat { text-align:center; padding:12px 8px; border:1px solid var(--border); border-radius:var(--radius); }
.dp-stat b { display:block; font-size:22px; line-height:1.1; }
.dp-stat span { font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.06em; }
.dp-path { font-family:'Courier New',monospace; font-size:12px; word-break:break-all; }
</style>

<div class="page-head">
    <div>
        <h1>Deployment Preview</h1>
        <p class="muted"><?= h($safeLabel) ?> · <?= number_format($pkgSize / 1024, 1) ?> KB</p>
    </div>
    <div>
        <span class="dep-env <?= $isLive ? 'dep-env-live' : 'dep-env-local' ?>"
              style="display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;text-transform:uppercase;
                     background:<?= $isLive ? '#fee2e2' : '#d1fae5' ?>;color:<?= $isLive ? '#991b1b' : '#065f46' ?>">
            Environment: <?= $isLive ? 'LIVE' : 'LOCAL' ?>
        </span>
    </div>
</div>

<?php if ($isLive): ?>
<div class="alert alert-warn" style="margin-top:14px">
    <strong>&#9888; LIVE SERVER</strong><br>
    This deployment will modify production files.
</div>
<?php endif; ?>

<div class="row g-2" style="margin-top:14px">
    <div class="col"><div class="dp-stat"><b><?= (int) $s['total'] ?></b><span>Total files</span></div></div>
    <div class="col"><div class="dp-stat"><b style="color:#1a7a40"><?= (int) $s['add'] ?></b><span>New</span></div></div>
    <div class="col"><div class="dp-stat"><b style="color:#084298"><?= (int) $s['update'] ?></b><span>To update</span></div></div>
    <div class="col"><div class="dp-stat"><b><?= (int) $s['skip'] ?></b><span>Skipped</span></div></div>
    <div class="col"><div class="dp-stat"><b style="color:#856404"><?= (int) $s['protected'] ?></b><span>Protected</span></div></div>
    <?php if ($s['sql']): ?>
    <div class="col"><div class="dp-stat"><b style="color:#842029"><?= (int) $s['sql'] ?></b><span>DB changes</span></div></div>
    <?php endif; ?>
</div>

<?php
/* A package that overwrites bootstrap.php or header.php can strip out this
 * module's own wiring — most visibly the nav link. The deployment still
 * succeeds, so nothing else would tell the admin their System Update menu
 * entry just vanished. Name the files rather than burying it in the table. */
$_wiring = [];
foreach ($analysis['entries'] as $_e) {
    if ($_e['action'] === 'UPDATE'
        && in_array($_e['rel'], ['includes/bootstrap.php', 'includes/header.php'], true)) {
        $_wiring[] = $_e['rel'];
    }
}
if ($_wiring): ?>
<div class="alert alert-warn" style="margin-top:14px">
    <strong>&#9888; This package replaces core wiring files.</strong><br>
    It will overwrite <?php foreach ($_wiring as $_i => $_w): ?><code><?= h($_w) ?></code><?= $_i < count($_wiring) - 1 ? ' and ' : '' ?><?php endforeach; ?>.
    If the package predates the System Update module, those copies will not contain
    its wiring, and the <strong>System Update menu item will disappear</strong> after
    deployment. The pages themselves stay reachable by URL.
    <br>Repackage without these files — or with copies taken from this installation —
    unless you specifically intend to replace them.
</div>
<?php endif; ?>

<?php if ($analysis['has_sql']): ?>
<div class="alert alert-warn" style="margin-top:14px">
    <strong>This package contains database changes.</strong>
    The <code>.sql</code> file(s) are installed like any other file (so the migration is
    kept on disk), but they are <em>never</em> executed automatically. Tick the box in the
    confirm panel to run them as well.
    <br><strong>No database backup is taken, and a migration cannot be undone.</strong>
    Take your own backup first if the change is not trivially reversible.
</div>
<?php endif; ?>

<div class="card" style="margin-top:14px">
    <div class="card-body" style="padding:0">
        <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0">
            <thead class="table-dark">
                <tr><th>File</th><th style="width:110px">Current</th><th style="width:150px">Action</th><th>Note</th></tr>
            </thead>
            <tbody>
            <?php foreach ($analysis['entries'] as $e): ?>
                <tr>
                    <td class="dp-path"><?= h($e['rel'] ?? $e['raw']) ?></td>
                    <td>
                        <?php if ($e['action'] === 'ADD'): ?><span class="muted">New</span>
                        <?php elseif ($e['action'] === 'UPDATE'): ?>Existing
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td>
                        <span class="dp-badge dp-<?= h($e['action']) ?>"><?= h($e['action']) ?></span>
                        <?php if (!empty($e['is_sql'])): ?>
                            <span class="dp-badge dp-SQL" style="margin-left:4px">DB CHANGE</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted" style="font-size:12px"><?= h($e['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="card" style="margin-top:14px">
    <div class="card-body">
        <?php if ($willDo === 0 && !$analysis['has_sql']): ?>
            <p class="mb-2"><strong>Nothing to deploy.</strong>
               Every file in this package is already identical, protected or not permitted.</p>
            <a href="<?= BASE_URL ?>/modules/deployment/index.php" class="btn btn-secondary btn-sm">Back</a>
        <?php else: ?>
        <form method="POST" action="<?= BASE_URL ?>/modules/deployment/deploy.php"
              onsubmit="return confirm('<?= $isLive ? 'LIVE SERVER — this modifies production files.\n\n' : '' ?>Deploy <?= (int) $willDo ?> file(s)?');">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= h($_SESSION['deploy_pending']['token']) ?>">

            <?php if ($analysis['has_sql']): ?>
            <div class="form-check" style="margin-bottom:10px">
                <input class="form-check-input" type="checkbox" name="run_sql" value="1" id="runSql">
                <label class="form-check-label" for="runSql">
                    Also run the <?= (int) $s['sql'] ?> SQL file(s) in this package
                    <span class="muted">(no backup is taken — this cannot be undone)</span>
                </label>
            </div>
            <?php endif; ?>

            <?php if ($isLive): ?>
            <div class="form-check" style="margin-bottom:10px">
                <input class="form-check-input" type="checkbox" name="maintenance" value="1" id="maint" checked>
                <label class="form-check-label" for="maint">
                    Enable maintenance mode during deployment
                    <span class="muted">(other users see a notice; Super Admins are never locked out)</span>
                </label>
            </div>
            <div style="margin-bottom:12px">
                <label class="form-label" style="font-size:12px;font-weight:700">
                    Type <code>DEPLOY</code> to confirm this production change
                </label>
                <input type="text" name="confirm" class="form-control form-control-sm"
                       style="max-width:220px" autocomplete="off" required>
            </div>
            <?php endif; ?>

            <div style="display:flex;gap:8px">
                <a href="<?= BASE_URL ?>/modules/deployment/index.php" class="btn btn-secondary btn-sm">Cancel</a>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa fa-shield-halved me-1"></i>Deploy
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
