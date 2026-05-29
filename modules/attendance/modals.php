<?php
/**
 * Attendance Import Modals
 * Included at the bottom of index.php — NOT a standalone page.
 *
 * Provides:
 *  #dailyImportModal   — import a single day's attendance (CSV/XLSX)
 *  #monthlyImportModal — import a full month's attendance (CSV/XLSX)
 *
 * Both use the HRMS custom modal system (openModal / closeModal from hrms-core.js).
 * Bootstrap is NOT used for these modals so there is no conflict with magdyn-base.css.
 *
 * Expected column headers (case-insensitive, spaces → underscores):
 *
 *   Daily CSV / XLSX:
 *     employee_id | emp_id | code     (required)
 *     in_time     | check_in          (optional — auto-classifies status)
 *     out_time    | check_out         (optional)
 *     status      | attendance        (optional — auto-calculated from in_time)
 *     remarks     | note              (optional)
 *     date                            (optional — overrides the form date field)
 *
 *   Monthly CSV / XLSX — two accepted formats:
 *     Format A (row-per-record — same columns as daily but 'date' is required):
 *       employee_id, date, in_time, out_time, status, remarks
 *
 *     Format B (matrix — employee per row, day-numbers as column headers):
 *       employee_id, 1, 2, 3, …, 31
 *       (status codes: P/Present=OnTime  L/Late  A/Absent  HD/HalfDay  OD  CO/CompOff  H/Holiday)
 *
 * Status auto-classification (when status column is blank):
 *   - No in_time                         → Absent
 *   - in_time ≤ WORK_START + GRACE       → On Time
 *   - in_time > WORK_START + GRACE       → Late
 */
if (!defined('BASE_URL')) die(); // safety guard — must be included, not accessed directly
?>

<!-- ═══════════════════════════════════════════════════════════════════════
     DAILY IMPORT MODAL
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="modal" id="dailyImportModal">
    <div class="modal-content" style="max-width:560px;width:94%">

        <!-- Header -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 style="margin:0;font-size:16px;font-weight:700">
                <i class="fa fa-file-arrow-up" style="color:var(--primary);margin-right:8px"></i>
                Daily Attendance Import
            </h3>
            <button type="button" onclick="closeModal('dailyImportModal')"
                    style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted);line-height:1;padding:0 4px"
                    aria-label="Close">&times;</button>
        </div>

        <!-- Info banner -->
        <div style="background:var(--bg-secondary);border:1px solid var(--border);border-radius:var(--radius);
                    padding:10px 14px;font-size:12.5px;margin-bottom:14px;line-height:1.7">
            <strong><i class="fa fa-circle-info" style="color:var(--primary)"></i> How it works</strong><br>
            Upload a <strong>CSV</strong> or <strong>XLSX</strong> for <em>one specific day</em>.
            Existing records for that date are <strong>updated</strong>; new ones are <strong>inserted</strong>.<br>
            <span style="color:var(--text-muted)">
                Status auto-calculated from In Time if blank
                (grace: <?= ATTENDANCE_GRACE_MINUTES ?> min after <?= WORK_START_TIME ?>).
            </span>
        </div>

        <!-- Column guide -->
        <div style="margin-bottom:14px">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
                        color:var(--text-muted);margin-bottom:6px">Required column</div>
            <span class="pill pill-danger">employee_id</span>
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
                        color:var(--text-muted);margin:8px 0 6px">Optional columns</div>
            <div style="display:flex;flex-wrap:wrap;gap:4px">
                <?php foreach (['in_time','out_time','status','remarks','date'] as $col): ?>
                <span class="pill pill-neutral"><?= $col ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Template download hint -->
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:14px">
            <i class="fa fa-lightbulb"></i>
            Sample row: <code>EMP0001,09:05,18:00,,</code>
            &nbsp;|&nbsp;
            <code>EMP0002,,,Absent,Late arrival</code>
        </div>

        <!-- Form -->
        <form method="POST" enctype="multipart/form-data"
              action="<?= BASE_URL ?>/modules/attendance/process_daily.php"
              id="dailyImportForm" onsubmit="return validateImportFile(this.querySelector('[name=import_file]'))">
            <?= csrf_field() ?>

            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px">
                <div class="field" style="flex:1;min-width:160px;margin:0">
                    <label>Attendance Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="att_date" id="daily_att_date"
                           value="<?= date('Y-m-d') ?>" required
                           max="<?= date('Y-m-d') ?>">
                </div>
                <div class="field" style="flex:2;min-width:200px;margin:0">
                    <label>Import File <span style="color:var(--danger)">*</span></label>
                    <input type="file" name="import_file" accept=".csv,.xlsx" required
                           style="display:block;width:100%;padding:7px 10px;border:1px solid var(--border-strong);
                                  border-radius:var(--radius);font-size:13px;background:white;box-sizing:border-box"
                           onchange="validateImportFile(this)">
                    <div style="font-size:11px;color:var(--text-muted);margin-top:3px">CSV or XLSX · max <?= UPLOAD_MAX_MB ?>MB</div>
                </div>
            </div>

            <!-- Options -->
            <div style="background:var(--bg-secondary);border-radius:var(--radius);padding:10px 12px;
                        margin-bottom:16px;font-size:13px">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:6px">
                    <input type="checkbox" name="auto_classify" value="1" checked>
                    Auto-classify status from In Time
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" name="skip_holidays" value="1">
                    Skip rows where date falls on a holiday
                </label>
            </div>

            <!-- Footer buttons -->
            <div style="display:flex;gap:8px;justify-content:flex-end;padding-top:14px;border-top:1px solid var(--border)">
                <button type="button" class="btn" onclick="closeModal('dailyImportModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-upload"></i> Import Daily Attendance
                </button>
            </div>
        </form>
    </div>
</div><!-- /#dailyImportModal -->


<!-- ═══════════════════════════════════════════════════════════════════════
     MONTHLY IMPORT MODAL
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="modal" id="monthlyImportModal">
    <div class="modal-content" style="max-width:620px;width:94%">

        <!-- Header -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 style="margin:0;font-size:16px;font-weight:700">
                <i class="fa fa-calendar-arrow-up" style="color:var(--primary);margin-right:8px"></i>
                Monthly Attendance Import
            </h3>
            <button type="button" onclick="closeModal('monthlyImportModal')"
                    style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted);line-height:1;padding:0 4px"
                    aria-label="Close">&times;</button>
        </div>

        <!-- Info banner -->
        <div style="background:var(--bg-secondary);border:1px solid var(--border);border-radius:var(--radius);
                    padding:10px 14px;font-size:12.5px;margin-bottom:14px;line-height:1.7">
            <strong><i class="fa fa-circle-info" style="color:var(--primary)"></i> Two accepted formats</strong><br>
            <strong>Format A — Row per record:</strong>
            <code style="font-size:11px">employee_id, date, in_time, out_time, status, remarks</code><br>
            <strong>Format B — Matrix (auto-detected):</strong>
            <code style="font-size:11px">employee_id, 1, 2, 3 … 31</code>
            with status codes <code>P L A HD OD CO H</code>
        </div>

        <!-- Format tabs -->
        <div style="display:flex;gap:0;margin-bottom:14px;border:1px solid var(--border);
                    border-radius:var(--radius);overflow:hidden;font-size:13px">
            <div style="flex:1;padding:8px 12px;background:var(--primary);color:white;text-align:center">
                <strong>Format A</strong> — Row-per-record
            </div>
            <div style="flex:1;padding:8px 12px;text-align:center;color:var(--text-muted)">
                <strong>Format B</strong> — Matrix (date columns)
            </div>
        </div>

        <!-- Status code legend -->
        <div style="margin-bottom:14px">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
                        color:var(--text-muted);margin-bottom:6px">Status codes for Format B</div>
            <div style="display:flex;flex-wrap:wrap;gap:5px;font-size:12px">
                <?php
                $codes = [
                    'P / Present' => 'On Time',
                    'L / Late'    => 'Late',
                    'A / Absent'  => 'Absent',
                    'HD'          => 'Half Day',
                    'OD'          => 'On Duty',
                    'CO'          => 'Comp Off',
                    'H / Holiday' => 'Holiday',
                ];
                foreach ($codes as $code => $label): ?>
                <span style="background:var(--bg-secondary);border:1px solid var(--border);
                             border-radius:var(--radius);padding:2px 7px">
                    <code><?= $code ?></code> = <?= $label ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Column guide for Format A -->
        <div style="margin-bottom:14px">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
                        color:var(--text-muted);margin-bottom:6px">Format A required columns</div>
            <span class="pill pill-danger">employee_id</span>
            <span class="pill pill-danger">date</span>
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
                        color:var(--text-muted);margin:6px 0 4px">Optional</div>
            <div style="display:flex;flex-wrap:wrap;gap:4px">
                <?php foreach (['in_time','out_time','status','remarks'] as $col): ?>
                <span class="pill pill-neutral"><?= $col ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Form -->
        <form method="POST" enctype="multipart/form-data"
              action="<?= BASE_URL ?>/modules/attendance/process_monthly.php"
              id="monthlyImportForm" onsubmit="return validateImportFile(this.querySelector('[name=import_file]'))">
            <?= csrf_field() ?>

            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px">
                <div class="field" style="flex:1;min-width:160px;margin:0">
                    <label>Month <span style="color:var(--danger)">*</span></label>
                    <input type="month" name="att_month" id="monthly_att_month"
                           value="<?= $month ?? date('Y-m') ?>" required
                           max="<?= date('Y-m') ?>">
                    <div style="font-size:11px;color:var(--text-muted);margin-top:3px">
                        Used for matrix format &amp; date validation
                    </div>
                </div>
                <div class="field" style="flex:2;min-width:200px;margin:0">
                    <label>Import File <span style="color:var(--danger)">*</span></label>
                    <input type="file" name="import_file" accept=".csv,.xlsx" required
                           style="display:block;width:100%;padding:7px 10px;border:1px solid var(--border-strong);
                                  border-radius:var(--radius);font-size:13px;background:white;box-sizing:border-box"
                           onchange="validateImportFile(this)">
                    <div style="font-size:11px;color:var(--text-muted);margin-top:3px">CSV or XLSX · max <?= UPLOAD_MAX_MB ?>MB</div>
                </div>
            </div>

            <!-- Options -->
            <div style="background:var(--bg-secondary);border-radius:var(--radius);padding:10px 12px;
                        margin-bottom:16px;font-size:13px">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:6px">
                    <input type="checkbox" name="auto_classify" value="1" checked>
                    Auto-classify Late / On Time from In Time (Format A)
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:6px">
                    <input type="checkbox" name="skip_existing" value="1">
                    Skip rows where a record already exists (no overwrite)
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" name="skip_weekends" value="1">
                    Skip Saturdays &amp; Sundays automatically
                </label>
            </div>

            <!-- Footer buttons -->
            <div style="display:flex;gap:8px;justify-content:flex-end;padding-top:14px;border-top:1px solid var(--border)">
                <button type="button" class="btn" onclick="closeModal('monthlyImportModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-upload"></i> Import Monthly Attendance
                </button>
            </div>
        </form>
    </div>
</div><!-- /#monthlyImportModal -->
