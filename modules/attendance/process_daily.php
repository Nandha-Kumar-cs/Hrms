<?php
/**
 * Daily Attendance Import Processor
 * ─────────────────────────────────
 * Accepts a CSV or XLSX file containing attendance data for ONE specific day.
 * Performs an UPSERT (INSERT … ON DUPLICATE KEY UPDATE) into the `attendance`
 * table, keyed on (employee_id, att_date).
 *
 * Expected file columns (case-insensitive, spaces/hyphens → underscores):
 *   employee_id | emp_id | emp_code | code   ← required
 *   in_time     | check_in | time_in          ← optional
 *   out_time    | check_out| time_out         ← optional
 *   status      | attendance                  ← optional; auto-computed if blank
 *   remarks     | note | comment              ← optional
 *   date        | att_date                    ← optional (overrides form field per-row)
 *
 * Auto-status classification (when status column is empty):
 *   No in_time                        → Absent
 *   in_time ≤ WORK_START + GRACE min  → On Time
 *   in_time > WORK_START + GRACE min  → Late
 *
 * POST fields from modals.php:
 *   att_date       — the attendance date (Y-m-d) for all rows (unless overridden in file)
 *   auto_classify  — "1" to auto-compute status from in_time
 *   skip_holidays  — "1" to skip the date if it is a company holiday
 *   csrf_token     — CSRF protection
 *   import_file    — uploaded file (multipart)
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('attendance', 'mark');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/modules/attendance/index.php');
}

/* ── POST fields ─────────────────────────────────────────────────────────── */
$attDate      = $_POST['att_date'] ?? date('Y-m-d');
$autoClassify = !empty($_POST['auto_classify']);
$skipHolidays = !empty($_POST['skip_holidays']);

// Validate date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $attDate)) {
    flash('error', 'Invalid attendance date.');
    redirect(BASE_URL . '/modules/attendance/index.php');
}
// Future dates not allowed
if ($attDate > date('Y-m-d')) {
    flash('error', 'Cannot import attendance for a future date.');
    redirect(BASE_URL . '/modules/attendance/index.php');
}

/* ── Holiday check ───────────────────────────────────────────────────────── */
$db = db();
if ($skipHolidays) {
    $hStmt = $db->prepare("SELECT name FROM holidays WHERE h_date = ? LIMIT 1");
    $hStmt->execute([$attDate]);
    $holidayName = $hStmt->fetchColumn();
    if ($holidayName) {
        flash('warn', "Skipped import: $attDate is a company holiday ($holidayName).");
        redirect(BASE_URL . '/modules/attendance/index.php');
    }
}

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
    ? _att_parse_csv($file['tmp_name'])
    : _att_parse_xlsx($file['tmp_name']);

if (empty($rows)) {
    flash('error', 'The file is empty or could not be parsed. Check the format and try again.');
    redirect(BASE_URL . '/modules/attendance/index.php');
}

/* ── Pre-load employee map: employee_id code → DB id ──────────────────────── */
$empMap = [];
foreach ($db->query("SELECT id, employee_id FROM employees WHERE status='Active'")->fetchAll() as $e) {
    $empMap[strtoupper(trim($e['employee_id']))] = (int)$e['id'];
}

/* ── Pre-load existing record IDs for today (for smarter UPDATE logic) ─────── */
$existStmt = $db->prepare(
    "SELECT employee_id FROM attendance WHERE att_date = ? AND employee_id IN
     (SELECT id FROM employees WHERE status='Active')"
);
$existStmt->execute([$attDate]);
$existingEmpIds = array_flip($existStmt->fetchAll(PDO::FETCH_COLUMN));

/* ── Process rows ────────────────────────────────────────────────────────── */
$user     = current_user();
$inserted = 0;
$updated  = 0;
$skipped  = 0;
$errors   = [];

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

foreach ($rows as $i => $row) {
    $lineNo = $i + 2; // 1-based, account for header

    /* Normalize keys */
    $r = [];
    foreach ($row as $k => $v) {
        $nk     = strtolower(trim(str_replace([' ','-'], '_', (string)$k)));
        $r[$nk] = trim((string)$v);
    }

    /* Resolve employee code */
    $empCode = strtoupper(
        $r['employee_id'] ?? $r['emp_id'] ?? $r['emp_code'] ?? $r['code'] ?? ''
    );
    if ($empCode === '') {
        $skipped++;
        $errors[] = "Row $lineNo: employee_id column is missing or empty — skipped.";
        continue;
    }
    if (!isset($empMap[$empCode])) {
        $skipped++;
        $errors[] = "Row $lineNo: employee '$empCode' not found or inactive — skipped.";
        continue;
    }
    $empDbId = $empMap[$empCode];

    /* Resolve date (per-row override or form field) */
    $rowDate = _att_parse_date($r['date'] ?? $r['att_date'] ?? '');
    $useDate = ($rowDate && $rowDate <= date('Y-m-d')) ? $rowDate : $attDate;

    /* Normalize times */
    $inTime  = _att_normalize_time($r['in_time']  ?? $r['check_in']  ?? $r['time_in']  ?? '');
    $outTime = _att_normalize_time($r['out_time'] ?? $r['check_out'] ?? $r['time_out'] ?? '');

    /* Normalize / auto-classify status */
    $rawStatus = $r['status'] ?? $r['attendance'] ?? '';
    if ($rawStatus !== '') {
        $status = _att_normalize_status($rawStatus);
    } elseif ($autoClassify) {
        $status = _att_classify_from_time($inTime, $useDate);
    } else {
        $status = $inTime ? 'On Time' : 'Absent';
    }

    /* If Absent / Holiday / Comp Off → clear times */
    if (in_array($status, ['Absent', 'Holiday', 'Comp Off'], true)) {
        $inTime  = null;
        $outTime = null;
    }

    $remarks = sanitize($r['remarks'] ?? $r['note'] ?? $r['comment'] ?? '');

    /* Upsert */
    try {
        $wasExisting = isset($existingEmpIds[$empDbId]);
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
        $errors[] = "Row $lineNo ($empCode): DB error — " . $e->getMessage();
    }
}

/* ── Store rich result in session for the index banner ───────────────────── */
$_SESSION['att_daily_result'] = [
    'date'     => $attDate,
    'saved'    => $inserted,
    'updated'  => $updated,
    'skipped'  => $skipped,
    'warnings' => array_slice($errors, 0, 20),
];

/* ── Flash fallback (for cases where session isn't read by index) ────────── */
$type = ($inserted + $updated === 0 && $skipped > 0) ? 'error'
      : ($errors ? 'warn' : 'success');
flash($type, sprintf(
    'Daily import complete for %s: %d inserted, %d updated, %d skipped.',
    date_fmt($attDate), $inserted, $updated, $skipped
));

$redirectMonth = substr($attDate, 0, 7);
redirect(BASE_URL . "/modules/attendance/index.php?month=$redirectMonth");


/* ═══════════════════════════════════════════════════════════════════════════
   HELPER FUNCTIONS
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Normalize an attendance status string to the schema ENUM value.
 * Handles abbreviations, mixed case, and common variations.
 */
function _att_normalize_status(string $raw): string
{
    $map = [
        // Present / On Time
        'p'        => 'On Time', 'present'   => 'On Time',
        'on time'  => 'On Time', 'ontime'    => 'On Time', 'o'       => 'On Time',
        // Late
        'l'        => 'Late',    'late'       => 'Late',
        // Absent
        'a'        => 'Absent',  'absent'     => 'Absent', 'ab'      => 'Absent',
        // Half Day
        'hd'       => 'Half Day','half day'   => 'Half Day','halfday' => 'Half Day',
        'half'     => 'Half Day',
        // On Duty
        'od'       => 'OD',      'on duty'    => 'OD',     'onduty'  => 'OD',
        // Comp Off
        'co'       => 'Comp Off','comp off'   => 'Comp Off','compoff' => 'Comp Off',
        'comp_off' => 'Comp Off','compensatory' => 'Comp Off',
        // Holiday
        'h'        => 'Holiday', 'holiday'    => 'Holiday', 'hol'    => 'Holiday',
        'week off' => 'Holiday', 'weekoff'    => 'Holiday',
    ];
    $key = strtolower(trim($raw));
    if (isset($map[$key])) return $map[$key];

    /* If it already matches an ENUM value exactly (case-insensitive) */
    $valid = ['On Time','Late','Absent','OD','Comp Off','Half Day','Holiday'];
    foreach ($valid as $v) {
        if (strcasecmp($key, strtolower($v)) === 0) return $v;
    }
    return 'Absent'; // safe fallback
}

/**
 * Auto-classify status based on in_time vs configured work start + grace period.
 * Uses the att_date to build the correct full timestamp comparison.
 */
function _att_classify_from_time(?string $inTime, string $attDate): string
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
 * Normalize a time string to H:i:s (24-hour) suitable for MySQL TIME column.
 * Handles: H:i, H:i:s, h:i A, h:i:s A, H.i, H:iAM, etc.
 * Returns null-string '' if the input is blank / unparseable.
 */
function _att_normalize_time(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || strtolower($raw) === 'null') return null;

    /* Already H:i:s */
    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $raw)) {
        [$h, $m, $s] = explode(':', $raw);
        return sprintf('%02d:%02d:%02d', (int)$h, (int)$m, (int)$s);
    }

    /* H:i or H.i */
    if (preg_match('/^(\d{1,2})[:\.](\d{2})$/', $raw, $m)) {
        return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
    }

    /* 12-hour with AM/PM — e.g. "9:05 AM", "09:05AM", "9:05:00 PM" */
    if (preg_match('/^(\d{1,2})[:\.](\d{2})(?::(\d{2}))?\s*(am|pm)$/i', $raw, $m)) {
        $h  = (int)$m[1];
        $mn = (int)$m[2];
        $s  = isset($m[3]) ? (int)$m[3] : 0;
        $meridiem = strtolower($m[4]);
        if ($meridiem === 'pm' && $h !== 12) $h += 12;
        if ($meridiem === 'am' && $h === 12) $h = 0;
        return sprintf('%02d:%02d:%02d', $h, $mn, $s);
    }

    /* HHMM as 4-digit integer e.g. "0905", "1830" */
    if (preg_match('/^\d{3,4}$/', $raw)) {
        $raw = str_pad($raw, 4, '0', STR_PAD_LEFT);
        return sprintf('%02d:%02d:00', (int)substr($raw, 0, 2), (int)substr($raw, 2, 2));
    }

    /* Last resort: strtotime */
    $ts = strtotime('1970-01-01 ' . $raw);
    if ($ts !== false) return date('H:i:s', $ts);

    return null; // unparseable
}

/**
 * Parse a date string to Y-m-d, handling multiple regional formats.
 * Returns '' if unparseable.
 */
function _att_parse_date(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';

    /* Already Y-m-d */
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;

    /* d/m/Y or d-m-Y (Indian format) */
    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/', $raw, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }

    /* m/d/Y (US format) — only if month <= 12 and day <= 31 */
    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/', $raw, $m)) {
        if ((int)$m[1] <= 12) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[1], (int)$m[2]);
        }
    }

    /* Excel serial date number (days since 1900-01-01, with Lotus 1-2-3 leap-year bug) */
    if (is_numeric($raw) && (int)$raw > 40000 && (int)$raw < 60000) {
        $ts = mktime(0,0,0,1,1,1900) + ((int)$raw - 2) * 86400;
        return date('Y-m-d', $ts);
    }

    /* Fallback: strtotime */
    $ts = strtotime($raw);
    return ($ts !== false) ? date('Y-m-d', $ts) : '';
}

/**
 * Parse a CSV file and return an array of associative arrays (header-keyed rows).
 */
function _att_parse_csv(string $path): array
{
    $rows    = [];
    $headers = null;
    $fh = fopen($path, 'r');
    if ($fh === false) return [];
    // Skip UTF-8 BOM if present
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);
    while (($line = fgetcsv($fh, 0, ',')) !== false) {
        if ($headers === null) {
            $headers = array_map('trim', $line);
            continue;
        }
        if (count(array_filter($line, fn($v) => trim($v) !== '')) === 0) continue; // skip empty
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
 * Returns the same array-of-associative-arrays format as _att_parse_csv().
 * Handles shared strings and multi-run rich text cells.
 */
function _att_parse_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) return [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    /* Shared strings */
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
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
            $colIdx = _att_xlsx_col_index((string)$c['r']);
            $v      = isset($c->v) ? (string)$c->v : '';
            if ((string)$c['t'] === 's') $v = $shared[(int)$v] ?? '';
            $cells[$colIdx] = $v;
        }
        if (!$cells) continue;
        $max = max(array_keys($cells));
        $row = [];
        for ($i = 0; $i <= $max; $i++) $row[] = $cells[$i] ?? '';
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
 * Convert an Excel column reference (e.g. "A", "B", "AA", "AB") to a 0-based index.
 */
function _att_xlsx_col_index(string $cellRef): int
{
    preg_match('/^([A-Z]+)/i', $cellRef, $m);
    $col = strtoupper($m[1] ?? 'A');
    $n   = 0;
    for ($i = 0, $len = strlen($col); $i < $len; $i++) {
        $n = $n * 26 + (ord($col[$i]) - 64);
    }
    return $n - 1;
}
