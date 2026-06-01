<?php
/**
 * Grace & Late Permission Settings
 *
 * Mirrors Laravel GraceSettingController + settings/grace.blade.php.
 *   GET  → show form (daily grace, monthly grace)
 *   POST → validate + save, then recalculate attendance statuses
 *
 * Recalc rules (priority order) — adapted to HRMS attendance schema
 * (status ENUM 'On Time'/'Late'/'Half Day', in_time, out_time):
 *   1. in_time ≥ 11:00 AM                          → Half Day
 *   2. in_time < 11:00 AND 0 < worked < 4 hours    → Half Day
 *   3. in_time < 11:00 AND in_time > start+grace   → Late
 *   4. in_time < 11:00 AND in_time ≤ start+grace   → On Time
 * Never touches Absent, OD, Comp Off or Holiday.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('settings', 'view');

$db     = db();
$errors = [];

/**
 * Recalculate present/late/half-day statuses for ALL attendance records based
 * on the current daily-grace setting.  Returns count of affected rows.
 */
function recalc_attendance_statuses(PDO $db): int {
    $threshold      = setting_office_start_mins() + setting_daily_grace_mins();
    $halfDayCheckin = 11 * 60; // 11:00 AM
    $affected       = 0;

    // Rule 1: check-in ≥ 11:00 AM → Half Day
    $r1 = $db->prepare(
        "UPDATE attendance
            SET status = 'Half Day'
          WHERE status IN ('On Time','Late','Half Day')
            AND in_time IS NOT NULL
            AND (HOUR(in_time) * 60 + MINUTE(in_time)) >= ?"
    );
    $r1->execute([$halfDayCheckin]);
    $affected += $r1->rowCount();

    // Rule 2: check-in < 11:00 AND 0 < worked < 4 hrs → Half Day
    $r2 = $db->prepare(
        "UPDATE attendance
            SET status = 'Half Day'
          WHERE status IN ('On Time','Late','Half Day')
            AND in_time  IS NOT NULL
            AND out_time IS NOT NULL
            AND (HOUR(in_time) * 60 + MINUTE(in_time)) < ?
            AND TIMESTAMPDIFF(MINUTE, in_time, out_time) > 0
            AND TIMESTAMPDIFF(MINUTE, in_time, out_time) < 240"
    );
    $r2->execute([$halfDayCheckin]);
    $affected += $r2->rowCount();

    // Rule 3: check-in < 11:00 AND in_time > threshold → Late
    $r3 = $db->prepare(
        "UPDATE attendance
            SET status = 'Late'
          WHERE status IN ('On Time','Late')
            AND in_time IS NOT NULL
            AND (HOUR(in_time) * 60 + MINUTE(in_time)) < ?
            AND (HOUR(in_time) * 60 + MINUTE(in_time)) > ?"
    );
    $r3->execute([$halfDayCheckin, $threshold]);
    $affected += $r3->rowCount();

    // Rule 4: check-in < 11:00 AND in_time ≤ threshold → On Time
    $r4 = $db->prepare(
        "UPDATE attendance
            SET status = 'On Time'
          WHERE status IN ('On Time','Late')
            AND in_time IS NOT NULL
            AND (HOUR(in_time) * 60 + MINUTE(in_time)) < ?
            AND (HOUR(in_time) * 60 + MINUTE(in_time)) <= ?"
    );
    $r4->execute([$halfDayCheckin, $threshold]);
    $affected += $r4->rowCount();

    return $affected;
}

// ─── POST — Save + recalc ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash('error', 'Invalid or expired request. Please try again.');
        redirect(BASE_URL . '/modules/settings/grace.php');
    }

    $daily   = $_POST['daily_grace_minutes']   ?? '';
    $monthly = $_POST['monthly_grace_minutes'] ?? '';

    if (!ctype_digit((string)$daily) || (int)$daily < 0 || (int)$daily > 180) {
        $errors[] = 'Daily Grace must be a whole number between 0 and 180 minutes.';
    }
    if (!ctype_digit((string)$monthly) || (int)$monthly < 0 || (int)$monthly > 480) {
        $errors[] = 'Monthly Permission must be a whole number between 0 and 480 minutes.';
    }

    if (!$errors) {
        setting_set('daily_grace_minutes',   (int)$daily);
        setting_set('monthly_grace_minutes', (int)$monthly);
        $updated = recalc_attendance_statuses($db);
        flash('success', "Grace settings saved. {$updated} attendance record(s) recalculated.");
        redirect(BASE_URL . '/modules/settings/grace.php');
    }
}

// ─── GET — Load current values ───────────────────────────────────────────────
$dailyGraceMinutes   = $_POST['daily_grace_minutes']   ?? setting_daily_grace_mins();
$monthlyGraceMinutes = $_POST['monthly_grace_minutes'] ?? setting_monthly_grace_mins();
$officeStartTime     = setting_office_start();

$startMins   = time_to_mins($officeStartTime);
$fmt12       = fn(int $mins) => date('h:i A', mktime(0, 0, 0, 0, 1, 2000) + $mins * 60);
$lateThresh  = $fmt12($startMins + (int)$dailyGraceMinutes);

$page_title = 'Grace Settings';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card page-card" style="max-width:680px">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 fw-semibold">
            <i class="fa fa-hourglass-half me-2 text-warning"></i>Grace &amp; Late Permission Settings
        </h5>
    </div>
    <div class="card-body">

        <?= render_flash() ?>

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <!-- How it works info box -->
        <div class="alert alert-info small mb-4 py-2">
            <i class="fa fa-info-circle me-1"></i>
            <strong>How grace time works:</strong><br>
            • <strong>Daily Grace:</strong> Employee has this many minutes after office start before being marked late.
              E.g. Office = <strong><?= h($fmt12($startMins)) ?></strong>,
              Grace = <strong><?= (int)$dailyGraceMinutes ?> min</strong> →
              late threshold is <strong id="lateThresholdInfo"><?= h($lateThresh) ?></strong>.<br>
            • <strong>Late minutes</strong> are always counted from office start (not from the grace cutoff).<br>
              E.g. Check-in at <?= h($fmt12($startMins + (int)$dailyGraceMinutes + 1)) ?>
              → <strong><?= (int)$dailyGraceMinutes + 1 ?> min late</strong>.<br>
            • <strong>Monthly Permission:</strong> Total late minutes ≤ this limit → no salary deduction.
              Exceeding triggers a <strong>2× penalty</strong> on the full late amount.
        </div>

        <form action="<?= BASE_URL ?>/modules/settings/grace.php" method="POST">
            <?= csrf_field() ?>

            <!-- Daily Grace -->
            <h6 class="fw-semibold text-muted mb-3 mt-1">
                <i class="fa fa-clock me-1"></i>Daily Late Grace
            </h6>
            <div class="row g-3 mb-4">
                <div class="col-md-5">
                    <label class="form-label">
                        Daily Grace (minutes) <span class="text-danger">*</span>
                        <i class="fa fa-circle-question ms-1 text-muted"
                           title="Minutes after office start that are still not counted as late"></i>
                    </label>
                    <div class="input-group">
                        <input type="number" name="daily_grace_minutes" class="form-control"
                               value="<?= h((string)$dailyGraceMinutes) ?>" min="0" max="180" required>
                        <span class="input-group-text text-muted">min</span>
                    </div>
                    <div class="form-text">
                        Office starts at <strong><?= h($fmt12($startMins)) ?></strong>.
                        Late threshold: <strong id="lateThreshold"><?= h($lateThresh) ?></strong>
                        <span class="text-muted">(updates live)</span>
                    </div>
                </div>
            </div>

            <!-- Monthly Grace -->
            <h6 class="fw-semibold text-muted mb-3">
                <i class="fa fa-calendar-check me-1"></i>Monthly Late Permission
            </h6>
            <div class="row g-3 mb-4">
                <div class="col-md-5">
                    <label class="form-label">
                        Monthly Permission (minutes) <span class="text-danger">*</span>
                        <i class="fa fa-circle-question ms-1 text-muted"
                           title="Total late minutes allowed per month before penalty kicks in"></i>
                    </label>
                    <div class="input-group">
                        <input type="number" name="monthly_grace_minutes" class="form-control"
                               value="<?= h((string)$monthlyGraceMinutes) ?>" min="0" max="480" required>
                        <span class="input-group-text text-muted">min</span>
                    </div>
                    <div class="form-text" id="monthlyHint">
                        Currently <strong><?= (int)$monthlyGraceMinutes ?> min</strong>
                        = <strong><?= intdiv((int)$monthlyGraceMinutes, 60) ?>h <?= (int)$monthlyGraceMinutes % 60 ?>m</strong>.
                        Exceeding triggers 2× deduction on total late.
                    </div>
                </div>
            </div>

            <!-- Live preview -->
            <div class="bg-light rounded p-3 mt-2 mb-4 small" id="gracePreview">
                <strong><i class="fa fa-calculator me-1"></i>Live Preview</strong><br>
                <span id="previewText"></span>
            </div>

            <hr class="my-4">
            <button type="submit" class="btn btn-primary">
                <i class="fa fa-save me-1"></i>Save Grace Settings
            </button>
            <a href="<?= BASE_URL ?>/modules/settings/ot.php" class="btn btn-outline-secondary ms-2">OT Settings</a>
        </form>

        <hr class="my-4">
        <div class="small text-muted">
            <strong>Defaults:</strong> Daily Grace = 15 min · Monthly Permission = 90 min (1h 30m)<br>
            <strong>Saving recalculates</strong> present / late / half-day statuses for existing attendance records
            using the new grace value.
        </div>
    </div>
</div>

<?php
$startMinsJs = $startMins;
$page_scripts = <<<PAGEJS
<script>
\$(function () {
    var officeStartMins = {$startMinsJs};

    function minsToTime(m) {
        var h = Math.floor(m / 60), mn = m % 60;
        var suffix = h >= 12 ? 'PM' : 'AM';
        var h12 = h % 12 || 12;
        return h12 + ':' + String(mn).padStart(2, '0') + ' ' + suffix;
    }
    function fmtMins(m) {
        return (Math.floor(m/60) > 0 ? Math.floor(m/60) + 'h ' : '') + (m % 60) + 'm';
    }

    function updatePreview() {
        var daily   = parseInt(\$('input[name="daily_grace_minutes"]').val())   || 0;
        var monthly = parseInt(\$('input[name="monthly_grace_minutes"]').val()) || 0;

        var thresholdMins = officeStartMins + daily;
        \$('#lateThreshold').text(minsToTime(thresholdMins));
        \$('#lateThresholdInfo').text(minsToTime(thresholdMins));

        var exampleLate = daily + 1;
        var exampleIn   = minsToTime(thresholdMins + 1);

        \$('#monthlyHint').html(monthly + ' min = ' + fmtMins(monthly) + '. Exceeding triggers 2× deduction.');

        var lines = [];
        lines.push('Office start: <strong>' + minsToTime(officeStartMins) + '</strong> | Daily grace: <strong>' + daily + ' min</strong> | Late threshold: <strong>' + minsToTime(thresholdMins) + '</strong>');
        lines.push('Example: Check-in at <strong>' + exampleIn + '</strong> → <strong>' + exampleLate + ' min late</strong> (from office start)');
        if (monthly > 0) {
            lines.push('Monthly limit: <strong>' + fmtMins(monthly) + '</strong>. If total late = ' + fmtMins(monthly + 10) + ' → <strong>' + fmtMins((monthly + 10) * 2) + ' deducted (2×)</strong>');
        }
        \$('#previewText').html(lines.join('<br>'));
    }

    \$('input[name="daily_grace_minutes"], input[name="monthly_grace_minutes"]').on('input', updatePreview);
    updatePreview();
});
</script>
PAGEJS;
?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
