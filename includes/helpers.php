<?php
/**
 * MagDyn HRMS — General Helpers
 */

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
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

function generate_employee_id(): string {
    $stmt = db()->query('SELECT MAX(CAST(SUBSTRING(employee_id, 4) AS UNSIGNED)) as max_id FROM employees');
    $max = (int) $stmt->fetchColumn();
    return 'EMP' . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
}

function sanitize(mixed $input): string {
    return trim(strip_tags((string)$input));
}

function paginate(int $total, int $perPage, int $current): array {
    $pages = (int) ceil($total / $perPage);
    return ['total' => $total, 'per_page' => $perPage, 'current' => $current, 'pages' => $pages,
            'offset' => ($current - 1) * $perPage];
}

function upload_file(array $file, string $dest, string $prefix = ''): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > UPLOAD_MAX_MB * 1024 * 1024) return null;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_DOC_TYPES)) return null;
    $ext  = pathinfo($file['name'], PATHINFO_EXTENSION);
    $name = $prefix . uniqid() . '.' . strtolower($ext);
    $path = rtrim($dest, '/') . '/' . $name;
    if (!is_dir($dest)) mkdir($dest, 0755, true);
    move_uploaded_file($file['tmp_name'], $path);
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
 * In the attendance report, 'half' and 'short' show as "H"; 'present'/'full' show normally.
 * $inMin / $outMin / $officeEndMin may be null (then the early-leave test is skipped).
 *
 * @return array{status:string,worked_h:float,ded_days:float,late:bool}
 *   ded_days = fraction of a day to deduct (0..1); multiply by per-day salary.
 */
function attendance_classify(int $netMin, ?int $inMin, int $officeStartMin, int $graceMin, ?int $outMin = null, ?int $officeEndMin = null): array {
    $FULL_H        = 8.0;
    $FULL_MIN      = 480;
    $HALF_MIN      = 240;                                      // 4 hours
    $workedH       = max(0, $netMin) / 60;
    $shortfallDays = max(0.0, $FULL_H - $workedH) / $FULL_H;   // 0..1 of a day
    $cutoff        = $officeStartMin + 120;                    // ~11:00 when start = 09:00

    // Rule (highest precedence): net < 4h → "short hours" (no late). They could not
    // complete even a half day, so salary is pro-rated on the hours actually worked —
    // regardless of check-in time.
    if ($netMin < $HALF_MIN) {
        return ['status' => 'short', 'worked_h' => round($workedH, 2),
                'ded_days' => $shortfallDays, 'late' => false];
    }
    // Rule: check-in at/after ~11:00 (with ≥ 4h worked) → half day (no late), ≥ ½ day off.
    if ($inMin !== null && $inMin >= $cutoff) {
        return ['status' => 'half', 'worked_h' => round($workedH, 2),
                'ded_days' => max(0.5, $shortfallDays), 'late' => false];
    }
    // Rule: net = 4h exactly → half day (no late), ½-day deduction.
    if ($netMin === $HALF_MIN) {
        return ['status' => 'half', 'worked_h' => round($workedH, 2),
                'ded_days' => $shortfallDays, 'late' => false];
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
 * employee's lunch window (from employee_lunch_window); when omitted the default
 * lunch is used. Tea breaks are always the global ones.
 */
function break_minutes_within(int $inMins, int $outMins, ?array $lunchWindow = null): int {
    if ($outMins <= $inMins) return 0;
    $windows = tea_break_windows();
    $lunch   = $lunchWindow ?? default_lunch_window();
    if (($lunch['end'] ?? 0) > ($lunch['start'] ?? 0)) $windows[] = $lunch;

    $total = 0;
    foreach ($windows as $b) {
        $overlap = min($outMins, $b['end']) - max($inMins, $b['start']);
        if ($overlap > 0) $total += $overlap;
    }
    return $total;
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
