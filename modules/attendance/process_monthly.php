<?php
/**
 * Monthly Attendance Import Processor
 * ─────────────────────────────────────
 * Accepts a CSV or XLSX file containing attendance data for an entire month.
 * Performs an UPSERT (INSERT … ON DUPLICATE KEY UPDATE) into the `attendance`
 * table, keyed on (employee_id, att_date).
 *
 * AUTO-DETECTED FORMATS:
 *
 *   Format A — Row-per-record  (detected when headers contain 'date' or 'att_date')
 *     Required: employee_id, date
 *     Optional: in_time, out_time, status, remarks
 *     Example row: EMP0001,2025-05-15,09:02,18:10,,
 *
 *   Format B — Matrix / pivot  (detected when headers are numeric day numbers 1–31)
 *     Required: employee_id, then columns named 1, 2, 3 … up to 31
 *     Status codes: P/Present=OnTime  L=Late  A=Absent  HD=Half Day
 *                   OD  CO/CompOff=Comp Off  H/Holiday=Holiday
 *     Example row: EMP0001,P,P,L,A,P,P,...
 *     Missing/blank cells in Format B → treated as Absent
 *
 * POST fields from modals.php (#monthlyImportModal):
 *   att_month      — YYYY-MM (month being imported)
 *   auto_classify  — "1" to auto-compute Late/OnTime from in_time (Format A only)
 *   skip_existing  — "1" to skip rows where a record already exists (no overwrite)
 *   skip_weekends  — "1" to skip Saturdays and Sundays
 *   csrf_token     — CSRF protection
 *   import_file    — uploaded CSV or XLSX
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('attendance', 'mark');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/modules/attendance/index.php');
}

/* ── POST fields ─────────────────────────────────────────────────────────── */
$attMonth     = preg_replace('/[^0-9\-]/', '', $_POST['att_month'] ?? date('Y-m'));
$autoClassify = !empty($_POST['auto_classify']);
$skipExisting = !empty($_POST['skip_existing']);
$skipWeekends = !empty($_POST['skip_weekends']);

// Validate month (must be YYYY-MM)
if (!preg_match('/^\d{4}-\d{2}$/', $attMonth)) {
    flash('error', 'Invalid month selected.');
    redirect(BASE_URL . '/modules/attendance/index.php');
}

// Future month check
[$mYear, $mMon] = explode('-', $attMonth);
$todayYm = date('Y-m');
if ($attMonth > $todayYm) {
    flash('error', 'Cannot import attendance for a future month.');
    redirect(BASE_URL . '/modules/attendance/index.php');
}
$monthStart   = $attMonth . '-01';
$daysInMonth  = (int)date('t', strtotime($monthStart));
$monthEnd     = $attMonth . '-' . str_pad($daysInMonth, 2, '0', STR_PAD_LEFT);

/* ── File validation ──────────────────────────────────────────────────────── */
$file = $_FILES['import_file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File upload was incomplete.',
        UPLOAD_ERR_NO_FILE    => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing.',
        UPLOAD_ERR_CANT_WRITE => 'Server cannot write temp file.',
    ];
    $msg = $errMap[$file['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'Upload error.';
    flash('error', $msg);
    redirect(BASE_URL . '/modules/attendance/index.php');
}
if ($file['size'] > UPLOAD_MAX_MB * 1024 * 1024) {
    flash('error', 'File is too large. Maximum allowed size is ' . UPLOAD_MAX_MB . ' MB.');
    redirect(BASE_URL . '/modules/attendance/index.php');
}
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'xlsx'], true)) {
    flash('error', 'Only .csv and .xlsx files are accepted.');
    redirect(BASE_URL . '/modules/attendance/index.php');
}

/* ── Parse file ──────────────────────────────────────────────────────────── */
$rows = ($ext === 'csv')
    ? _attm_parse_csv($file['tmp_name'])
    : _attm_parse_xlsx($file['tmp_name']);

// Raw grid (no header combine) — needed to recognise the blocked biometric
// punch report (Format C), whose employee codes live inside the sheet body.
$grid = ($ext === 'csv')
    ? _attm_parse_csv_grid($file['tmp_name'])
    : _attm_parse_xlsx_grid($file['tmp_name']);

if (empty($rows) && empty($grid)) {
    flash('error', 'The file is empty or could not be parsed. Check the format and try again.');
    redirect(BASE_URL . '/modules/attendance/index.php');
}

/* ── Format C — biometric punch report (per-employee blocks) ──────────────── */
$isFormatC = _attm_detect_punch_report($grid);

/* ── Detect format by inspecting header keys ─────────────────────────────── */
$sampleHeaders = array_keys($rows[0] ?? []);
$normalizedHeaders = array_map(
    fn($h) => strtolower(trim(str_replace([' ', '-'], '_', (string)$h))),
    $sampleHeaders
);

// Format B: headers (aside from the first emp-id column) are mostly numeric day numbers
$hasDateCol   = in_array('date', $normalizedHeaders, true)
             || in_array('att_date', $normalizedHeaders, true);

// Count how many headers are purely numeric (day numbers 1-31)
$numericDayCount = 0;
foreach ($normalizedHeaders as $h) {
    if (is_numeric($h) && (int)$h >= 1 && (int)$h <= 31) {
        $numericDayCount++;
    }
}
$isFormatB = (!$hasDateCol && $numericDayCount >= 5); // ≥5 day columns → matrix

/* ── Pre-load employee map: employee_id code → DB id ──────────────────────── */
$db = db();
$empMap = [];
foreach ($db->query("SELECT id, employee_id FROM employees WHERE status='Active'")->fetchAll() as $e) {
    $empMap[strtoupper(trim($e['employee_id']))] = (int)$e['id'];
}

/* ── Pre-load existing records for this month (for skip_existing) ─────────── */
$existingSet = []; // "empDbId|att_date" → true
if ($skipExisting) {
    $exStmt = $db->prepare(
        "SELECT employee_id, att_date FROM attendance WHERE att_date BETWEEN ? AND ?"
    );
    $exStmt->execute([$monthStart, $monthEnd]);
    foreach ($exStmt->fetchAll() as $rec) {
        $existingSet[$rec['employee_id'] . '|' . $rec['att_date']] = true;
    }
}

/* ── Prepare UPSERT statement ────────────────────────────────────────────── */
$user = current_user();

$upsertSql = "INSERT INTO attendance
    (employee_id, att_date, status, in_time, out_time, remarks, marked_by, created_at)
  VALUES
    (:emp_id, :att_date, :status, :in_time, :out_time, :remarks, :marked_by, NOW())
  ON DUPLICATE KEY UPDATE
    status    = VALUES(status),
    in_time   = VALUES(in_time),
    out_time  = VALUES(out_time),
    remarks   = VALUES(remarks),
    marked_by = VALUES(marked_by)";
$upsertStmt = $db->prepare($upsertSql);

$inserted = 0;
$updated  = 0;
$skipped  = 0;
$errors   = [];
$empProcessed = 0;

/* ════════════════════════════════════════════════════════════════════════════
   FORMAT C — Biometric punch report (per-employee blocks)
   Layout (one block per employee):
     Row:  Empcode | <code> | … | Name | <name>
     Row:  Date | Shift | IN | Out1 | In2 | … | Out | Work+OT | OT | Break
     Rows: <dd/mm/yyyy> | <shift> | <first-in> | … | <last-out> | … | <OT>
   First punch (IN) → in_time, final punch (Out) → out_time, OT column → ot_hours.
   "--:--", blank or shift "X" mean no punch → Absent.
   ════════════════════════════════════════════════════════════════════════════ */
if ($isFormatC) {

    // Format C upsert also carries ot_hours (the report provides it).
    $upsertC = $db->prepare(
        "INSERT INTO attendance
            (employee_id, att_date, status, in_time, out_time, ot_hours, remarks, marked_by, created_at)
         VALUES
            (:emp_id, :att_date, :status, :in_time, :out_time, :ot_hours, :remarks, :marked_by, NOW())
         ON DUPLICATE KEY UPDATE
            status   = VALUES(status),   in_time  = VALUES(in_time),
            out_time = VALUES(out_time), ot_hours = VALUES(ot_hours),
            remarks  = VALUES(remarks),  marked_by = VALUES(marked_by)"
    );

    $curCode  = '';
    $curEmpId = null;          // null = no current/valid employee block
    $colMap   = ['date' => 0, 'in' => 2, 'out' => 17, 'ot' => 19]; // sensible defaults
    $seenCodes = [];

    foreach ($grid as $cells) {
        $cells = array_map(fn($v) => trim((string)$v), $cells);

        // (1) Employee header row: "Empcode" label followed by the code.
        $empLabelIdx = null;
        foreach ($cells as $idx => $val) {
            if (strtolower($val) === 'empcode') { $empLabelIdx = $idx; break; }
        }
        if ($empLabelIdx !== null) {
            $curCode = '';
            for ($k = $empLabelIdx + 1; $k <= max(array_keys($cells)); $k++) {
                if (isset($cells[$k]) && $cells[$k] !== '') { $curCode = strtoupper($cells[$k]); break; }
            }
            $curEmpId = $empMap[$curCode] ?? null;
            if ($curCode !== '' && !isset($seenCodes[$curCode])) {
                $seenCodes[$curCode] = true;
                $empProcessed++;
                if ($curEmpId === null) {
                    $errors[] = "Employee '$curCode' not found or inactive — block skipped.";
                }
            }
            continue;
        }

        // (2) Column-header row: "Date | Shift | IN | … | Out | … | OT".
        if (strtolower($cells[0] ?? '') === 'date') {
            $colMap = [];
            foreach ($cells as $idx => $val) {
                $lab = strtolower($val);
                // First occurrence wins, so "IN" beats "In2…" and "Out" the lone final column.
                if ($lab !== '' && !isset($colMap[$lab])) $colMap[$lab] = $idx;
            }
            $colMap += ['date' => 0, 'in' => 2, 'out' => 17, 'ot' => 19];
            continue;
        }

        // (3) Daily record row: column A holds a real date.
        $rawDate = $cells[$colMap['date']] ?? '';
        $useDate = _attm_parse_date($rawDate);
        if (!$useDate) continue;                 // totals/blank/separator row
        if ($curEmpId === null) { $skipped++; continue; }
        if (substr($useDate, 0, 7) !== $attMonth) { $skipped++; continue; }
        if ($skipWeekends && _attm_is_weekend($useDate)) { $skipped++; continue; }
        if ($skipExisting && isset($existingSet[$curEmpId . '|' . $useDate])) { $skipped++; continue; }

        $inRaw  = $cells[$colMap['in']  ?? 2]  ?? '';
        $outRaw = $cells[$colMap['out'] ?? 17] ?? '';
        $otRaw  = $cells[$colMap['ot']  ?? 19] ?? '';

        // "--:--" and "0:00"/"00:00" mean no real punch.
        $inTime  = ($inRaw  === '--:--') ? null : _attm_normalize_time($inRaw);
        $outTime = ($outRaw === '--:--') ? null : _attm_normalize_time($outRaw);
        $otHours = _attm_hhmm_to_hours($otRaw);

        if ($inTime) {
            $status = $autoClassify ? _attm_classify_from_time($inTime, $useDate) : 'On Time';
        } else {
            $status = 'Absent';
            $inTime = $outTime = null;
            $otHours = 0;
        }

        $wasExisting = isset($existingSet[$curEmpId . '|' . $useDate]);
        try {
            $upsertC->execute([
                ':emp_id'    => $curEmpId,
                ':att_date'  => $useDate,
                ':status'    => $status,
                ':in_time'   => $inTime,
                ':out_time'  => $outTime,
                ':ot_hours'  => $otHours ?: null,
                ':remarks'   => null,
                ':marked_by' => $user['id'],
            ]);
            if ($wasExisting) { $updated++; } else { $inserted++; }
        } catch (PDOException $e) {
            $skipped++;
            $errors[] = "$curCode / $useDate: DB error — " . $e->getMessage();
        }
    }
}

/* ════════════════════════════════════════════════════════════════════════════
   FORMAT A — Row-per-record
   ════════════════════════════════════════════════════════════════════════════ */
elseif (!$isFormatB) {

    foreach ($rows as $i => $row) {
        $lineNo = $i + 2;

        /* Normalize keys */
        $r = [];
        foreach ($row as $k => $v) {
            $nk     = strtolower(trim(str_replace([' ', '-'], '_', (string)$k)));
            $r[$nk] = trim((string)$v);
        }

        /* Resolve employee code */
        $empCode = strtoupper(
            $r['employee_id'] ?? $r['emp_id'] ?? $r['emp_code'] ?? $r['code'] ?? ''
        );
        if ($empCode === '') {
            $skipped++;
            $errors[] = "Row $lineNo: employee_id is missing or empty — skipped.";
            continue;
        }
        if (!isset($empMap[$empCode])) {
            $skipped++;
            $errors[] = "Row $lineNo: employee '$empCode' not found or inactive — skipped.";
            continue;
        }
        $empDbId = $empMap[$empCode];

        /* Resolve date */
        $rawDate = $r['date'] ?? $r['att_date'] ?? '';
        $useDate = _attm_parse_date($rawDate);
        if (!$useDate) {
            $skipped++;
            $errors[] = "Row $lineNo ($empCode): missing or invalid date '$rawDate' — skipped.";
            continue;
        }

        /* Verify the date belongs to the selected month */
        if (substr($useDate, 0, 7) !== $attMonth) {
            $skipped++;
            $errors[] = "Row $lineNo ($empCode): date $useDate is outside month $attMonth — skipped.";
            continue;
        }

        /* Skip weekends if requested */
        if ($skipWeekends && _attm_is_weekend($useDate)) {
            $skipped++;
            continue;
        }

        /* Skip existing record if requested */
        if ($skipExisting && isset($existingSet[$empDbId . '|' . $useDate])) {
            $skipped++;
            continue;
        }

        /* Normalize times */
        $inTime  = _attm_normalize_time($r['in_time']  ?? $r['check_in']  ?? $r['time_in']  ?? '');
        $outTime = _attm_normalize_time($r['out_time'] ?? $r['check_out'] ?? $r['time_out'] ?? '');

        /* Status */
        $rawStatus = $r['status'] ?? $r['attendance'] ?? '';
        if ($rawStatus !== '') {
            $status = _attm_normalize_status($rawStatus);
        } elseif ($autoClassify) {
            $status = _attm_classify_from_time($inTime, $useDate);
        } else {
            $status = $inTime ? 'On Time' : 'Absent';
        }

        /* Clear times for non-work statuses */
        if (in_array($status, ['Absent', 'Holiday', 'Comp Off'], true)) {
            $inTime  = null;
            $outTime = null;
        }

        $remarks = sanitize($r['remarks'] ?? $r['note'] ?? $r['comment'] ?? '');

        /* Upsert */
        $wasExisting = isset($existingSet[$empDbId . '|' . $useDate]);
        try {
            $upsertStmt->execute([
                ':emp_id'    => $empDbId,
                ':att_date'  => $useDate,
                ':status'    => $status,
                ':in_time'   => $inTime,
                ':out_time'  => $outTime,
                ':remarks'   => $remarks ?: null,
                ':marked_by' => $user['id'],
            ]);
            if ($wasExisting) { $updated++; } else { $inserted++; }
        } catch (PDOException $e) {
            $skipped++;
            $errors[] = "Row $lineNo ($empCode / $useDate): DB error — " . $e->getMessage();
        }
    }
}

/* ════════════════════════════════════════════════════════════════════════════
   FORMAT B — Matrix (employee rows × day-number columns)
   ════════════════════════════════════════════════════════════════════════════ */
else {

    foreach ($rows as $i => $row) {
        $lineNo = $i + 2;

        /* Normalize keys */
        $r = [];
        foreach ($row as $k => $v) {
            $nk     = strtolower(trim(str_replace([' ', '-'], '_', (string)$k)));
            $r[$nk] = trim((string)$v);
        }

        /* Resolve employee code */
        $empCode = strtoupper(
            $r['employee_id'] ?? $r['emp_id'] ?? $r['emp_code'] ?? $r['code'] ?? ''
        );
        if ($empCode === '') {
            $skipped++;
            $errors[] = "Row $lineNo: employee_id is missing or empty — skipped.";
            continue;
        }
        if (!isset($empMap[$empCode])) {
            $skipped++;
            $errors[] = "Row $lineNo: employee '$empCode' not found or inactive — skipped.";
            continue;
        }
        $empDbId = $empMap[$empCode];

        /* Iterate over day columns */
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dayKey  = (string)$day;
            $dayKey0 = str_pad($dayKey, 2, '0', STR_PAD_LEFT); // "01", "02", ...

            /* Accept both "1" and "01" as column keys */
            $cellValue = '';
            if (array_key_exists($dayKey, $r))  $cellValue = $r[$dayKey];
            elseif (array_key_exists($dayKey0, $r)) $cellValue = $r[$dayKey0];

            $useDate = sprintf('%s-%02d', $attMonth, $day);

            /* Skip weekends if requested */
            if ($skipWeekends && _attm_is_weekend($useDate)) {
                $skipped++;
                continue;
            }

            /* Skip existing if requested */
            if ($skipExisting && isset($existingSet[$empDbId . '|' . $useDate])) {
                $skipped++;
                continue;
            }

            /* Empty cell → default to Absent (can also skip — Absent makes the record explicit) */
            $status  = _attm_normalize_status($cellValue !== '' ? $cellValue : 'A');
            $inTime  = null;
            $outTime = null;

            /* Clear times for non-work statuses (all Format B statuses) */
            // Format B does not carry time data — only status codes

            $wasExisting = isset($existingSet[$empDbId . '|' . $useDate]);
            try {
                $upsertStmt->execute([
                    ':emp_id'    => $empDbId,
                    ':att_date'  => $useDate,
                    ':status'    => $status,
                    ':in_time'   => null,
                    ':out_time'  => null,
                    ':remarks'   => null,
                    ':marked_by' => $user['id'],
                ]);
                if ($wasExisting) { $updated++; } else { $inserted++; }
            } catch (PDOException $e) {
                $skipped++;
                $errors[] = "$empCode / Day $day: DB error — " . $e->getMessage();
            }
        } // end day loop
    } // end row loop
}

/* ── Store rich result in session for the index banner ───────────────────── */
$formatLabel = $isFormatC ? 'Biometric punch report (Format C)'
             : ($isFormatB ? 'Matrix (Format B)' : 'Row-per-record (Format A)');

$empCount = $isFormatC
    ? $empProcessed
    : count(array_unique(array_column($rows, array_key_first($rows[0] ?? []))));

$_SESSION['att_monthly_result'] = [
    'month'     => date('F Y', strtotime($attMonth . '-01')) . " [$formatLabel]",
    'employees' => $empCount,
    'saved'     => $inserted,
    'updated'   => $updated,
    'skipped'   => $skipped,
    'warnings'  => array_slice($errors, 0, 20),
];

/* ── Flash fallback ──────────────────────────────────────────────────────── */
$type = ($inserted + $updated === 0 && $skipped > 0) ? 'error'
      : ($errors ? 'warn' : 'success');
flash($type, sprintf(
    'Monthly import complete for %s [%s]: %d inserted, %d updated, %d skipped.',
    $attMonth, $formatLabel, $inserted, $updated, $skipped
));

// Land on the standalone matrix report (report.php expects numeric month + year).
redirect(BASE_URL . "/modules/attendance/report.php?month=" . (int)$mMon . "&year=" . (int)$mYear);


/* ═══════════════════════════════════════════════════════════════════════════
   HELPER FUNCTIONS
   (Same logic as process_daily.php — namespaced with _attm_ prefix to avoid
    redeclaration errors if both files are somehow included in the same request)
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Normalize an attendance status string to the schema ENUM value.
 */
function _attm_normalize_status(string $raw): string
{
    $map = [
        // Present / On Time
        'p'           => 'On Time', 'present'     => 'On Time',
        'on time'     => 'On Time', 'ontime'      => 'On Time', 'o' => 'On Time',
        // Late
        'l'           => 'Late',    'late'         => 'Late',
        // Absent
        'a'           => 'Absent',  'absent'       => 'Absent', 'ab' => 'Absent',
        // Half Day
        'hd'          => 'Half Day','half day'     => 'Half Day',
        'halfday'     => 'Half Day','half'         => 'Half Day',
        // On Duty
        'od'          => 'OD',      'on duty'      => 'OD',     'onduty'      => 'OD',
        // Comp Off
        'co'          => 'Comp Off','comp off'     => 'Comp Off','compoff'     => 'Comp Off',
        'comp_off'    => 'Comp Off','compensatory' => 'Comp Off',
        // Holiday
        'h'           => 'Holiday', 'holiday'      => 'Holiday', 'hol'        => 'Holiday',
        'week off'    => 'Holiday', 'weekoff'      => 'Holiday',
    ];
    $key = strtolower(trim($raw));
    if (isset($map[$key])) return $map[$key];

    $valid = ['On Time','Late','Absent','OD','Comp Off','Half Day','Holiday'];
    foreach ($valid as $v) {
        if (strcasecmp($key, strtolower($v)) === 0) return $v;
    }
    return 'Absent'; // safe fallback
}

/**
 * Auto-classify status based on in_time vs configured work start + grace period.
 */
function _attm_classify_from_time(?string $inTime, string $attDate): string
{
    if (!$inTime) return 'Absent';
    try {
        $deadline = new DateTime($attDate . ' ' . WORK_START_TIME);
        $deadline->modify('+' . ATTENDANCE_GRACE_MINUTES . ' minutes');
        $actual   = new DateTime($attDate . ' ' . $inTime);
        return ($actual <= $deadline) ? 'On Time' : 'Late';
    } catch (Exception $e) {
        return 'On Time';
    }
}

/**
 * Normalize a time string to H:i:s (24-hour) for MySQL TIME column.
 */
function _attm_normalize_time(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || strtolower($raw) === 'null') return null;

    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $raw)) {
        [$h, $m, $s] = explode(':', $raw);
        return sprintf('%02d:%02d:%02d', (int)$h, (int)$m, (int)$s);
    }
    if (preg_match('/^(\d{1,2})[:\.](\d{2})$/', $raw, $m)) {
        return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
    }
    if (preg_match('/^(\d{1,2})[:\.](\d{2})(?::(\d{2}))?\s*(am|pm)$/i', $raw, $m)) {
        $h  = (int)$m[1];
        $mn = (int)$m[2];
        $s  = isset($m[3]) ? (int)$m[3] : 0;
        if (strtolower($m[4]) === 'pm' && $h !== 12) $h += 12;
        if (strtolower($m[4]) === 'am' && $h === 12) $h = 0;
        return sprintf('%02d:%02d:%02d', $h, $mn, $s);
    }
    if (preg_match('/^\d{3,4}$/', $raw)) {
        $raw = str_pad($raw, 4, '0', STR_PAD_LEFT);
        return sprintf('%02d:%02d:00', (int)substr($raw, 0, 2), (int)substr($raw, 2, 2));
    }
    $ts = strtotime('1970-01-01 ' . $raw);
    if ($ts !== false) return date('H:i:s', $ts);
    return null;
}

/**
 * Parse a date string to Y-m-d, handling multiple regional formats.
 */
function _attm_parse_date(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;

    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/', $raw, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }

    if (is_numeric($raw) && (int)$raw > 40000 && (int)$raw < 60000) {
        $ts = mktime(0,0,0,1,1,1900) + ((int)$raw - 2) * 86400;
        return date('Y-m-d', $ts);
    }

    $ts = strtotime($raw);
    return ($ts !== false) ? date('Y-m-d', $ts) : '';
}

/**
 * Returns true if the given Y-m-d date falls on Saturday (6) or Sunday (7).
 */
function _attm_is_weekend(string $date): bool
{
    return (int)date('N', strtotime($date)) >= 6;
}

/**
 * Convert an "HH:MM" duration (e.g. OT "02:06") to decimal hours. Returns 0.0
 * for blank, "--:--", "00:00" or anything unparseable.
 */
function _attm_hhmm_to_hours(string $raw): float
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '--:--') return 0.0;
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
        return round((int)$m[1] + (int)$m[2] / 60, 2);
    }
    return 0.0;
}

/**
 * Recognise the blocked biometric punch report (Format C): it carries an
 * "Empcode" label cell and a "Date"+"IN"/"Out" column-header row somewhere in
 * the body, rather than a single header row at the top.
 */
function _attm_detect_punch_report(array $grid): bool
{
    if (!$grid) return false;
    $hasEmpcode = false; $hasDateHeader = false;
    foreach ($grid as $cells) {
        foreach ($cells as $val) {
            $v = strtolower(trim((string)$val));
            if ($v === 'empcode') $hasEmpcode = true;
            if ($v === 'date')    $hasDateHeader = true;
            if ($hasEmpcode && $hasDateHeader) return true;
        }
    }
    return false;
}

/**
 * Parse a CSV file into a raw 0-indexed grid (no header combine).
 */
function _attm_parse_csv_grid(string $path): array
{
    $grid = [];
    $fh = fopen($path, 'r');
    if ($fh === false) return [];
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);
    while (($line = fgetcsv($fh, 0, ',')) !== false) {
        $grid[] = array_map(fn($v) => (string)$v, $line);
    }
    fclose($fh);
    return $grid;
}

/**
 * Parse an XLSX file into a raw 0-indexed grid (no header combine), preserving
 * column positions so blocked layouts can be walked by column index.
 */
function _attm_parse_xlsx_grid(string $path): array
{
    if (!class_exists('ZipArchive')) return [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    $shared = [];
    $ssXml  = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = simplexml_load_string($ssXml);
        foreach ($ss->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string)$si->t;
            } else {
                $text = '';
                foreach ($si->r as $rr) $text .= (string)$rr->t;
                $shared[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheetXml) return [];

    $sheet   = simplexml_load_string($sheetXml);
    $rawRows = [];
    foreach ($sheet->sheetData->row as $xmlRow) {
        $rowIdx = (int)$xmlRow['r'] - 1;
        $cells  = [];
        foreach ($xmlRow->c as $c) {
            $colIdx = _attm_xlsx_col_index((string)$c['r']);
            $v      = isset($c->v) ? (string)$c->v : '';
            if ((string)$c['t'] === 's') $v = $shared[(int)$v] ?? '';
            $cells[$colIdx] = $v;
        }
        if (!$cells) { $rawRows[$rowIdx] = []; continue; }
        $max = max(array_keys($cells));
        $row = [];
        for ($j = 0; $j <= $max; $j++) $row[] = $cells[$j] ?? '';
        $rawRows[$rowIdx] = $row;
    }
    ksort($rawRows);
    return array_values($rawRows);
}

/**
 * Parse a CSV file and return an array of associative arrays.
 */
function _attm_parse_csv(string $path): array
{
    $rows    = [];
    $headers = null;
    $fh = fopen($path, 'r');
    if ($fh === false) return [];
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);
    while (($line = fgetcsv($fh, 0, ',')) !== false) {
        if ($headers === null) {
            $headers = array_map('trim', $line);
            continue;
        }
        if (count(array_filter($line, fn($v) => trim($v) !== '')) === 0) continue;
        if (count($line) < count($headers)) {
            $line = array_pad($line, count($headers), '');
        }
        $rows[] = array_combine($headers, array_slice($line, 0, count($headers)));
    }
    fclose($fh);
    return $rows;
}

/**
 * Parse an XLSX file using ZipArchive + SimpleXML.
 */
function _attm_parse_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) return [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    $shared = [];
    $ssXml  = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = simplexml_load_string($ssXml);
        foreach ($ss->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string)$si->t;
            } else {
                $text = '';
                foreach ($si->r as $rr) $text .= (string)$rr->t;
                $shared[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheetXml) return [];

    $sheet   = simplexml_load_string($sheetXml);
    $rawRows = [];
    foreach ($sheet->sheetData->row as $xmlRow) {
        $rowIdx = (int)$xmlRow['r'] - 1;
        $cells  = [];
        foreach ($xmlRow->c as $c) {
            $colIdx = _attm_xlsx_col_index((string)$c['r']);
            $v      = isset($c->v) ? (string)$c->v : '';
            if ((string)$c['t'] === 's') $v = $shared[(int)$v] ?? '';
            $cells[$colIdx] = $v;
        }
        if (!$cells) continue;
        $max = max(array_keys($cells));
        $row = [];
        for ($j = 0; $j <= $max; $j++) $row[] = $cells[$j] ?? '';
        $rawRows[$rowIdx] = $row;
    }
    ksort($rawRows);
    $rawRows = array_values($rawRows);
    if (count($rawRows) < 2) return [];

    $headers = array_shift($rawRows);
    $result  = [];
    foreach ($rawRows as $cells) {
        while (count($cells) < count($headers)) $cells[] = '';
        $result[] = array_combine($headers, array_slice($cells, 0, count($headers)));
    }
    return $result;
}

/**
 * Convert an Excel column reference (e.g. "A", "AA") to a 0-based index.
 */
function _attm_xlsx_col_index(string $cellRef): int
{
    preg_match('/^([A-Z]+)/i', $cellRef, $m);
    $col = strtoupper($m[1] ?? 'A');
    $n   = 0;
    for ($i = 0, $len = strlen($col); $i < $len; $i++) {
        $n = $n * 26 + (ord($col[$i]) - 64);
    }
    return $n - 1;
}
