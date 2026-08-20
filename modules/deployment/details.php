<?php
/**
 * Per-deployment detail: summary, the failure banner if it failed, and the
 * full file list with before/after checksums.
 */
$page_title = 'Deployment Details';
require_once __DIR__ . '/../../includes/header.php';
/* Load the engine directly rather than trusting bootstrap.php to do it.
 * bootstrap.php is NOT a protected path, so a package can overwrite it with a
 * copy that predates this module — which removes its `require deployer.php`
 * and turns every deploy_*() call here into a fatal error, leaving no working
 * page from which to repair the damage. deployer.php IS protected, so this
 * require can never be broken by a package. require_once = free when
 * bootstrap already loaded it. */
require_once __DIR__ . '/../../includes/deployer.php';
require_permission('deployment', 'view');

$deploymentId = trim((string) ($_GET['id'] ?? ''));
if (!preg_match('/^DEP-\d{8}-\d{6}$/', $deploymentId)) {
    flash('error', 'Invalid deployment reference.');
    redirect(BASE_URL . '/modules/deployment/index.php');
}

$db = db();
$q  = $db->prepare('SELECT * FROM deployments WHERE deployment_id = ? LIMIT 1');
$q->execute([$deploymentId]);
$dep = $q->fetch();
if (!$dep) {
    flash('error', 'That deployment does not exist.');
    redirect(BASE_URL . '/modules/deployment/index.php');
}

$fq = $db->prepare('SELECT * FROM deployment_files WHERE deployment_id = ? ORDER BY
                    FIELD(action,"UPDATE","ADD","SQL","PROTECTED","SKIP"), rel_path');
$fq->execute([$deploymentId]);
$files = $fq->fetchAll();

$changedFiles = (int) $dep['files_added'] + (int) $dep['files_updated'];

/* Packaged SQL that was executed. The rollback feature has been removed, but
 * deployments rolled back while it existed restored FILES only — so a row that
 * ran SQL still has that change live in the database, and the page says so. */
$sqlApplied = deploy_sql_applied_count($deploymentId);

// One-shot result banner handed over by deploy.php.
$result = $_SESSION['deploy_result'] ?? null;
unset($_SESSION['deploy_result']);
if ($result && ($result['deployment_id'] ?? '') !== $deploymentId) $result = null;
?>

<style>
.dp-badge { display:inline-block; padding:2px 9px; border-radius:11px; font-size:11px; font-weight:700; }
.dp-ADD{background:#d4edda;color:#1a7a40}.dp-UPDATE{background:#cfe2ff;color:#084298}
.dp-SKIP{background:#e2e3e5;color:#444}.dp-PROTECTED{background:#fff3cd;color:#856404}
.dp-SQL{background:#f8d7da;color:#842029}
.dep-status{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700}
.ds-SUCCESS{background:#d4edda;color:#1a7a40}.ds-FAILED{background:#f8d7da;color:#842029}
.ds-ROLLED_BACK{background:#fff3cd;color:#856404}.ds-PENDING{background:#e2e3e5;color:#444}
.dp-path{font-family:'Courier New',monospace;font-size:12px;word-break:break-all}
.dp-hash{font-family:'Courier New',monospace;font-size:11px;color:var(--text-muted)}
.dp-kv{display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px dashed var(--border);font-size:13px}
.dp-kv:last-child{border-bottom:0}
.dp-kv span:first-child{color:var(--text-muted)}
</style>

<div class="page-head">
    <div>
        <h1>Deployment <?= h($dep['deployment_id']) ?></h1>
        <p class="muted"><?= h($dep['package_name']) ?></p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/modules/deployment/index.php" class="btn btn-secondary btn-sm">
            <i class="fa fa-arrow-left me-1"></i>All deployments
        </a>
    </div>
</div>

<?= render_flash() ?>

<?php if ($result && empty($result['ok'])): ?>
<div class="alert alert-error" style="margin-top:14px">
    <strong>Deployment Failed</strong><br><br>
    <strong>Deployment ID:</strong><br><?= h($deploymentId) ?><br><br>
    <?php if (!empty($result['failed_file'])): ?>
        <strong>Failed File:</strong><br><?= h($result['failed_file']) ?><br><br>
    <?php endif; ?>
    <strong>Automatic recovery:</strong><br><?= !empty($result['rolled_back']) ? 'SUCCESS' : 'INCOMPLETE' ?><br><br>
    <?= !empty($result['rolled_back'])
        ? 'Files added by this deployment have been removed.'
        : 'This deployment could not be fully undone. Files it OVERWROTE are still at the '
        . 'new version — no backup was taken, so the previous contents no longer exist. '
        . 'Check the file list below and redeploy a package with the correct versions.' ?>
</div>
<?php endif; ?>

<?php /* "Rolled back" means the FILES were restored. Say plainly that the schema
         change is still live, or this page reads as "left no trace". */
      if ($dep['status'] === 'ROLLED_BACK' && $sqlApplied > 0): ?>
<div class="alert alert-warn" style="margin-top:14px">
    <strong>&#9888; Files were rolled back — the database was not.</strong><br>
    This deployment executed <strong><?= (int) $sqlApplied ?></strong> database change(s).
    Rolling back restores files only, so those changes are <strong>still applied</strong>
    to the database.
    <?php if ($dep['db_backup_path']): ?>
        <br>A backup taken immediately <em>before</em> the migration ran is on disk as
        <code><?= h($dep['db_backup_path']) ?></code>. Restoring it reverts the whole
        database to that moment, so review what else has changed since before using it.
    <?php else: ?>
        <br>No pre-migration database backup was recorded for this deployment.
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3" style="margin-top:14px">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h6 class="fw-semibold mb-3">Summary</h6>
                <div class="dp-kv"><span>Status</span>
                    <strong><span class="dep-status ds-<?= h($dep['status']) ?>"><?= h(str_replace('_', ' ', $dep['status'])) ?></span></strong></div>
                <div class="dp-kv"><span>Environment</span><strong><?= h($dep['environment']) ?></strong></div>
                <div class="dp-kv"><span>Deployed by</span><strong><?= h($dep['user_name'] ?: '—') ?></strong></div>
                <div class="dp-kv"><span>Started</span><strong><?= h(date('d M Y, h:i A', strtotime($dep['created_at']))) ?></strong></div>
                <?php if ($dep['completed_at']): ?>
                <div class="dp-kv"><span>Completed</span><strong><?= h(date('d M Y, h:i A', strtotime($dep['completed_at']))) ?></strong></div>
                <?php endif; ?>
                <?php if ($dep['rolled_back_at']): ?>
                <div class="dp-kv"><span>Rolled back</span><strong><?= h(date('d M Y, h:i A', strtotime($dep['rolled_back_at']))) ?></strong></div>
                <?php endif; ?>
                <div class="dp-kv"><span>Package size</span><strong><?= number_format($dep['package_size'] / 1024, 1) ?> KB</strong></div>
                <div class="dp-kv"><span>Total files</span><strong><?= (int) $dep['total_files'] ?></strong></div>
                <div class="dp-kv"><span>Added</span><strong><?= (int) $dep['files_added'] ?></strong></div>
                <div class="dp-kv"><span>Updated</span><strong><?= (int) $dep['files_updated'] ?></strong></div>
                <div class="dp-kv"><span>Skipped</span><strong><?= (int) $dep['files_skipped'] ?></strong></div>
                <?php if ($dep['backup_path']): ?>
                <div class="dp-kv"><span>Backup</span><strong><?= h($dep['backup_path']) ?></strong></div>
                <?php endif; ?>
                <?php if ($dep['db_backup_path']): ?>
                <div class="dp-kv"><span>DB backup</span><strong><?= h($dep['db_backup_path']) ?></strong></div>
                <?php endif; ?>
                <?php if (!empty($dep['package_sha256'])): ?>
                <div class="dp-kv"><span>Package SHA-256</span>
                    <strong class="dp-hash"><?= h(substr($dep['package_sha256'], 0, 24)) ?>…</strong></div>
                <?php endif; ?>

                <?php if ($dep['error_message']): ?>
                <div class="alert alert-error" style="margin-top:12px;font-size:12px">
                    <?= h($dep['error_message']) ?>
                </div>
                <?php endif; ?>

                <?php if ($dep['status'] === 'SUCCESS' && $changedFiles === 0): ?>
                <p class="muted" style="margin-top:12px;font-size:12px">
                    This deployment changed no files — every entry was already identical,
                    protected or not permitted.
                </p>
                <?php endif; ?>

                <?php // No backups are kept, so a deployment is final. Say so.
                      if ($changedFiles > 0): ?>
                <p class="muted" style="margin-top:12px;font-size:12px">
                    <i class="fa fa-circle-info me-1"></i>
                    This deployment cannot be undone. No copy of the replaced files was kept —
                    to go back, deploy a package containing the previous versions.
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-body" style="padding:0">
                <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead class="table-dark">
                        <tr><th>File</th><th style="width:120px">Action</th>
                            <th style="width:120px">Status</th><th style="width:150px">Size</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$files): ?>
                        <tr><td colspan="4" class="muted" style="padding:16px">No file records.</td></tr>
                    <?php else: foreach ($files as $f): ?>
                        <tr>
                            <td>
                                <div class="dp-path"><?= h($f['rel_path']) ?></div>
                                <?php if ($f['note']): ?>
                                    <div class="muted" style="font-size:11px"><?= h($f['note']) ?></div>
                                <?php endif; ?>
                                <?php if ($f['sha256_after']): ?>
                                    <div class="dp-hash">sha256 <?= h(substr($f['sha256_after'], 0, 16)) ?>…</div>
                                <?php endif; ?>
                            </td>
                            <td><span class="dp-badge dp-<?= h($f['action']) ?>">
                                <?= $f['action'] === 'SQL' ? 'DB CHANGE' : h($f['action']) ?></span>
                                <?php if (str_starts_with((string) $f['status'], 'SQL_')): ?>
                                    <span class="dp-badge dp-SQL" style="margin-left:4px">DB</span>
                                <?php endif; ?></td>
                            <td style="font-size:12px"><?= h(str_replace('_', ' ', $f['status'])) ?></td>
                            <td style="font-size:12px">
                                <?php if ($f['size_before'] !== null && $f['action'] === 'UPDATE'): ?>
                                    <?= number_format($f['size_before']) ?> &rarr; <?= number_format((int) $f['size_after']) ?> B
                                <?php elseif ($f['size_after'] !== null): ?>
                                    <?= number_format((int) $f['size_after']) ?> B
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
