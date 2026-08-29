<?php
/**
 * MagDyn HRMS — General Helpers
 */

function redirect(string $url): void {
    // Several pages include header.php BEFORE their require_permission() call
    // (dashboard index.php:18-19 among them). header.php emits ~43KB, which
    // overruns output_buffering, so the headers are already on the wire by the
    // time a guard fires — header('Location:') is then a no-op and the visitor
    // simply keeps the page they should have been redirected away from.
    //
    // This silently defeated BOTH the forced password change (audit H-3) and
    // the session-timeout redirect. Falling back to a client-side redirect
    // makes the navigation happen either way.
    if (!headers_sent()) {
        header('Location: ' . $url);
    } else {
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
        echo '<script>location.replace(' . json_encode($url) . ');</script>';
    }
    exit;
}

/**
 * Add a column to a table if it is not there yet, and report whether the table
 * now has it. Idempotent and safe to call on every relevant request.
 *
 * The project has no migration runner — schema changes ship as lazy DDL at the
 * point of use (see the login_attempts table in login.php). This helper gives
 * the same treatment to added COLUMNS, so a change like users.must_change_password
 * (security audit H-3) reaches existing installs instead of only fresh ones.
 *
 * Result is cached per request; a failed ALTER (e.g. a read-only DB user) returns
 * false so callers can degrade instead of fataling.
 */
/**
 * Statuses that mean the employee actually PUNCHED IN, and therefore need a
 * punch-out before the day counts as worked (security audit M-18).
 *
 * The no-checkout rule listed only On Time and Late, so it converted those to
 * Absent while leaving Half Day alone. Because status is classified by check-in
 * time FIRST, that inverted pay fairness: a 09:00 arrival with no punch-out was
 * On Time -> Absent and paid nothing, while an 11:30 arrival with no punch-out
 * stayed Half Day and was paid half. The later you turned up, the more you got.
 *
 * All three are check-in-derived and are treated identically. OD, Comp Off,
 * On Leave and Holiday are deliberately excluded: no punch is expected on those.
 *
 * Single source of truth — the write paths (attendance/mark.php,
 * includes/attendance_resync.php) and the counting paths (attendance/index.php,
 * attendance/report.php) all read this, so the rule cannot drift between the
 * status that is stored and the status that is displayed.
 */
function attendance_checkin_statuses(): array {
    return ['On Time', 'Late', 'Half Day'];
}

function db_ensure_column(string $table, string $column, string $definition): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $s = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $s->execute([$table, $column]);
        if ((int)$s->fetchColumn() > 0) return $cache[$key] = true;
        // Identifiers cannot be bound — they are caller-supplied literals, never
        // request input, but validate anyway so this can never become an injection.
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return $cache[$key] = false;
        }
        db()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        return $cache[$key] = true;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

/**
 * URL for a file under uploads/ or storage/, routed through the authenticated
 * gateway (file.php). Those directories deny direct web access, so this is the
 * only way to link one — see the header of file.php (security audit H-2).
 *
 * $relPath is project-relative, exactly as stored in the DB, e.g.
 *   uploads/employee_docs/12/doc_abc.pdf   |   storage/entities/sign_x.jpeg
 *
 * The result is already URL-encoded; do NOT wrap it in h() inside an attribute
 * beyond normal escaping of the surrounding markup.
 */
function file_url(string $relPath, bool $download = false): string {
    $rel = ltrim(str_replace(chr(92), '/', $relPath), '/');
    return BASE_URL . '/file.php?p=' . rawurlencode($rel) . ($download ? '&dl=1' : '');
}

/**
 * Keep uploads/ and storage/ closed to direct web access.
 *
 * Both directories hold employee PII (documents, photos, leave attachments) and
 * the authorised-signatory signature images. They are served ONLY through
 * file.php, which applies the owning module's permission plus
 * require_own_employee() — see the header of file.php (security audit H-2).
 *
 * This is written at runtime rather than shipped as a file because the
 * deployment module cannot install it: uploads/ and storage/ are on
 * deploy_protected_paths(), so a delta package silently skips them and the fix
 * would arrive half-applied on every deployed server. The deployer hardens its
 * own storage area the same way — see deploy_harden_dir().
 *
 * Self-healing: the marker line is what is checked, so a file that is missing,
 * truncated, or reverted to the old execute-only rules is rewritten.
 */
function hrms_harden_data_dirs(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $marker = '# hrms-data-dir-hardening v1';
    $body = $marker . "\n"
        . "# Direct web access is DENIED. These files are employee PII and signature\n"
        . "# images; every read goes through /file.php, which checks the permission and\n"
        . "# the record's owner (security audit H-2).\n"
        . "# Managed by hrms_harden_data_dirs() in includes/helpers.php — edits are\n"
        . "# overwritten on the next request.\n"
        . "<IfModule mod_authz_core.c>\n"
        . "    Require all denied\n"
        . "</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "    Order allow,deny\n"
        . "    Deny from all\n"
        . "</IfModule>\n"
        . "Options -Indexes\n"
        . "# Belt and braces: never executable, even if the deny above is overridden.\n"
        . "php_flag engine off\n"
        . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar\n"
        . "RemoveType .php .phtml .php3 .php4 .php5 .php7 .phar\n";

    foreach (['uploads', 'storage'] as $dir) {
        $path = BASE_PATH . '/' . $dir;
        if (!is_dir($path)) continue;
        $ht = $path . '/.htaccess';
        if (is_file($ht) && strpos((string) @file_get_contents($ht), $marker) !== false) continue;
        @file_put_contents($ht, $body);
        // For servers that ignore .htaccess entirely (IIS, or AllowOverride None).
        if (!is_file($path . '/web.config')) {
            @file_put_contents($path . '/web.config',
                "<?xml version=\"1.0\"?>\n<configuration><system.webServer><security>"
              . "<authorization><deny users=\"*\" /></authorization></security>"
              . "</system.webServer></configuration>\n");
        }
    }
}

function h(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function money(float $amount, bool $symbol = true): string {
    $fmt = number_format($amount, 2);
    return $symbol ? PAYROLL_CURRENCY_SYMBOL . $fmt : $fmt;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = compact('type', 'message');
}

function get_flash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function render_flash(): string {
    $f = get_flash();
    if (!$f) return '';
    $map = ['success' => 'alert-success', 'error' => 'alert-error', 'warn' => 'alert-warn', 'info' => 'alert-info'];
    $cls = $map[$f['type']] ?? 'alert-info';
    return '<div class="alert ' . $cls . '">' . h($f['message']) . '</div>';
}

function date_fmt(?string $date, string $format = 'd M Y'): string {
    if (!$date) return '—';
    try { return (new DateTime($date))->format($format); }
    catch (Exception $e) { return $date; }
}

function age(string $dob): int {
    return (int) (new DateTime($dob))->diff(new DateTime())->y;
}

function tenure(string $joinDate): string {
    $diff = (new DateTime($joinDate))->diff(new DateTime());
    $parts = [];
    if ($diff->y) $parts[] = $diff->y . 'y';
    if ($diff->m) $parts[] = $diff->m . 'm';
    return implode(' ', $parts) ?: 'New';
}

/**
 * Hand out the next value of a named counter — atomically (security audit L-5).
 *
 * Reference numbers were minted with COUNT(*)+1 (letters) and MAX(...)+1
 * (employee codes). Both are read-then-write races: two saves that overlap read
 * the same figure and produce the same reference. COUNT(*) is worse still —
 * deleting any row lowers the count, so the next reference DUPLICATES one that
 * already exists, with no concurrency needed at all.
 *
 * A counter row fixes both. The INSERT ... ON DUPLICATE KEY UPDATE below is a
 * single statement, so MySQL serialises it; LAST_INSERT_ID(expr) both stores and
 * returns the new value, so the number this connection gets is its own and no
 * other request can be handed the same one. The counter only ever goes up, so
 * deleting rows can never rewind it.
 *
 * $seed supplies the starting point the first time a counter is used, so
 * numbering continues from the existing data rather than restarting at 1.
 */
function next_sequence(string $name, ?callable $seed = null): int {
    $db = db();

    // DDL implicitly COMMITs, so never create the table mid-transaction (M-5).
    static $ready = false;
    if (!$ready && !$db->inTransaction()) {
        $db->exec('CREATE TABLE IF NOT EXISTS app_sequences (
            seq_name VARCHAR(64) NOT NULL PRIMARY KEY,
            next_val BIGINT UNSIGNED NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $ready = true;
    }

    $start = $seed ? (int) $seed() : 0;
    $st = $db->prepare(
        'INSERT INTO app_sequences (seq_name, next_val) VALUES (:n, LAST_INSERT_ID(:s + 1))
         ON DUPLICATE KEY UPDATE next_val = LAST_INSERT_ID(next_val + 1)'
    );
    $st->execute([':n' => $name, ':s' => $start]);
    return (int) $db->lastInsertId();
}

/**
 * The next letter reference, e.g. HR/I/2026/0014 (security audit L-5).
 *
 * Both modules/letters/create.php and modules/increments/save.php minted these
 * with COUNT(*)+1 against the same table, so they could collide with each other
 * as well as with themselves. The numeric part is a single company-wide series,
 * matching how the existing references read.
 */
/**
 * Add a UNIQUE index if it is missing (security audit L-5).
 *
 * Same lazy pattern as db_ensure_column(): this project has no migration runner,
 * so schema catches up on first use. Fails quietly — on a database that already
 * holds duplicates the ALTER cannot succeed, and refusing to load the page over
 * it would be worse than running without the index. The generator no longer
 * produces duplicates either way; this is the backstop for anything that
 * bypasses it.
 */
/**
 * The one definition of employee_benefits (security audit L-7 follow-up).
 *
 * modules/benefits/index.php and modules/benefits/save.php each carried their own
 * CREATE TABLE IF NOT EXISTS, and they DISAGREED — 8 columns versus 16. Whichever
 * page a user opened first on a fresh install won permanently, because the second
 * CREATE is then a no-op. When the list page won, the table had no start_date,
 * end_date, frequency or payment_mode, so payroll's benefits query failed with
 * "Unknown column" inside a catch-all and NO benefit was ever paid, on any slip,
 * with nothing on screen to say so.
 *
 * One definition now, plus a column backfill so an install that already got the
 * stunted table repairs itself rather than staying broken for ever.
 */
function benefits_table_ready(): void
{
    static $done = false;
    if ($done) return;
    // DDL implicitly COMMITs — never run it inside someone's transaction (M-5).
    if (db()->inTransaction()) return;
    $done = true;

    // Heredoc: the ENUM lists are full of quotes, and escaping them inside a
    // PHP string literal is exactly how the two definitions drifted apart.
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS employee_benefits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    benefit_fund_type_id INT NULL,
    frequency ENUM('weekly','fortnightly','monthly','quarterly','half_yearly','annual') NOT NULL DEFAULT 'monthly',
    start_date DATE NULL,
    end_date DATE NULL,
    benefit_name VARCHAR(255) NULL,
    fund_type VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    effective_month DATE NOT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    payment_mode ENUM('cash','cashless') NOT NULL DEFAULT 'cash',
    added_by INT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
    db()->exec($sql);

    // Repair a table created by the old 8-column definition in benefits/index.php.
    db_ensure_column('employee_benefits', 'benefit_fund_type_id', 'INT NULL');
    db_ensure_column('employee_benefits', 'frequency',
        "ENUM('weekly','fortnightly','monthly','quarterly','half_yearly','annual') NOT NULL DEFAULT 'monthly'");
    db_ensure_column('employee_benefits', 'start_date',   'DATE NULL');
    db_ensure_column('employee_benefits', 'end_date',     'DATE NULL');
    db_ensure_column('employee_benefits', 'benefit_name', 'VARCHAR(255) NULL');
    db_ensure_column('employee_benefits', 'payment_mode', "ENUM('cash','cashless') NOT NULL DEFAULT 'cash'");
    db_ensure_column('employee_benefits', 'added_by',     'INT NULL');
}

function db_ensure_unique_index(string $table, string $index, string $columns): bool {
    static $done = [];
    $key = $table . '.' . $index;
    if (isset($done[$key])) return $done[$key];
    try {
        $db = db();
        if ($db->inTransaction()) return false;          // DDL implicitly COMMITs (M-5)
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $index)) {
            return $done[$key] = false;
        }
        $s = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $s->execute([$table, $index]);
        if ((int) $s->fetchColumn() > 0) return $done[$key] = true;
        $db->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$index` ($columns)");
        return $done[$key] = true;
    } catch (Throwable $e) {
        error_log("db_ensure_unique_index($key) skipped: " . $e->getMessage());
        return $done[$key] = false;
    }
}

function next_letter_reference(string $typeCode): string {
    db_ensure_unique_index('letters', 'uk_letters_reference', '`reference`');
    $n = next_sequence('letter_reference', static function (): int {
        // Continue from the highest number already issued, whatever its prefix.
        return (int) db()->query(
            'SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(reference, "/", -1) AS UNSIGNED)), 0) FROM letters'
        )->fetchColumn();
    });
    return 'HR/' . $typeCode . '/' . date('Y') . '/' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/**
 * Auto-generated employee code (security audit L-5).
 *
 * MAX(...)+1 was a read-then-write race, and deleting the highest-numbered
 * employee made the next code REUSE a retired one — which then attaches new
 * records to an identifier a former employee's history already used. The counter
 * is seeded from the same MAX so the first code issued is unchanged, and only
 * ever climbs from there.
 *
 * employees.employee_id is UNIQUE, so a collision would have failed loudly
 * rather than silently duplicated — but it would still have failed the save.
 */
function generate_employee_id(): string {
    $n = next_sequence('employee_code', static function (): int {
        return (int) db()->query(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(employee_id, 4) AS UNSIGNED)), 0) FROM employees'
        )->fetchColumn();
    });
    return 'EMP' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

function sanitize(mixed $input): string {
    return trim(strip_tags((string)$input));
}

/**
 * Blood groups offered in the employee form and accepted on save. A whitelist,
 * so a hand-crafted POST cannot store arbitrary text in the column that the
 * ID card prints on its reverse.
 */
function blood_group_options(): array {
    return ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
}

/** Normalise a submitted blood group to a whitelisted value, else null. */
function blood_group_clean(?string $value): ?string {
    $v = strtoupper(trim((string)$value));
    return in_array($v, blood_group_options(), true) ? $v : null;
}

function paginate(int $total, int $perPage, int $current): array {
    $pages = (int) ceil($total / $perPage);
    return ['total' => $total, 'per_page' => $perPage, 'current' => $current, 'pages' => $pages,
            'offset' => ($current - 1) * $perPage];
}

/** MIME → extension for ordinary uploads (photos, logos, scans). */
function upload_safe_ext_map(): array {
    return [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
    ];
}

/**
 * MIME → extension for EMPLOYEE DOCUMENTS, which legitimately include Word files
 * (security audit L-2).
 *
 * finfo reports a modern .docx by its real Open-XML type. A legacy .doc is an
 * OLE2 compound file, which most magic databases report as application/CDFV2 — a
 * container shared with .xls and .ppt, so accepting it means accepting any OLE2
 * document rather than specifically a Word one. That is still far stronger than
 * trusting the filename, but it is the loosest entry here: delete the two legacy
 * rows to refuse .doc outright.
 */
function upload_document_ext_map(): array {
    return upload_safe_ext_map() + [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc',
        'application/CDFV2'  => 'doc',
    ];
}

/**
 * Store an uploaded file safely.
 *
 * $extMap   — MIME → extension allowlist; defaults to upload_safe_ext_map().
 * $maxBytes — size cap; defaults to UPLOAD_MAX_MB.
 * Both are optional, so existing callers are unaffected (security audit L-2).
 */
function upload_file(array $file, string $dest, string $prefix = '',
                     ?array $extMap = null, ?int $maxBytes = null): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;

    // The temp path must be one PHP itself received. move_uploaded_file() checks
    // this too, but doing it up front means nothing in between can be pointed at
    // an arbitrary server path by a forged $_FILES array.
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;

    $cap = $maxBytes ?? (UPLOAD_MAX_MB * 1024 * 1024);
    if (($file['size'] ?? 0) > $cap) return null;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    // SECURITY: derive the extension from the VERIFIED MIME type, never from the
    // user-supplied filename — prevents an image-polyglot being saved as .php.
    $extByMime = $extMap ?? upload_safe_ext_map();
    $ext = $extByMime[$mime] ?? null;
    if ($ext === null) return null;                       // MIME not in the safe map

    $name = $prefix . uniqid() . '.' . $ext;
    $path = rtrim($dest, '/') . '/' . $name;
    if (!is_dir($dest)) mkdir($dest, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $path)) return null;
    return $name;
}

function active_if(string $module): string {
    $current = basename($_SERVER['PHP_SELF'], '.php');
    return (strpos($current, $module) !== false) ? 'active' : '';
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): bool {
    return hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '');
}

/**
 * Verify CSRF token and abort with redirect on failure.
 * Use at the top of every POST-handling script:
 *   verify_csrf($_POST['csrf_token'] ?? '');
 */
function verify_csrf(string $token = ''): void {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        flash('error', 'Invalid or expired request. Please try again.');
        $back = $_SERVER['HTTP_REFERER'] ?? BASE_URL . '/index.php';
        redirect($back);
    }
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/* ═══════════════════════════════════════════════════════════════════════════
   APP SETTINGS (key/value) — mirrors Laravel App\Helpers\AppSettings
   Backed by the `app_settings` table.  In-request static cache avoids repeat hits.
   ═══════════════════════════════════════════════════════════════════════════ */

$GLOBALS['__settings_cache'] = [];

function setting_get(string $key, mixed $default = null): mixed {
    if (!array_key_exists($key, $GLOBALS['__settings_cache'])) {
        try {
            $st = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
            $st->execute([$key]);
            $val = $st->fetchColumn();
            $GLOBALS['__settings_cache'][$key] = ($val === false) ? null : $val;
        } catch (Throwable $e) {
            $GLOBALS['__settings_cache'][$key] = null;   // table may not exist yet
        }
    }
    $v = $GLOBALS['__settings_cache'][$key];
    return ($v !== null && $v !== '') ? $v : $default;
}

function setting_set(string $key, mixed $value): void {
    $st = db()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $st->execute([$key, (string)$value]);
    $GLOBALS['__settings_cache'][$key] = (string)$value;
}

function settings_flush(): void {
    $GLOBALS['__settings_cache'] = [];
}

/**
 * True when an employee has a salary configured (CTC on the master, or a salary
 * structure with gross > 0, or a recorded increment). Used to block loans,
 * benefits and salary-slip generation for employees with no salary set.
 */
function employee_has_salary(int $empId): bool {
    if ($empId <= 0) return false;
    try {
        $st = db()->prepare('SELECT COALESCE(fixed_salary,0) + COALESCE(variable_salary,0) FROM employees WHERE id = ?');
        $st->execute([$empId]);
        if ((float)($st->fetchColumn() ?: 0) > 0) return true;

        $ss = db()->prepare('SELECT 1 FROM salary_structures WHERE employee_id = ? AND gross > 0 LIMIT 1');
        $ss->execute([$empId]);
        if ($ss->fetchColumn()) return true;

        $inc = db()->prepare('SELECT 1 FROM employee_increments WHERE employee_id = ? AND new_salary > 0 LIMIT 1');
        $inc->execute([$empId]);
        return (bool) $inc->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   NON-WORKING DAYS
   The company works Mon–Sat except Sundays and the 1st/3rd Saturday, plus any
   configured holiday. These are the canonical rules; the monthly attendance
   report and the employee QR portal both resolve "is this an absence?" through
   them, so a day can never count as Absent on one screen and Week Off on the
   other.
   ═══════════════════════════════════════════════════════════════════════════ */

/** Count the Saturdays from the 1st of the month up to and including $d. */
function saturday_index_in_month(DateTime $d): int {
    $n = 0;
    $t = (clone $d)->modify('first day of this month');
    while ($t <= $d) {
        if ((int)$t->format('N') === 6) $n++;
        $t->modify('+1 day');
    }
    return $n;
}

/** True when $d is the 1st or 3rd Saturday of its month (a non-working Saturday). */
function is_non_working_saturday(DateTime $d): bool {
    if ((int)$d->format('N') !== 6) return false;
    return in_array(saturday_index_in_month($d), [1, 3], true);
}

/**
 * Why $d is a non-working day, or null when it is a normal working day.
 *
 * $holidayMap: 'YYYY-MM-DD' => ['name' => ..., 'is_working_day' => bool], as
 * loaded from the `holidays` table. A holiday flagged is_working_day overrides
 * everything and makes the day workable.
 *
 * Returns a human label ('Sunday', '1st Saturday', or the holiday name).
 */
function non_working_day_reason(DateTime $d, array $holidayMap = []): ?string {
    $key = $d->format('Y-m-d');
    $hol = $holidayMap[$key] ?? null;

    if ($hol && !empty($hol['is_working_day'])) return null;   // working holiday

    if ((int)$d->format('N') === 7)  return 'Sunday';
    if (is_non_working_saturday($d)) return (saturday_index_in_month($d) === 1 ? '1st' : '3rd') . ' Saturday';
    if ($hol)                        return (string) ($hol['name'] ?? 'Holiday');

    return null;
}

/** Convert "HH:MM" (or "HH:MM:SS") to minutes-since-midnight. */
function time_to_mins(string $time): int {
    $p = explode(':', $time);
    return (int)($p[0] ?? 0) * 60 + (int)($p[1] ?? 0);
}

/** Whole-rupee amount → words using the Indian numbering system (Lakh/Crore). */
if (!function_exists('_inr_words')) {
    function _inr_words(float $num): string {
        $num = (int) round($num);
        if ($num <= 0) return 'Zero';
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
                 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $two  = function (int $n) use ($ones, $tens): string {
            return $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
        };
        $three = function (int $n) use ($ones, $two): string {
            $hd = intdiv($n, 100); $r = $n % 100;
            return trim(($hd ? $ones[$hd] . ' Hundred ' : '') . ($r ? $two($r) : ''));
        };
        $parts = [];
        $crore = intdiv($num, 10000000); $num %= 10000000;
        $lakh  = intdiv($num, 100000);   $num %= 100000;
        $thou  = intdiv($num, 1000);     $num %= 1000;
        if ($crore) $parts[] = $three($crore) . ' Crore';
        if ($lakh)  $parts[] = $three($lakh)  . ' Lakh';
        if ($thou)  $parts[] = $three($thou)  . ' Thousand';
        if ($num)   $parts[] = $three($num);
        return trim(implode(' ', $parts));
    }
}

// ── Grace / Late permission ──────────────────────────────────────────────────
function setting_daily_grace_mins(): int   { return (int) setting_get('daily_grace_minutes', 15); }
function setting_monthly_grace_mins(): int { return (int) setting_get('monthly_grace_minutes', 90); }

// ── Office hours ──────────────────────────────────────────────────────────────
function setting_office_start(): string    { return (string) setting_get('office_start_time', '09:00'); }
function setting_office_start_mins(): int   { return time_to_mins(setting_office_start()); }
function setting_office_end(): string       { return (string) setting_get('office_end_time', defined('WORK_END_TIME') ? WORK_END_TIME : '18:00'); }
function setting_office_end_mins(): int      { return time_to_mins(setting_office_end()); }

// ── OT timing ─────────────────────────────────────────────────────────────────
function setting_ot_trigger(): string      { return (string) setting_get('ot_trigger_time', '20:30'); }
function setting_ot_baseline(): string      { return (string) setting_get('ot_baseline_time', '18:15'); }
function setting_ot_trigger_mins(): int     { return time_to_mins(setting_ot_trigger()); }
function setting_ot_baseline_mins(): int    { return time_to_mins(setting_ot_baseline()); }

// ── Break windows (lunch + 2 tea breaks) ──────────────────────────────────────
// Each break is a clock window. Only the portion of a break that falls within an
// employee's [check-in, check-out] presence is deducted from worked hours, so an
// employee who leaves before a break never has that break subtracted. Lunch is
// per-employee via lunch batches (Settings → Break Settings); the 2 tea breaks
// are global.

/** Default lunch window (mins) — used when an employee has no batch assigned. */
function default_lunch_window(): array {
    return [
        'name'  => 'Lunch',
        'start' => time_to_mins((string) setting_get('lunch_start', '13:00')),
        'end'   => time_to_mins((string) setting_get('lunch_end',   '13:30')),
    ];
}

/** The two global tea-break windows (mins). */
function tea_break_windows(): array {
    $defs = [
        ['Tea Break 1', 'tea1_start', '11:00', 'tea1_end', '11:15'],
        ['Tea Break 2', 'tea2_start', '16:00', 'tea2_end', '16:15'],
    ];
    $out = [];
    foreach ($defs as [$name, $sk, $sd, $ek, $ed]) {
        $s = time_to_mins((string) setting_get($sk, $sd));
        $e = time_to_mins((string) setting_get($ek, $ed));
        if ($e > $s) $out[] = ['name' => $name, 'start' => $s, 'end' => $e];
    }
    return $out;
}

/** Lunch window (mins) for an employee — their assigned batch, else the default. */
function employee_lunch_window(int $empId): array {
    static $cache = [];
    if (array_key_exists($empId, $cache)) return $cache[$empId];
    $cache[$empId] = _employee_lunch_window_resolve($empId);
    return $cache[$empId];
}
function _employee_lunch_window_resolve(int $empId): array {
    if ($empId > 0) {
        try {
            $st = db()->prepare(
                'SELECT b.name, b.start_time, b.end_time
                   FROM employees e JOIN lunch_batches b ON b.id = e.lunch_batch_id
                  WHERE e.id = ? LIMIT 1'
            );
            $st->execute([$empId]);
            if ($r = $st->fetch()) {
                return [
                    'name'  => (string) $r['name'],
                    'start' => time_to_mins((string) $r['start_time']),
                    'end'   => time_to_mins((string) $r['end_time']),
                ];
            }
        } catch (Throwable $e) { /* lunch_batches absent — fall through */ }
    }
    return default_lunch_window();
}

// ─── Shift helpers (per-shift timing) ─────────────────────────────────────────
// Phase 0: defined for attendance (Phase 2) and payroll (Phase 3) to consume.
// Each degrades gracefully if the shift tables are absent, so an install that has
// not yet run add_shift_system.sql keeps working on the legacy global settings.

/** Fetch a shift row by id (cached per request). Returns [] if not found. */
function get_shift(int $shiftId): array {
    static $cache = [];
    if ($shiftId <= 0) return [];
    if (array_key_exists($shiftId, $cache)) return $cache[$shiftId];
    $row = [];
    try {
        $st = db()->prepare('SELECT * FROM shifts WHERE id = ? LIMIT 1');
        $st->execute([$shiftId]);
        $row = $st->fetch() ?: [];
    } catch (Throwable $e) { /* shifts table absent */ }
    return $cache[$shiftId] = $row;
}

/**
 * The shift row that applies to an employee (via employees.shift_id). Falls back
 * to the General shift, then to a hardcoded set mirroring the legacy global
 * settings — so callers always receive a usable timing set.
 */
function employee_shift(int $empId): array {
    static $cache = [];
    if (array_key_exists($empId, $cache)) return $cache[$empId];
    $shift = [];
    try {
        $st = db()->prepare(
            'SELECT s.* FROM employees e JOIN shifts s ON s.id = e.shift_id WHERE e.id = ? LIMIT 1'
        );
        $st->execute([$empId]);
        $shift = $st->fetch() ?: [];
    } catch (Throwable $e) { /* tables absent */ }
    if (!$shift) {
        try {
            $g = db()->query("SELECT * FROM shifts WHERE name = 'General' LIMIT 1")->fetch();
            if ($g) $shift = $g;
        } catch (Throwable $e) { /* ignore */ }
    }
    if (!$shift) {
        $shift = [
            'id' => 0, 'name' => 'General',
            'start_time' => setting_office_start(), 'end_time' => setting_office_end(),
            'daily_grace_mins' => setting_daily_grace_mins(),
            'monthly_grace_mins' => setting_monthly_grace_mins(),
            'ot_enabled' => 1,
            'ot_trigger_time' => setting_ot_trigger(), 'ot_baseline_time' => setting_ot_baseline(),
            'half_day_cutoff' => null, 'lunch_start' => null, 'lunch_end' => null,
        ];
    }
    return $cache[$empId] = $shift;
}

/** Break windows (mins) for a shift — the per-shift replacement for tea_break_windows(). */
function shift_break_windows(int $shiftId): array {
    static $cache = [];
    if (array_key_exists($shiftId, $cache)) return $cache[$shiftId];
    $out = [];
    try {
        $st = db()->prepare('SELECT name, start_time, end_time FROM shift_breaks WHERE shift_id = ? ORDER BY start_time');
        $st->execute([$shiftId]);
        foreach ($st->fetchAll() as $r) {
            $s = time_to_mins((string)$r['start_time']);
            $e = time_to_mins((string)$r['end_time']);
            if ($e > $s) $out[] = ['name' => (string)$r['name'], 'start' => $s, 'end' => $e];
        }
    } catch (Throwable $e) { /* table absent */ }
    return $cache[$shiftId] = $out;
}

/**
 * Lunch window (mins) for an employee under the shift model:
 *   1. their assigned lunch batch (must belong to their shift), else
 *   2. the shift's default lunch window (shifts.lunch_start/end), else
 *   3. null — no lunch (e.g. straight Morning/Evening shifts).
 */
function shift_lunch_window(int $empId): ?array {
    static $cache = [];
    if (array_key_exists($empId, $cache)) return $cache[$empId];
    $win = null;
    try {
        $st = db()->prepare(
            'SELECT b.name, b.start_time, b.end_time
               FROM employees e JOIN lunch_batches b ON b.id = e.lunch_batch_id
              WHERE e.id = ? AND (b.shift_id IS NULL OR b.shift_id = e.shift_id) LIMIT 1'
        );
        $st->execute([$empId]);
        if ($r = $st->fetch()) {
            $win = ['name' => (string)$r['name'], 'start' => time_to_mins((string)$r['start_time']), 'end' => time_to_mins((string)$r['end_time'])];
        }
    } catch (Throwable $e) { /* tables absent */ }
    if ($win === null) {
        $shift = employee_shift($empId);
        if (!empty($shift['lunch_start']) && !empty($shift['lunch_end'])) {
            $s = time_to_mins((string)$shift['lunch_start']);
            $e = time_to_mins((string)$shift['lunch_end']);
            if ($e > $s) $win = ['name' => 'Lunch', 'start' => $s, 'end' => $e];
        }
    }
    return $cache[$empId] = $win;
}

/**
 * The shift an employee is on for a GIVEN DATE — the rotation resolver.
 *
 * Looks in employee_shift_schedule for a row covering $date (latest start_date
 * wins if ranges overlap); falls back to the employee's standing shift
 * (employees.shift_id) when they have no schedule, which is the case for every
 * non-rotating employee. Passing $date = null skips the schedule entirely.
 *
 * This is what makes back-dated marking and biometric imports correct: a day
 * imported next week is still judged by the shift in force on the day worked.
 */
function employee_shift_on(int $empId, ?string $date = null): array {
    static $cache = [];
    if ($date === null) return employee_shift($empId);
    $key = $empId . '|' . $date;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $shift = [];
    try {
        $st = db()->prepare(
            'SELECT shift_id FROM employee_shift_schedule
              WHERE employee_id = ?
                AND start_date <= ?
                AND (end_date IS NULL OR end_date >= ?)
              ORDER BY start_date DESC, id DESC LIMIT 1'
        );
        $st->execute([$empId, $date, $date]);
        $sid = $st->fetchColumn();
        if ($sid) $shift = get_shift((int)$sid);
    } catch (Throwable $e) { /* schedule table absent — no rotation configured */ }

    return $cache[$key] = ($shift ?: employee_shift($empId));
}

/**
 * Attendance-classification timing for one employee, resolved from their shift
 * (falls back to the legacy global settings when they have none). Cached via
 * employee_shift(). Used by mark.php and the daily/monthly importers so the
 * late/half-day/OT thresholds live in exactly one place.
 *
 * $date (optional): resolve the shift AS OF that attendance date, honouring any
 * rotation schedule. Omit it to use the employee's current standing shift.
 *
 * Returns:
 *   shift_id      ?int  — null on legacy fallback
 *   start_mins    int   — shift start
 *   late_thresh   int   — start + daily grace (check-in AFTER this = Late)
 *   half_cutoff   int   — check-in AT/after this = Half Day
 *   monthly_grace int
 *   shift_ot_on   bool  — false = this shift never earns OT
 *   ot_trigger    ?int  — mins; null when the shift has no OT times
 *   ot_baseline   ?int
 */
function attendance_shift_timing(int $empId, ?string $date = null): array {
    static $cache = [];
    $key = $empId . '|' . ($date ?? '');
    if (array_key_exists($key, $cache)) return $cache[$key];

    // With a date, honour any rotation schedule for that day; without one, the
    // employee's current standing shift.
    $s = employee_shift_on($empId, $date);
    $startMins = time_to_mins(substr((string)$s['start_time'], 0, 5));
    $grace     = (int)($s['daily_grace_mins'] ?? setting_daily_grace_mins());
    $halfCut   = !empty($s['half_day_cutoff'])
                    ? time_to_mins(substr((string)$s['half_day_cutoff'], 0, 5))
                    : $startMins + 120;
    return $cache[$key] = [
        'shift_id'      => !empty($s['id']) ? (int)$s['id'] : null,
        'start_mins'    => $startMins,
        'end_mins'      => time_to_mins(substr((string)$s['end_time'], 0, 5)),
        'daily_grace'   => $grace,
        'late_thresh'   => $startMins + $grace,
        'half_cutoff'   => $halfCut,
        'monthly_grace' => (int)($s['monthly_grace_mins'] ?? setting_monthly_grace_mins()),
        'shift_ot_on'   => !isset($s['ot_enabled']) || (bool)$s['ot_enabled'],
        'ot_trigger'    => !empty($s['ot_trigger_time'])  ? time_to_mins(substr((string)$s['ot_trigger_time'], 0, 5))  : null,
        'ot_baseline'   => !empty($s['ot_baseline_time']) ? time_to_mins(substr((string)$s['ot_baseline_time'], 0, 5)) : null,
    ];
}

/**
 * Timing for ONE attendance row, preferring the shift stamped on that row
 * (attendance.shift_id, frozen at mark time) over the employee's current shift.
 * This is the single resolver shared by payroll and the attendance reports, so
 * a past month is judged identically in both after an employee changes shift.
 *
 * Resolution: row's stamped shift → employee's current shift → legacy globals.
 * Adds to attendance_shift_timing(): 'breaks' (null = legacy global tea breaks)
 * and 'lunch' (null = no lunch), ready to pass to break_minutes_within().
 */
function attendance_row_timing(?int $rowShiftId, int $empId): array {
    $cur = attendance_shift_timing($empId);

    $rowShift = ($rowShiftId !== null && $rowShiftId > 0) ? get_shift($rowShiftId) : [];
    if ($rowShift) {
        $startMins = time_to_mins(substr((string)$rowShift['start_time'], 0, 5));
        $lunch = null;
        if ((int)$rowShift['id'] === ($cur['shift_id'] ?? -1)) {
            // Still the employee's shift → honour their staggered lunch batch.
            $lunch = shift_lunch_window($empId);
        } elseif (!empty($rowShift['lunch_start']) && !empty($rowShift['lunch_end'])) {
            $lunch = ['name'  => 'Lunch',
                      'start' => time_to_mins(substr((string)$rowShift['lunch_start'], 0, 5)),
                      'end'   => time_to_mins(substr((string)$rowShift['lunch_end'], 0, 5))];
        }
        return [
            'shift_id'      => (int)$rowShift['id'],
            'start_mins'    => $startMins,
            'end_mins'      => time_to_mins(substr((string)$rowShift['end_time'], 0, 5)),
            'daily_grace'   => (int)$rowShift['daily_grace_mins'],
            'late_thresh'   => $startMins + (int)$rowShift['daily_grace_mins'],
            'half_cutoff'   => !empty($rowShift['half_day_cutoff'])
                                  ? time_to_mins(substr((string)$rowShift['half_day_cutoff'], 0, 5))
                                  : $startMins + 120,
            'monthly_grace' => (int)$rowShift['monthly_grace_mins'],
            'shift_ot_on'   => (bool)$rowShift['ot_enabled'],
            'ot_trigger'    => !empty($rowShift['ot_trigger_time'])  ? time_to_mins(substr((string)$rowShift['ot_trigger_time'], 0, 5))  : null,
            'ot_baseline'   => !empty($rowShift['ot_baseline_time']) ? time_to_mins(substr((string)$rowShift['ot_baseline_time'], 0, 5)) : null,
            'breaks'        => shift_break_windows((int)$rowShift['id']),
            'lunch'         => $lunch,
        ];
    }

    // Unstamped row (or a stamped shift that has since been deleted).
    if ($cur['shift_id'] !== null) {
        return $cur + [
            'breaks' => shift_break_windows($cur['shift_id']),
            'lunch'  => shift_lunch_window($empId),
        ];
    }
    // Full legacy: global settings + global tea breaks + batch/default lunch.
    return $cur + [
        'breaks' => null,
        'lunch'  => function_exists('employee_lunch_window') ? employee_lunch_window($empId) : null,
    ];
}

/**
 * Classify a worked day by NET worked minutes (presence − breaks) against an
 * 8-hour full day, and compute its salary deduction:
 *   • net ≥ 8h        → 'full'  — no deduction
 *   • 4h ≤ net < 8h   → 'half'  — flat 0.5-day deduction
 *   • net < 4h        → 'short' — deduct the non-working hours (8 − net) at the hourly rate
 * Returns ['worked_h','status','non_working_h','deduction'].
 *
 * NOTE: kept for backward compatibility. Payroll/report now use
 * attendance_classify() below, which also factors in the check-in time.
 */
function attendance_day_calc(int $netMin, float $perDay): array {
    $workedH = round($netMin / 60, 2);
    $perHour = $perDay / 8;
    if ($workedH >= 8) {
        return ['worked_h' => $workedH, 'status' => 'full', 'non_working_h' => 0.0, 'deduction' => 0.0];
    }
    if ($workedH >= 4) {
        return ['worked_h' => $workedH, 'status' => 'half', 'non_working_h' => round(8 - $workedH, 2), 'deduction' => round(0.5 * $perDay, 2)];
    }
    $nonWorking = round(8 - $workedH, 2);
    return ['worked_h' => $workedH, 'status' => 'short', 'non_working_h' => $nonWorking, 'deduction' => round($nonWorking * $perHour, 2)];
}

/**
 * SANDWICH LEAVE lookups for the attendance screens.
 *
 * Delegates to PayrollCalculator so there is exactly ONE definition of the rule —
 * a second copy here would drift from the slip the first time either changed.
 * Results are memoised per request: a month grid asks once per employee row and
 * would otherwise re-run the whole scan every time.
 *
 * @param  int[] $empIds
 * @return array<int, array<int, array{before:string,after:string,dates:string[]}>>
 */
function attendance_sandwich_spans(array $empIds, int $month, int $year): array {
    static $cache = [];

    $empIds = array_values(array_unique(array_map('intval', $empIds)));
    if (!$empIds) return [];

    $key     = $year . '-' . $month;
    $missing = array_values(array_filter($empIds, fn($id) => !isset($cache[$key][$id])));
    if ($missing) {
        require_once __DIR__ . '/PayrollCalculator.php';
        try {
            $bulk = (new PayrollCalculator(db()))->sandwichSpansBulk($missing, $month, $year);
        } catch (Throwable $e) {
            $bulk = [];   // never let a badge break the attendance page
        }
        foreach ($missing as $id) $cache[$key][$id] = $bulk[$id] ?? [];
    }

    $out = [];
    foreach ($empIds as $id) $out[$id] = $cache[$key][$id] ?? [];
    return $out;
}

/**
 * The company-leave days CHARGED as LOP by a sandwich.
 *
 * @return array<int, array<string,bool>> employee id => ['Y-m-d' => true]
 */
function attendance_sandwich_map(array $empIds, int $month, int $year): array {
    $out = [];
    foreach (attendance_sandwich_spans($empIds, $month, $year) as $id => $spans) {
        $out[$id] = [];
        foreach ($spans as $span) {
            foreach ($span['dates'] as $ds) $out[$id][$ds] = true;
        }
    }
    return $out;
}

/**
 * The LEAVE days that CLOSED a sandwich — the working day before and after the
 * offs. This is what the month grids badge: a non-working day is drawn as one
 * cell spanning every employee row, so the charged offs have nowhere to carry a
 * per-employee marker, while the leave days on either side do.
 *
 * Each date maps to the number of company-leave days that leave helped charge,
 * so a tooltip can say how long the sandwich ran.
 *
 * @return array<int, array<string,int>> employee id => ['Y-m-d' => offs charged]
 */
function attendance_sandwich_leave_map(array $empIds, int $month, int $year): array {
    $out = [];
    foreach (attendance_sandwich_spans($empIds, $month, $year) as $id => $spans) {
        $out[$id] = [];
        foreach ($spans as $span) {
            $n = count($span['dates']);
            foreach (['before', 'after'] as $edge) {
                $ds = $span[$edge];
                $out[$id][$ds] = max($out[$id][$ds] ?? 0, $n);
            }
        }
    }
    return $out;
}

/**
 * EVERY date a sandwich occupies — the two leave days that closed it AND the
 * company leaves in between. This is what the attendance report's SL column
 * counts: "leave Sat + off Sun + leave Mon" reads as 3 days, not 1.
 *
 * Deduped, so a leave day that closes two sandwiches at once (absent Fri, off
 * Sat/Sun, absent Mon, holiday Tue, absent Wed) is counted once, not twice.
 *
 * Absences listed here are excluded from the report's A column — a day shown as
 * SL must not also be counted as a plain absence, or the two columns double-count
 * it. A + SL then reconciles exactly with the slip's LOP Days.
 *
 * @return array<int, array<string,bool>> employee id => ['Y-m-d' => true]
 */
function attendance_sandwich_all_dates(array $empIds, int $month, int $year): array {
    $out = [];
    foreach (attendance_sandwich_spans($empIds, $month, $year) as $id => $spans) {
        $out[$id] = [];
        foreach ($spans as $span) {
            $out[$id][$span['before']] = true;
            $out[$id][$span['after']]  = true;
            foreach ($span['dates'] as $ds) $out[$id][$ds] = true;
        }
    }
    return $out;
}

/** True when $date is a company-leave day charged as LOP by a sandwich. */
function attendance_is_sandwich(int $empId, string $date): bool {
    $ts = strtotime($date);
    if (!$ts) return false;
    $map = attendance_sandwich_map([$empId], (int)date('n', $ts), (int)date('Y', $ts));
    return isset($map[$empId][date('Y-m-d', $ts)]);
}

/** Half-day check-in cutoff (minutes since midnight): office start + 2 hours (e.g. 09:00 → 11:00). */
function half_day_checkin_mins(): int {
    $osM = function_exists('setting_office_start_mins') ? (int) setting_office_start_mins() : 9 * 60;
    return $osM + 120;
}

/**
 * Classify one worked day per company policy (full day = 8 net hours = 480 min).
 * The status drives BOTH the salary-slip deduction label and the report badge:
 *   • check-in after the half-day cutoff (~11:00) → 'half'    — no late; deduct ≥ ½ day        (slip: "Half Day")
 *   • net worked < 4h (check-in within cutoff)    → 'short'   — no late; deduct by hours        (slip: "Short Hours")
 *   • net worked = 4h (check-in within cutoff)    → 'half'    — no late; deduct ½ day            (slip: "Half Day")
 *   • net worked > 4h, LEFT BEFORE office end     → 'present' — late applies; deduct shortfall   (slip: "Short Hours")
 *   • net worked > 4h, stayed till/after end      → 'full'    — late applies; NO short deduction
 *
 * Key point: the short-hours (pro-rated) deduction is charged ONLY when the employee
 * left before office end. If they stayed till/after office end, any shortfall vs 8h is
 * due to a LATE check-in (already covered by the late penalty) or breaks — so charging
 * short hours too would double-count the lateness. Such days get full credit + late only.
 *
 * In the attendance report 'half' shows as "H"; 'short' and a deducted 'present'
 * show as "EO" (Early Out); 'full' shows normally.
 * $inMin / $outMin / $officeEndMin may be null (then the early-leave test is skipped).
 *
 * @return array{status:string,worked_h:float,ded_days:float,late:bool}
 *   ded_days = fraction of a day to deduct (0..1); multiply by per-day salary.
 */
function attendance_classify(int $netMin, ?int $inMin, int $officeStartMin, int $graceMin, ?int $outMin = null, ?int $officeEndMin = null, ?int $halfCutoffMin = null): array {
    $FULL_H        = 8.0;
    $FULL_MIN      = 480;
    $HALF_MIN      = 240;                                      // 4 hours
    $workedH       = max(0, $netMin) / 60;
    $shortfallDays = max(0.0, $FULL_H - $workedH) / $FULL_H;   // 0..1 of a day
    // Half-day check-in cutoff: the shift's explicit cutoff when given, else
    // the legacy start+2h rule (~11:00 when start = 09:00).
    $cutoff        = $halfCutoffMin ?? ($officeStartMin + 120);

    // Rule (highest precedence): net = 4h EXACTLY → the half day. Tested before
    // everything else so it holds even for a late arrival: 4 hours worked is half
    // a day however it came about. ded_days = (8−4)/8 = 0.5 exactly.
    if ($netMin === $HALF_MIN) {
        return ['status' => 'half', 'worked_h' => round($workedH, 2),
                'ded_days' => $shortfallDays, 'late' => false,
                'reason' => 'half_worked'];
    }
    // Rule: net < 4h → "short hours" (no late). They could not complete even a
    // half day, so salary is pro-rated on the hours actually worked — regardless
    // of check-in time.
    if ($netMin < $HALF_MIN) {
        return ['status' => 'short', 'worked_h' => round($workedH, 2),
                'ded_days' => $shortfallDays, 'late' => false];
    }
    // Rule: check-in at/after the cutoff (~11:00) → HALF DAY (no late), with a
    // ½-day floor on the deduction. Turning up that late cannot earn a full day
    // however long the employee then stays, so this sits above the "stayed till
    // office end" rule below — an 11:07 arrival leaving at 18:15 is half a day,
    // not a full day docked for the hours missed.
    if ($inMin !== null && $inMin >= $cutoff) {
        return ['status' => 'half', 'worked_h' => round($workedH, 2),
                'ded_days' => max(0.5, $shortfallDays), 'late' => false,
                'reason' => 'late_arrival'];
    }
    // net > 4h, on-time-ish.
    $lateElig  = ($inMin  !== null && $inMin  > $officeStartMin + $graceMin);
    $leftEarly = ($outMin !== null && $officeEndMin !== null && $outMin < $officeEndMin);
    // Stayed till/after office end (or a genuine full 8h) → full credit, NO short-hours
    // deduction. A late arrival here is penalised by the late pool, not pro-rated again.
    if ($netMin >= $FULL_MIN || !$leftEarly) {
        return ['status' => 'full', 'worked_h' => round($workedH, 2),
                'ded_days' => 0.0, 'late' => $lateElig];
    }
    // Left before office end with 4h < net < 8h → pro-rate the shortfall; late also applies.
    return ['status' => 'present', 'worked_h' => round($workedH, 2),
            'ded_days' => $shortfallDays, 'late' => $lateElig];
}

/**
 * Total break minutes that overlap a [in,out] presence window. $lunchWindow is the
 * employee's lunch window (from employee_lunch_window / shift_lunch_window);
 * when omitted the default lunch is used.
 *
 * $teaWindows: pass a shift's break set (from shift_break_windows) to use it
 * INSTEAD of the global tea breaks. Pass [] for a shift with no breaks at all —
 * combined with a null-lunch shift that makes total break time 0 (straight
 * shifts). null (default) keeps the legacy global tea breaks.
 */
function break_minutes_within(int $inMins, int $outMins, ?array $lunchWindow = null, ?array $teaWindows = null): int {
    if ($outMins <= $inMins) return 0;
    $windows = $teaWindows ?? tea_break_windows();
    // With an explicit break set (shift mode), a null lunch means NO lunch —
    // don't fall back to the global default.
    $lunch = ($teaWindows !== null) ? $lunchWindow : ($lunchWindow ?? default_lunch_window());
    if ($lunch !== null && ($lunch['end'] ?? 0) > ($lunch['start'] ?? 0)) $windows[] = $lunch;

    $total = 0;
    foreach ($windows as $b) {
        $overlap = min($outMins, $b['end']) - max($inMins, $b['start']);
        if ($overlap > 0) $total += $overlap;
    }
    return $total;
}

// ─── Entity name font ─────────────────────────────────────────────────────────
// Each entity can print its NAME in its own typeface (payslips, letters,
// circulars). Only the name is affected — body text is never restyled.
//
// The database stores a KEY, never CSS. entity_font_css() maps the key through
// this whitelist, so a value from the DB cannot inject arbitrary styling.
// Only web-safe stacks are offered, so they render identically in the browser
// and in printed/PDF output without needing a downloaded font.

/** key => [label, css font-family stack] */
function entity_font_options(): array {
    return [
        ''           => ['Default (document font)', ''],
        'serif'      => ['Serif — Times',           "'Times New Roman', Times, serif"],
        'georgia'    => ['Serif — Georgia',         "Georgia, 'Times New Roman', serif"],
        'garamond'   => ['Serif — Garamond',        "Garamond, Georgia, serif"],
        'sans'       => ['Sans — Arial',            "Arial, Helvetica, sans-serif"],
        'helvetica'  => ['Sans — Helvetica',        "'Helvetica Neue', Helvetica, Arial, sans-serif"],
        'verdana'    => ['Sans — Verdana',          "Verdana, Geneva, sans-serif"],
        'tahoma'     => ['Sans — Tahoma',           "Tahoma, Verdana, sans-serif"],
        'trebuchet'  => ['Sans — Trebuchet',        "'Trebuchet MS', Tahoma, sans-serif"],
        'impact'     => ['Display — Impact',        "Impact, 'Arial Black', sans-serif"],
        'arialblack' => ['Display — Arial Black',   "'Arial Black', Gadget, sans-serif"],
        'palatino'   => ['Elegant — Palatino',      "'Palatino Linotype', 'Book Antiqua', Palatino, serif"],
        'copperplate'=> ['Elegant — Copperplate',   "Copperplate, 'Copperplate Gothic Light', fantasy"],
        'cursive'    => ['Script — Brush',          "'Brush Script MT', 'Segoe Script', cursive"],
        'mono'       => ['Monospace — Courier',     "'Courier New', Courier, monospace"],
    ];
}

/**
 * CSS font-family for an entity's name, ready to drop into a style attribute.
 * Returns '' for the default (no font-family emitted at all), and '' for any
 * key not in the whitelist.
 */
function entity_font_css(?string $key): string {
    $opts = entity_font_options();
    $key  = (string)$key;
    return isset($opts[$key]) ? $opts[$key][1] : '';
}

/**
 * Ready-made style attribute value for the entity name, e.g.
 *   <div style="<?= entity_name_style($row['name_font']) ?>">
 * Empty string when the entity uses the document default.
 */
function entity_name_style(?string $key): string {
    $css = entity_font_css($key);
    return $css === '' ? '' : 'font-family:' . $css . ';';
}

/** Format decimal OT hours as "Xh Ym" (e.g. 2.78 → "2h 47m"). */
function fmt_ot_hours(float $decimalHours): string {
    $totalMins = (int) round($decimalHours * 60);
    $h = intdiv($totalMins, 60);
    $m = $totalMins % 60;
    return ($h > 0 ? $h . 'h ' : '') . $m . 'm';
}

function send_push_notification(int $userId, string $title, string $body, string $type = 'info'): void {
    // Store in notifications table for in-app display
    db()->prepare('INSERT INTO notifications (user_id, title, body, type, is_read, created_at) VALUES (?,?,?,?,0,NOW())')
        ->execute([$userId, $title, $body, $type]);
    // TODO: Integrate VAPID/web-push library for push notifications
}

function get_notifications(int $userId, bool $unread_only = false): array {
    $sql  = 'SELECT * FROM notifications WHERE user_id = ?' . ($unread_only ? ' AND is_read = 0' : '') . ' ORDER BY created_at DESC LIMIT 50';
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/* ── Interns ──────────────────────────────────────────────────────────────────
 * An intern is treated differently in two places: the Letters module issues
 * them an Internship Certificate and nothing else, and the employee form
 * disables the CTC input. Both read the rule from here so it cannot drift.
 */

/**
 * True when a DESIGNATION name denotes an intern.
 *
 * Whole-word match, so "Intern", "Software Intern" and "Intern - Accounts"
 * qualify while "Internal Auditor" and "International Sales" do not.
 */
function designation_is_intern(?string $designation): bool
{
    $designation = trim((string)$designation);
    return $designation !== '' && preg_match('/\bintern\b/i', $designation) === 1;
}

/**
 * True when an employee row is an intern — by designation name, or by an
 * employment_type of 'Intern'. Accepts any of the designation key spellings
 * used across the modules' queries.
 */
function employee_is_intern(?array $emp): bool
{
    if (!$emp) return false;
    if (strcasecmp(trim((string)($emp['employment_type'] ?? '')), 'Intern') === 0) return true;

    return designation_is_intern(
        (string)($emp['designation_name'] ?? $emp['designation'] ?? $emp['desig'] ?? '')
    );
}
