<?php
/**
 * Deployment / System Update — landing page.
 * Upload a delta package, and review deployment history.
 *
 * Viewing needs deployment.view; actually deploying additionally needs
 * deployment.deploy AND Super Admin (enforced in deploy.php, mirrored here so
 * the buttons match what the server will accept).
 */
$page_title = 'System Update';
require_once __DIR__ . '/../../includes/header.php';
// Load the engine directly — see the note in details.php. A package can replace
// bootstrap.php and strip its `require deployer.php`; deployer.php is protected
// and cannot be replaced, so this keeps the module reachable regardless.
require_once __DIR__ . '/../../includes/deployer.php';
require_permission('deployment', 'view');

$db      = db();
$isLive  = deploy_is_live();
$canDeploy   = can('deployment', 'deploy')   && is_super_admin() && DEPLOYMENT_ENABLED;
$storageOk   = deploy_storage_ready();
$lock        = deploy_lock_info();

$history = $db->query(
    'SELECT * FROM deployments ORDER BY id DESC LIMIT 50'
)->fetchAll();

/* Which deployments actually executed packaged SQL, as deployment_id => count.
 * Fetched in one query rather than one per row.
 *
 * The rollback feature has been removed, but rows from when it existed are
 * still in the history. Those marked ROLLED_BACK had their FILES restored and
 * their database changes left applied, so the ones that ran SQL still need
 * flagging — otherwise the history claims they left no trace. */
$sqlApplied = $db->query(
    "SELECT deployment_id, COUNT(*) FROM deployment_files
      WHERE status = 'SQL_APPLIED' GROUP BY deployment_id"
)->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<style>
.dep-env {
    display:inline-flex; align-items:center; gap:8px; padding:6px 14px; border-radius:20px;
    font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
}
.dep-env-local { background:#d1fae5; color:#065f46; }
.dep-env-live  { background:#fee2e2; color:#991b1b; }
.dep-drop {
    border:2px dashed var(--border); border-radius:var(--radius); padding:26px;
    text-align:center; background:var(--bg-secondary);
}
.dep-status { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; }
.ds-SUCCESS     { background:#d4edda; color:#1a7a40; }
.ds-FAILED      { background:#f8d7da; color:#842029; }
.ds-ROLLED_BACK { background:#fff3cd; color:#856404; }
.ds-PENDING     { background:#e2e3e5; color:#444; }
.dep-note { font-size:12px; color:var(--text-muted); }
</style>

<div class="page-head">
    <div>
        <h1>Deployment / System Update</h1>
        <p class="muted">Apply a delta package to this installation.</p>
    </div>
    <div>
        <span class="dep-env <?= $isLive ? 'dep-env-live' : 'dep-env-local' ?>">
            <i class="fa <?= $isLive ? 'fa-triangle-exclamation' : 'fa-laptop-code' ?>"></i>
            Environment: <?= $isLive ? 'LIVE' : 'LOCAL' ?>
        </span>
    </div>
</div>

<?= render_flash() ?>

<?php if (!DEPLOYMENT_ENABLED): ?>
<div class="alert alert-error" style="margin-top:14px">
    <strong>The deployment module is disabled.</strong>
    <code>DEPLOYMENT_ENABLED</code> is set to false in the application configuration.
</div>
<?php endif; ?>

<?php if ($isLive): ?>
<div class="alert alert-warn" style="margin-top:14px">
    <strong>&#9888; LIVE SERVER.</strong>
    Deployments here modify production files immediately. Take a database backup
    and deploy outside working hours where possible.
</div>
<?php endif; ?>

<?php if (!$storageOk): ?>
<div class="alert alert-error" style="margin-top:14px">
    <strong>Deployment storage is not writable.</strong>
    Packages and backups cannot be stored, so deployment is blocked. Grant write
    access to the deployment storage directory and reload.
</div>
<?php endif; ?>

<?php if ($lock !== null): ?>
<div class="alert alert-warn" style="margin-top:14px">
    <strong>A deployment is currently in progress.</strong>
    Please wait until it is completed.
    <?php if (!empty($lock['user'])): ?>
        Started by <?= h($lock['user']) ?><?= !empty($lock['at']) ? ' at ' . h(date('d M Y H:i', strtotime($lock['at']))) : '' ?>.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$canDeploy && DEPLOYMENT_ENABLED): ?>
<div class="alert alert-info" style="margin-top:14px">
    You can review deployment history, but only a <strong>Super Admin</strong> with the
    Deploy permission can upload and apply packages.
</div>
<?php endif; ?>

<div class="row g-3" style="margin-top:14px">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-body">
                <h6 class="fw-semibold mb-3">Upload package</h6>

                <?php if ($canDeploy && $storageOk && $lock === null): ?>
                <form method="POST" enctype="multipart/form-data"
                      action="<?= BASE_URL ?>/modules/deployment/analyze.php">
                    <?= csrf_field() ?>
                    <div class="dep-drop">
                        <i class="fa fa-file-zipper fa-2x" style="color:var(--primary)"></i>
                        <div style="margin:10px 0 6px;font-weight:600">Select a delta ZIP</div>
                        <input type="file" name="package" class="form-control" accept=".zip" required>
                        <div class="dep-note" style="margin-top:8px">
                            Maximum <?= (int) (DEPLOYMENT_MAX_ZIP_BYTES / 1048576) ?> MB.
                            Folder structure inside the ZIP is preserved.
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100" style="margin-top:14px">
                        <i class="fa fa-magnifying-glass me-1"></i>Analyze package
                    </button>
                    <p class="dep-note" style="margin-top:8px;text-align:center">
                        Nothing is changed until you review the preview and confirm.
                    </p>
                </form>
                <?php else: ?>
                <div class="dep-drop">
                    <i class="fa fa-lock fa-2x" style="color:var(--text-muted)"></i>
                    <div style="margin-top:10px" class="dep-note">Uploading is unavailable.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" style="margin-top:14px">
            <div class="card-body">
                <h6 class="fw-semibold mb-2">Protected paths</h6>
                <p class="dep-note">A package can never write to these:</p>
                <div style="display:flex;flex-wrap:wrap;gap:5px">
                    <?php foreach (deploy_protected_paths() as $p): ?>
                        <span class="pill pill-neutral"><?= h($p) ?></span>
                    <?php endforeach; ?>
                </div>
                <p class="dep-note" style="margin-top:10px">
                    <code>config/</code> is protected in full because it holds the
                    per-environment <code>BASE_URL</code> and database credentials.
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-body">
                <h6 class="fw-semibold mb-3">Deployment history</h6>

                <?php if (!$history): ?>
                    <p class="dep-note">No deployments recorded yet.</p>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Deployment</th><th>When</th><th>By</th>
                            <th>Package</th><th class="text-center">Files</th>
                            <th>Status</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $d):
                        $rowSql = (int) ($sqlApplied[$d['deployment_id']] ?? 0); ?>
                        <tr>
                            <td><code><?= h($d['deployment_id']) ?></code>
                                <?php if ($d['environment'] === 'LIVE'): ?>
                                    <span class="pill pill-danger" style="font-size:10px">LIVE</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap"><?= h(date('d M Y H:i', strtotime($d['created_at']))) ?></td>
                            <td><?= h($d['user_name'] ?: '—') ?></td>
                            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                                title="<?= h($d['package_name']) ?>"><?= h($d['package_name']) ?></td>
                            <td class="text-center">
                                <span title="added"><?= (int) $d['files_added'] ?>+</span> /
                                <span title="updated"><?= (int) $d['files_updated'] ?>&#8635;</span> /
                                <span title="skipped"><?= (int) $d['files_skipped'] ?>&#8709;</span>
                            </td>
                            <td><span class="dep-status ds-<?= h($d['status']) ?>"><?= h(str_replace('_', ' ', $d['status'])) ?></span>
                                <?php // Historical rollbacks restored files only — flag the schema change left behind.
                                      if ($d['status'] === 'ROLLED_BACK' && $rowSql > 0): ?>
                                    <span class="pill pill-danger" style="font-size:10px;display:block;margin-top:3px"
                                          title="<?= (int) $rowSql ?> database change(s) from this deployment were never reverted">DB NOT REVERTED</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap">
                                <a class="btn btn-secondary btn-sm"
                                   href="<?= BASE_URL ?>/modules/deployment/details.php?id=<?= urlencode($d['deployment_id']) ?>">Details</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
