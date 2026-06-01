<?php
/**
 * OT Settings — Overtime & attendance timing configuration
 *
 * Mirrors Laravel OtSettingController + settings/ot.blade.php.
 *   GET  → show form (office start, OT trigger, OT baseline)
 *   POST → validate + save to app_settings
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('settings', 'view');

$errors = [];

// ─── POST — Save ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash('error', 'Invalid or expired request. Please try again.');
        redirect(BASE_URL . '/modules/settings/ot.php');
    }

    $officeStart = trim($_POST['office_start_time'] ?? '');
    $otTrigger   = trim($_POST['ot_trigger_time']   ?? '');
    $otBaseline  = trim($_POST['ot_baseline_time']  ?? '');

    // Validate HH:MM format
    $timeRe = '/^([01]\d|2[0-3]):[0-5]\d$/';
    if (!preg_match($timeRe, $officeStart)) $errors[] = 'Office Start Time must be a valid time (HH:MM).';
    if (!preg_match($timeRe, $otTrigger))   $errors[] = 'OT Trigger Time must be a valid time (HH:MM).';
    if (!preg_match($timeRe, $otBaseline))  $errors[] = 'OT Baseline Time must be a valid time (HH:MM).';

    // Trigger must be strictly after baseline
    if (!$errors && time_to_mins($otTrigger) <= time_to_mins($otBaseline)) {
        $errors[] = 'OT Trigger time must be later than Baseline time.';
    }

    if (!$errors) {
        setting_set('office_start_time', $officeStart);
        setting_set('ot_trigger_time',   $otTrigger);
        setting_set('ot_baseline_time',  $otBaseline);
        flash('success', 'OT & attendance settings saved successfully.');
        redirect(BASE_URL . '/modules/settings/ot.php');
    }
}

// ─── GET — Load current values ───────────────────────────────────────────────
$officeStartTime = $_POST['office_start_time'] ?? setting_office_start();
$triggerTime     = $_POST['ot_trigger_time']   ?? setting_ot_trigger();
$baselineTime    = $_POST['ot_baseline_time']  ?? setting_ot_baseline();

$fmt12 = fn(string $t) => date('h:i A', strtotime($t));

$page_title = 'OT Settings';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card page-card" style="max-width:680px">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 fw-semibold"><i class="fas fa-clock me-2 text-primary"></i>Overtime (OT) Settings</h5>
    </div>
    <div class="card-body">

        <?= render_flash() ?>

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <div class="alert alert-info small mb-4 py-2">
            <i class="fa fa-info-circle me-1"></i>
            <strong>How late &amp; OT are calculated:</strong><br>
            • <strong>Late:</strong> check-in after Office Start + Daily Grace → minutes counted from Office Start. Monthly limit triggers 2× deduction. See <a href="<?= BASE_URL ?>/modules/settings/grace.php">Grace Settings</a>.<br>
            • <strong>Half Day:</strong> check-in &gt; Office Start + 2 hours → auto half-day status.<br>
            • <strong>OT:</strong> checkout ≥ Trigger Time → <code>OT hrs = (checkout − baseline) / 60</code>. OT pay = Basic ÷ days ÷ 8 × 2 × hrs.
        </div>

        <form action="<?= BASE_URL ?>/modules/settings/ot.php" method="POST">
            <?= csrf_field() ?>

            <!-- Attendance Timing -->
            <h6 class="fw-semibold text-muted mb-3 mt-1"><i class="fa fa-clock me-1"></i>Attendance Timing</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label">
                        Office Start Time <span class="text-danger">*</span>
                        <i class="fa fa-circle-question ms-1 text-muted" title="Check-in after this time is considered late"></i>
                    </label>
                    <input type="time" name="office_start_time" class="form-control"
                           value="<?= h($officeStartTime) ?>" required>
                    <div class="form-text">
                        Current: <strong><?= h($officeStartTime) ?></strong> (<?= h($fmt12($officeStartTime)) ?>)<br>
                        Late if check-in &gt; this. Half Day if &gt; this + 2 hrs.
                    </div>
                </div>
            </div>

            <!-- OT Timing -->
            <h6 class="fw-semibold text-muted mb-3"><i class="fa fa-moon me-1"></i>Overtime (OT) Timing</h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">
                        OT Trigger Time <span class="text-danger">*</span>
                        <i class="fa fa-circle-question ms-1 text-muted" title="Minimum checkout time for OT eligibility"></i>
                    </label>
                    <input type="time" name="ot_trigger_time" class="form-control"
                           value="<?= h($triggerTime) ?>" required>
                    <div class="form-text">
                        Current: <strong><?= h($triggerTime) ?></strong> (<?= h($fmt12($triggerTime)) ?>)<br>
                        Must clock out at or after this time to qualify for OT.
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">
                        OT Baseline Time <span class="text-danger">*</span>
                        <i class="fa fa-circle-question ms-1 text-muted" title="OT hours counted from this time onwards"></i>
                    </label>
                    <input type="time" name="ot_baseline_time" class="form-control"
                           value="<?= h($baselineTime) ?>" required>
                    <div class="form-text">
                        Current: <strong><?= h($baselineTime) ?></strong> (<?= h($fmt12($baselineTime)) ?>)<br>
                        OT hours accumulated from this time onwards.
                    </div>
                </div>
            </div>

            <!-- Live preview -->
            <div class="bg-light rounded p-3 mt-4 small" id="otPreview">
                <strong><i class="fa fa-calculator me-1"></i>Live Preview</strong><br>
                <span id="previewText"></span>
            </div>

            <hr class="my-4">
            <button type="submit" class="btn btn-primary">
                <i class="fa fa-save me-1"></i>Save OT Settings
            </button>
            <a href="<?= BASE_URL ?>/index.php" class="btn btn-outline-secondary ms-2">Cancel</a>
        </form>

        <hr class="my-4">
        <div class="small text-muted">
            <strong>Defaults:</strong> Office Start = 09:00 · OT Trigger = 20:30 · OT Baseline = 18:15<br>
            <strong>Changes take effect immediately</strong> — new attendance records will use the updated times.
            Existing saved records are <em>not</em> retroactively updated.
        </div>
    </div>
</div>

<?php $page_scripts = <<<'PAGEJS'
<script>
$(function () {
    function updatePreview() {
        var trigger  = $('input[name="ot_trigger_time"]').val();
        var baseline = $('input[name="ot_baseline_time"]').val();
        if (!trigger || !baseline) { $('#previewText').text('Enter both times to see preview.'); return; }

        var tParts = trigger.split(':'),  tMins = parseInt(tParts[0]) * 60 + parseInt(tParts[1]);
        var bParts = baseline.split(':'), bMins = parseInt(bParts[0]) * 60 + parseInt(bParts[1]);

        if (tMins <= bMins) {
            $('#previewText').html('<span class="text-danger">&#9888; Trigger time must be later than Baseline time.</span>');
            return;
        }

        var exampleOut  = tMins;          // checkout exactly at trigger time
        var exampleOT   = (exampleOut - bMins) / 60;
        var exampleOut2 = tMins + 45;     // checkout 45 min after trigger
        var exampleOT2  = (exampleOut2 - bMins) / 60;

        $('#previewText').html(
            'If employee checks out at <strong>' + trigger + '</strong> &rarr; <strong>' + exampleOT.toFixed(2) + ' OT hrs</strong> | ' +
            'At <strong>' + Math.floor(exampleOut2/60) + ':' + String(exampleOut2%60).padStart(2,'0') + '</strong> &rarr; <strong>' + exampleOT2.toFixed(2) + ' OT hrs</strong>'
        );
    }

    $('input[name="ot_trigger_time"], input[name="ot_baseline_time"]').on('change input', updatePreview);
    updatePreview();
});
</script>
PAGEJS;
?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
