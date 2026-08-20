<?php
/**
 * Step 3 — apply the package that analyze.php prepared.
 *
 * Sequence: lock → (maintenance) → re-analyze → write+verify per file →
 * optional SQL → record → unlock.
 *
 * Nothing is backed up. A failure removes the files this run ADDED, but files
 * it overwrote stay at the new version — there is no copy of the old one.
 *
 * No output is produced before the work is done, so every failure path can still
 * redirect cleanly.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
// Load the engine directly — see the note in details.php. Critical here: this
// page is what WRITES the package, so it must keep working even while applying
// a package that replaces bootstrap.php mid-deployment.
require_once __DIR__ . '/../../includes/deployer.php';
require_login();
require_permission('deployment', 'deploy');

$self = BASE_URL . '/modules/deployment/index.php';

if (!DEPLOYMENT_ENABLED) { flash('error', 'The deployment module is disabled.'); redirect($self); }
if (!is_super_admin())   { flash('error', 'Only a Super Admin can deploy packages.'); redirect($self); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect($self);
verify_csrf($_POST['csrf_token'] ?? '');

// ─── Recover the prepared package from the session ────────────────────────────
$pending = $_SESSION['deploy_pending'] ?? null;
if (!is_array($pending) || empty($pending['file'])) {
    flash('error', 'No package is awaiting deployment. Please upload it again.');
    redirect($self);
}
if (!hash_equals((string) ($pending['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
    flash('error', 'This confirmation is no longer valid. Please re-upload the package.');
    redirect($self);
}
if ((time() - (int) ($pending['at'] ?? 0)) > 1800) {          // 30-minute window
    unset($_SESSION['deploy_pending']);
    flash('error', 'The preview expired. Please upload the package again.');
    redirect($self);
}

$zipPath   = (string) $pending['file'];
$label     = (string) $pending['label'];
$isLive    = deploy_is_live();

// The stored package must still be inside our own storage area.
$pkgDirReal = realpath(deploy_packages_dir());
$zipReal    = realpath($zipPath);
if ($pkgDirReal === false || $zipReal === false ||
    strncmp(str_replace('\\', '/', $zipReal), str_replace('\\', '/', $pkgDirReal) . '/', strlen($pkgDirReal) + 1) !== 0) {
    unset($_SESSION['deploy_pending']);
    flash('error', 'The prepared package could not be located.');
    redirect($self);
}

// Live deployments need the typed confirmation.
if ($isLive && trim((string) ($_POST['confirm'] ?? '')) !== 'DEPLOY') {
    flash('error', 'Deployment cancelled — you must type DEPLOY to confirm on a live server.');
    redirect($self);
}

$runSql      = !empty($_POST['run_sql']);
$useMaint    = !empty($_POST['maintenance']) && $isLive;

// ─── Lock ─────────────────────────────────────────────────────────────────────
$lock = deploy_lock();
if ($lock === null) {
    flash('error', 'A deployment is currently in progress. Please wait until it is completed.');
    redirect($self);
}

$db           = db();
$user         = current_user();
$deploymentId = deploy_new_id();
$maintOn      = false;

// Release the lock and clear maintenance mode however this script ends —
// including via redirect(), which calls exit() and therefore skips `finally`.
deploy_register_cleanup($lock, $maintOn);

try {
    deploy_audit('DEPLOYMENT_STARTED', $deploymentId, 'Deploying ' . $label,
                 ['environment' => deploy_env()]);

    // Re-analyze rather than trusting the session: the tree may have changed
    // since the preview, and this is what decides ADD vs UPDATE.
    $analysis = deploy_analyze($zipPath);
    if (!$analysis['ok']) {
        throw new RuntimeException($analysis['error'] ?: 'The package could not be analyzed.');
    }
    $s = $analysis['summary'];

    if ($useMaint) { deploy_maintenance_enable($user['name'] ?? 'admin'); $maintOn = true; }

    // History row up front, so a fatal error still leaves a trace.
    $db->prepare(
        'INSERT INTO deployments
           (deployment_id, user_id, user_name, package_name, package_size, package_sha256,
            environment, total_files, files_added, files_updated, files_skipped, status, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
    )->execute([
        $deploymentId, $user['id'] ?? null, $user['name'] ?? null, $label,
        (int) $pending['size'], $pending['sha256'], deploy_env(),
        (int) $s['total'], (int) $s['add'], (int) $s['update'],
        (int) ($s['skip'] + $s['protected']), 'PENDING',
    ]);

    // ─── Files ────────────────────────────────────────────────────────────────
    $res = deploy_execute($zipPath, $label, $analysis, $deploymentId);

    // Record every entry we considered, whatever the outcome.
    $ins = $db->prepare(
        'INSERT INTO deployment_files
           (deployment_id, rel_path, action, size_before, size_after, sha256_before, sha256_after, status, note)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $done = [];
    foreach ($res['files'] as $f) {
        $done[$f['rel']] = true;
        $ins->execute([$deploymentId, $f['rel'], $f['action'], $f['size_before'], $f['size_after'],
                       $f['sha256_before'], $f['sha256_after'], 'OK', null]);
    }
    foreach ($analysis['entries'] as $e) {
        if (isset($done[$e['rel']])) continue;
        $ins->execute([$deploymentId, (string) ($e['rel'] ?? $e['raw']), $e['action'],
                       $e['size_before'], $e['size'], $e['sha256_before'], $e['sha256'],
                       $res['ok'] ? 'SKIPPED' : 'NOT_APPLIED', $e['reason'] ?: null]);
    }

    if (!$res['ok']) {
        $db->prepare('UPDATE deployments SET status=?, error_message=?, completed_at=NOW() WHERE deployment_id=?')
           ->execute(['FAILED', $res['error'], $deploymentId]);

        deploy_audit('DEPLOYMENT_FAILED', $deploymentId, $res['error'],
                     ['auto_restore' => $res['rolled_back'] ? 'SUCCESS' : 'INCOMPLETE']);

        $_SESSION['deploy_result'] = [
            'ok' => false, 'deployment_id' => $deploymentId,
            'failed_file' => $res['failed_file'] ?? null,
            'rolled_back' => $res['rolled_back'],
            'message' => $res['error'],
        ];
        redirect(BASE_URL . '/modules/deployment/details.php?id=' . urlencode($deploymentId));
    }

    /* ─── Optional SQL ─────────────────────────────────────────────────────────
     * No database backup is taken first. A migration that goes wrong cannot be
     * undone, so the tick-box on the preview is the only gate. */
    $sqlNote   = '';
    $sqlFailed = 0;                       // drives the warning below
    if ($runSql && $analysis['has_sql']) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) === true) {
            $ranOk = 0;
            foreach ($analysis['entries'] as $e) {
                if (empty($e['is_sql']) || !in_array($e['action'], ['ADD', 'UPDATE'], true)) continue;
                $sqlText = $zip->getFromName($e['raw']);
                if ($sqlText === false) { $sqlFailed++; continue; }
                $r = deploy_run_sql($sqlText);
                if ($r['ok']) { $ranOk++; } else { $sqlFailed++; }
                $db->prepare('UPDATE deployment_files SET status=?, note=? WHERE deployment_id=? AND rel_path=?')
                   ->execute([$r['ok'] ? 'SQL_APPLIED' : 'SQL_FAILED',
                              $r['ok'] ? $r['statements'] . ' statement(s)' : $r['error'],
                              $deploymentId, $e['rel']]);
            }
            $zip->close();
            $sqlNote = ' Database: ' . $ranOk . ' file(s) applied'
                     . ($sqlFailed ? ', ' . $sqlFailed . ' FAILED.' : '.');
        }
    }

    /* The files deployed, so the status stays SUCCESS — but a failed migration
     * must never be reported as a plain green success, or an admin will believe
     * a schema change landed when it did not. A partially-applied migration
     * cannot be undone, so the message has to say that plainly. */
    $errorMessage = $sqlFailed
        ? 'Files deployed successfully, but ' . $sqlFailed . ' database change(s) FAILED. '
        . 'The database may be partly modified, and no backup was taken — inspect it '
        . 'before relying on this deployment.'
        : null;

    $db->prepare('UPDATE deployments SET status=?, error_message=?, completed_at=NOW() WHERE deployment_id=?')
       ->execute(['SUCCESS', $errorMessage, $deploymentId]);

    deploy_audit($sqlFailed ? 'DEPLOYMENT_FAILED' : 'DEPLOYMENT_SUCCESS', $deploymentId,
                 'Deployed ' . $label . $sqlNote,
                 ['added' => $s['add'], 'updated' => $s['update']]);

    unset($_SESSION['deploy_pending']);

    $summary = 'Deployment ' . $deploymentId . ' — '
             . (int) $s['add'] . ' added, ' . (int) $s['update'] . ' updated.' . $sqlNote;

    if ($sqlFailed) {
        flash('warn', $summary . ' Files are in place, but the database changes did NOT apply — '
                    . 'review the deployment details before relying on this update.');
    } else {
        flash('success', $summary);
    }
    redirect(BASE_URL . '/modules/deployment/details.php?id=' . urlencode($deploymentId));

} catch (Throwable $e) {
    // Technical detail goes to the server log; the admin sees a clean message.
    error_log('Deployment ' . $deploymentId . ' error: ' . $e->getMessage());
    try {
        $db->prepare('UPDATE deployments SET status=?, error_message=?, completed_at=NOW() WHERE deployment_id=?')
           ->execute(['FAILED', 'Unexpected error during deployment.', $deploymentId]);
    } catch (Throwable $ignored) { /* history is best-effort at this point */ }

    deploy_audit('DEPLOYMENT_FAILED', $deploymentId, 'Unexpected error during deployment');
    flash('error', 'Deployment ' . $deploymentId . ' failed. No further files were changed; '
                 . 'see the deployment details for what was applied.');
    redirect(BASE_URL . '/modules/deployment/details.php?id=' . urlencode($deploymentId));
}
