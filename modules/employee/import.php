<?php
/**
 * Employee CSV / XLSX bulk import
 *
 * Accepted columns (case-insensitive, spaces/hyphens normalized to underscores):
 *   name | full_name                          — required
 *   email | email_address                     — optional; a placeholder is
 *                                                generated when blank
 *   employee_id | emp_id | empcode | emp_code — optional; if supplied and found,
 *                                                row is updated
 *   phone | mobile
 *   gender
 *   address
 *   dob | date_of_birth
 *   department | department_name
 *   designation | designation_name
 *   join_date | joining_date | date_of_joining | doj
 *   employment_type | type
 *   pan_number | pan
 *   aadhaar_number | aadhaar
 *   uan_number | uan
 *
 * Dates accept dd/mm/yyyy, dd-mm-yyyy, yyyy-mm-dd and Excel serial numbers.
 *
 * Upsert logic:
 *   - If employee_id matches an existing record → UPDATE
 *   - Else if a (real) email matches → UPDATE
 *   - Otherwise → INSERT (new employee; a login account is created only when a
 *     real email was supplied)
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('employee', 'create');

verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/modules/employee/index.php');
}

$file = $_FILES['import_file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK || empty($file['name'])) {
    flash('error', 'No file uploaded or upload failed. Please try again.');
    redirect(BASE_URL . '/modules/employee/index.php');
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'xlsx'], true)) {
    flash('error', 'Unsupported file type. Please upload a CSV or XLSX file.');
    redirect(BASE_URL . '/modules/employee/index.php');
}

$rows = $ext === 'csv' ? _parse_csv($file['tmp_name']) : _parse_xlsx($file['tmp_name']);

if (empty($rows)) {
    flash('error', 'The file is empty or could not be read. Check the format and try again.');
    redirect(BASE_URL . '/modules/employee/index.php');
}

// ── Pre-load lookup tables ────────────────────────────────────────────────────
$db = db();

$deptMap = [];
foreach ($db->query('SELECT id, name FROM departments')->fetchAll() as $r) {
    $deptMap[strtolower(trim($r['name']))] = (int)$r['id'];
}

$desigMap = [];
foreach ($db->query('SELECT id, name FROM designations')->fetchAll() as $r) {
    $desigMap[strtolower(trim($r['name']))] = (int)$r['id'];
}

// ── Process rows ──────────────────────────────────────────────────────────────
$inserted  = 0;
$updated   = 0;
$skipped   = 0;
$issues    = [];
$usedEmails = []; // placeholder emails generated this batch (keep them unique)

// Resolve the "Employee" role for auto-created logins. The id is not hardcoded
// (it can differ between installs / after a data reset); create the role if it
// is missing so importing never fails on a foreign-key constraint.
$empRoleId = (int)($db->query("SELECT id FROM roles WHERE name = 'Employee' LIMIT 1")->fetchColumn() ?: 0);
if (!$empRoleId) {
    $db->prepare("INSERT INTO roles (name, description, self_scope) VALUES ('Employee', 'Self-service employee login', 1)")
       ->execute();
    $empRoleId = (int)$db->lastInsertId();
}

foreach ($rows as $i => $row) {
    $rowNum = $i + 2; // +1 for header, +1 for 1-based display

    // Normalize keys
    $r = [];
    foreach ($row as $k => $v) {
        $key      = strtolower(trim(str_replace([' ', '-'], '_', (string)$k)));
        $r[$key]  = trim((string)$v);
    }

    $name  = $r['name']  ?? $r['full_name']     ?? '';
    $email = $r['email'] ?? $r['email_address'] ?? '';

    // Only name is mandatory. Email is optional (many rosters omit it); a unique
    // placeholder is generated below so the NOT-NULL UNIQUE column stays valid.
    if ($name === '') {
        $skipped++;
        $issues[] = "Row $rowNum: 'name' is required — skipped.";
        continue;
    }
    $emailProvided = ($email !== '' && strpos($email, '@') !== false);

    $empCode   = $r['employee_id']  ?? $r['emp_id'] ?? $r['empcode'] ?? $r['emp_code'] ?? '';
    $phone     = $r['phone']        ?? $r['mobile'] ?? '';
    $gender    = $r['gender']       ?? '';
    $address   = $r['address']      ?? '';
    $dob       = _norm_date($r['dob'] ?? $r['date_of_birth'] ?? '');
    $joinDate  = _norm_date($r['join_date'] ?? $r['joining_date'] ?? $r['date_of_joining'] ?? $r['doj'] ?? '');

    $deptKey   = strtolower(trim($r['department'] ?? $r['department_name'] ?? ''));
    $desigKey  = strtolower(trim($r['designation'] ?? $r['designation_name'] ?? ''));
    $dept_id   = $deptKey  ? ($deptMap[$deptKey]  ?? null) : null;
    $desig_id  = $desigKey ? ($desigMap[$desigKey] ?? null) : null;

    $etype = $r['employment_type'] ?? $r['type'] ?? 'Full Time';
    $validTypes = ['Full Time', 'Part Time', 'Contract', 'Intern'];
    if (!in_array($etype, $validTypes, true)) $etype = 'Full Time';

    $pan   = strtoupper($r['pan_number'] ?? $r['pan']     ?? '');
    $aadh  = $r['aadhaar_number']        ?? $r['aadhaar'] ?? '';
    $uan   = $r['uan_number']            ?? $r['uan']     ?? '';

    // Resolve existing record
    $existingId = null;
    if ($empCode !== '') {
        $chk = $db->prepare('SELECT id FROM employees WHERE employee_id = ? LIMIT 1');
        $chk->execute([$empCode]);
        $existingId = $chk->fetchColumn() ?: null;
    }
    if (!$existingId && $emailProvided) {
        $chk = $db->prepare('SELECT id FROM employees WHERE email = ? LIMIT 1');
        $chk->execute([$email]);
        $existingId = $chk->fetchColumn() ?: null;
    }

    if ($existingId) {
        // ── UPDATE ────────────────────────────────────────────────────────────
        $upd = $db->prepare(
            "UPDATE employees SET
                name=:name, phone=:phone, gender=:gender, dob=:dob, address=:address,
                department_id=:dept_id, designation_id=:desig_id,
                join_date=:jdate, employment_type=:etype,
                pan_number=:pan, aadhaar_number=:aadh, uan_number=:uan,
                updated_at=NOW()
             WHERE id=:id"
        );
        $upd->execute([
            ':name'    => $name,
            ':phone'   => $phone    ?: null,
            ':gender'  => $gender   ?: null,
            ':dob'     => $dob      ?: null,
            ':address' => $address  ?: null,
            ':dept_id' => $dept_id,
            ':desig_id'=> $desig_id,
            ':jdate'   => $joinDate ?: null,
            ':etype'   => $etype,
            ':pan'     => $pan      ?: null,
            ':aadh'    => $aadh     ?: null,
            ':uan'     => $uan      ?: null,
            ':id'      => $existingId,
        ]);
        $updated++;
    } else {
        // ── INSERT ────────────────────────────────────────────────────────────
        $newCode = ($empCode !== '') ? $empCode : generate_employee_id();

        // If caller supplied an employee_id, verify it's not taken
        if ($empCode !== '') {
            $codeChk = $db->prepare('SELECT 1 FROM employees WHERE employee_id = ? LIMIT 1');
            $codeChk->execute([$empCode]);
            if ($codeChk->fetchColumn()) {
                $skipped++;
                $issues[] = "Row $rowNum: Employee ID '$empCode' already exists — skipped.";
                continue;
            }
        }

        // When no email was supplied, synthesise a unique placeholder so the
        // NOT-NULL UNIQUE column is satisfied. Derived from the employee code (or
        // name) and de-duplicated against this batch and existing records.
        if (!$emailProvided) {
            $email = _placeholder_email($db, $newCode !== '' ? $newCode : $name, $usedEmails);
        }
        $usedEmails[strtolower($email)] = true;

        $ins = $db->prepare(
            "INSERT INTO employees
                (employee_id, name, email, phone, gender, dob, address,
                 department_id, designation_id, join_date, employment_type,
                 pan_number, aadhaar_number, uan_number,
                 status, created_at)
             VALUES
                (:emp_id, :name, :email, :phone, :gender, :dob, :address,
                 :dept_id, :desig_id, :jdate, :etype,
                 :pan, :aadh, :uan,
                 'Active', NOW())"
        );
        $ins->execute([
            ':emp_id'  => $newCode,
            ':name'    => $name,
            ':email'   => $email,
            ':phone'   => $phone    ?: null,
            ':gender'  => $gender   ?: null,
            ':dob'     => $dob      ?: null,
            ':address' => $address  ?: null,
            ':dept_id' => $dept_id,
            ':desig_id'=> $desig_id,
            ':jdate'   => $joinDate ?: null,
            ':etype'   => $etype,
            ':pan'     => $pan      ?: null,
            ':aadh'    => $aadh     ?: null,
            ':uan'     => $uan      ?: null,
        ]);
        $newId = (int)$db->lastInsertId();

        // Auto-create a login account only when a real email was supplied
        // (Employee role, resolved above). Placeholder addresses can't log in.
        $uChk = $db->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $uChk->execute([$email]);
        if ($emailProvided && !$uChk->fetchColumn()) {
            $tmpPass = password_hash('Hrms@' . substr($newCode, -4), PASSWORD_BCRYPT);
            $db->prepare(
                'INSERT INTO users (email, name, password_hash, role_id, employee_id, is_active)
                 VALUES (:email, :name, :pass, :role, :emp_id, 1)'
            )->execute([
                ':email'  => $email,
                ':name'   => $name,
                ':pass'   => $tmpPass,
                ':role'   => $empRoleId,
                ':emp_id' => $newId,
            ]);
        }
        $inserted++;
    }
}

// ── Flash result ──────────────────────────────────────────────────────────────
$summary = "Import complete: $inserted inserted, $updated updated, $skipped skipped.";
if ($issues) {
    $summary .= ' Issues: ' . implode(' | ', array_slice($issues, 0, 5));
    if (count($issues) > 5) $summary .= ' (and ' . (count($issues) - 5) . ' more)';
}

$flashType = ($inserted + $updated === 0) ? 'error' : ($skipped > 0 ? 'warn' : 'success');
flash($flashType, $summary);
redirect(BASE_URL . '/modules/employee/index.php');

// ── File parsers ──────────────────────────────────────────────────────────────

function _parse_csv(string $path): array
{
    $rows    = [];
    $headers = null;
    $fh = fopen($path, 'r');
    if ($fh === false) return [];
    while (($line = fgetcsv($fh, 0, ',')) !== false) {
        if ($headers === null) { $headers = $line; continue; }
        if (count($line) < count($headers)) {
            $line = array_pad($line, count($headers), '');
        }
        $rows[] = array_combine($headers, array_slice($line, 0, count($headers)));
    }
    fclose($fh);
    return $rows;
}

function _parse_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) return [];

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    // Shared strings
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = simplexml_load_string($ssXml);
        foreach ($ss->si as $si) {
            if (isset($si->t)) {
                $sharedStrings[] = (string)$si->t;
            } else {
                $text = '';
                foreach ($si->r as $rr) { $text .= (string)$rr->t; }
                $sharedStrings[] = $text;
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
            $colIdx     = _xlsx_col_index((string)$c['r']);
            $v          = isset($c->v) ? (string)$c->v : '';
            if ((string)$c['t'] === 's') $v = $sharedStrings[(int)$v] ?? '';
            $cells[$colIdx] = $v;
        }
        if (!$cells) continue;
        $maxCol = max(array_keys($cells));
        $row    = [];
        for ($i = 0; $i <= $maxCol; $i++) $row[] = $cells[$i] ?? '';
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

function _xlsx_col_index(string $cellRef): int
{
    preg_match('/^([A-Z]+)/i', $cellRef, $m);
    $col = strtoupper($m[1] ?? 'A');
    $n   = 0;
    for ($i = 0, $len = strlen($col); $i < $len; $i++) {
        $n = $n * 26 + (ord($col[$i]) - 64);
    }
    return $n - 1;
}

function _norm_date(string $val): string
{
    $val = trim($val);
    if ($val === '') return '';

    // Excel stores dates as a serial number (days since 1899-12-30).
    if (ctype_digit($val) && (int)$val >= 20000 && (int)$val <= 80000) {
        return gmdate('Y-m-d', ((int)$val - 25569) * 86400);
    }

    // dd/mm/yyyy or dd-mm-yyyy (the common Indian/Excel export format), with a
    // fallback to mm/dd when the first part can't be a day.
    if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})$#', $val, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
        if ($y < 100) $y += 2000;
        if ($mo > 12 && $d <= 12) { $t = $d; $d = $mo; $mo = $t; }
        if (checkdate($mo, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    $ts = strtotime($val);
    return $ts !== false ? date('Y-m-d', $ts) : '';
}

/**
 * Build a unique placeholder email for a row that has no address of its own,
 * derived from the employee code (or name). De-duplicated against this import
 * batch and the existing employees so the NOT-NULL UNIQUE column stays valid.
 */
function _placeholder_email(PDO $db, string $base, array $used): string
{
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '.', $base), '.'));
    if ($slug === '') $slug = 'employee';

    $chk = $db->prepare('SELECT 1 FROM employees WHERE email = ? LIMIT 1');
    $n = 1;
    do {
        $candidate = $slug . ($n > 1 ? '.' . $n : '') . '@noemail.local';
        $n++;
        if (isset($used[strtolower($candidate)])) continue;
        $chk->execute([$candidate]);
    } while (isset($used[strtolower($candidate)]) || $chk->fetchColumn());

    return $candidate;
}
