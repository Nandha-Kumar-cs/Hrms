<?php
/**
 * MagDyn HRMS — General Helpers
 */

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
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

function date_fmt(string $date, string $format = 'd M Y'): string {
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

/** Convert "HH:MM" (or "HH:MM:SS") to minutes-since-midnight. */
function time_to_mins(string $time): int {
    $p = explode(':', $time);
    return (int)($p[0] ?? 0) * 60 + (int)($p[1] ?? 0);
}

// ── Grace / Late permission ──────────────────────────────────────────────────
function setting_daily_grace_mins(): int   { return (int) setting_get('daily_grace_minutes', 15); }
function setting_monthly_grace_mins(): int { return (int) setting_get('monthly_grace_minutes', 90); }

// ── Office hours ──────────────────────────────────────────────────────────────
function setting_office_start(): string    { return (string) setting_get('office_start_time', '09:00'); }
function setting_office_start_mins(): int   { return time_to_mins(setting_office_start()); }

// ── OT timing ─────────────────────────────────────────────────────────────────
function setting_ot_trigger(): string      { return (string) setting_get('ot_trigger_time', '20:30'); }
function setting_ot_baseline(): string      { return (string) setting_get('ot_baseline_time', '18:15'); }
function setting_ot_trigger_mins(): int     { return time_to_mins(setting_ot_trigger()); }
function setting_ot_baseline_mins(): int    { return time_to_mins(setting_ot_baseline()); }

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
