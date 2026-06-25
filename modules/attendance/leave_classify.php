<?php
/**
 * Classify an ABSENT attendance day as Paid or Unpaid leave (AJAX).
 *
 * POST: employee_id, att_date (Y-m-d), classification ('paid'|'unpaid'|'none'), csrf_token
 * Returns JSON: { ok, classification, paid_used, message }
 *
 * Rules enforced server-side (authoritative):
 *   • Only rows with status = 'Absent' can be classified.
 *   • 'paid' is capped at 1 per employee per calendar month — counting BOTH
 *     paid-classified absences AND approved paid leave_requests in that month.
 *   • 'unpaid' is unlimited (it is explicit LOP). 'none' clears the flag.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('attendance', 'edit');

header('Content-Type: application/json');

$fail = function (string $msg, int $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg]);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST')           $fail('Invalid request method.', 405);
if (!csrf_verify())                                  $fail('Invalid or expired session token. Reload the page.', 419);
// Classifying others' attendance is an admin action — not for self-scoped users.
if (is_self_scoped())                                $fail('You are not allowed to classify attendance.', 403);

$empId  = (int)($_POST['employee_id'] ?? 0);
$date   = trim((string)($_POST['att_date'] ?? ''));
$class  = (string)($_POST['classification'] ?? '');

$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$empId || !$d || $d->format('Y-m-d') !== $date)  $fail('Invalid employee or date.');
if (!in_array($class, ['paid', 'unpaid', 'none'], true)) $fail('Invalid classification.');

$db = db();

// Find any existing row. A real row must be Absent to be classifiable; a missing
// row (a "no record" past day shown as A in the report) is created on demand.
$row = $db->prepare('SELECT id, status FROM attendance WHERE employee_id = ? AND att_date = ? LIMIT 1');
$row->execute([$empId, $date]);
$att   = $row->fetch();
$attId = $att ? (int)$att['id'] : 0;

if ($att && $att['status'] !== 'Absent') {
    $fail('Only days marked Absent can be classified as leave.');
}
// Nothing to clear on a day that has no row.
if (!$att && $class === 'none') {
    echo json_encode(['ok' => true, 'classification' => 'none', 'paid_used' => 0, 'message' => 'Nothing to clear.']);
    exit;
}

$monthStart = $d->format('Y-m-01');
$monthEnd   = $d->format('Y-m-t');

// Enforce the 1-paid-leave-per-month cap (across both mechanisms).
if ($class === 'paid') {
    $c1 = $db->prepare(
        "SELECT COUNT(*) FROM attendance
          WHERE employee_id = ? AND status = 'Absent' AND leave_classification = 'paid'
            AND att_date BETWEEN ? AND ? AND id <> ?"
    );
    $c1->execute([$empId, $monthStart, $monthEnd, $attId]);
    $paidAtt = (int)$c1->fetchColumn();

    $c2 = $db->prepare(
        "SELECT COUNT(*) FROM leave_requests lr
           JOIN leave_types lt ON lt.id = lr.leave_type_id
          WHERE lr.employee_id = ? AND lr.status = 'approved' AND lt.is_paid = 1
            AND lr.end_date >= ? AND lr.start_date <= ?"
    );
    $c2->execute([$empId, $monthStart, $monthEnd]);
    $paidLr = (int)$c2->fetchColumn();

    if ($paidAtt + $paidLr >= 1) {
        $fail('Paid Leave limit reached: this employee already has 1 paid leave in '
            . $d->format('F Y') . '. Mark additional absences as Unpaid Leave.', 409);
    }
}

$newVal = ($class === 'none') ? null : $class;
if ($attId) {
    $db->prepare('UPDATE attendance SET leave_classification = ? WHERE id = ?')
       ->execute([$newVal, $attId]);
} else {
    // Create the Absent row on demand (past working day with no punch record).
    // Upsert guards against a race on the (employee_id, att_date) unique key.
    $uid = (int)(current_user()['id'] ?? 0) ?: null;
    $db->prepare("INSERT INTO attendance (employee_id, att_date, status, leave_classification, marked_by, created_at)
                  VALUES (?, ?, 'Absent', ?, ?, NOW())
                  ON DUPLICATE KEY UPDATE leave_classification = VALUES(leave_classification)")
       ->execute([$empId, $date, $newVal, $uid]);
}

if (function_exists('activity_log')) {
    $label = $newVal === null ? 'cleared leave classification' : ('classified absence as ' . ucfirst($class) . ' Leave');
    $who   = function_exists('activity_emp_label') ? activity_emp_label($empId) : ('employee #' . $empId);
    activity_log('updated', 'Attendance', $label . ' for ' . $who . ' on ' . $d->format('d M Y'));
}

// Report how many paid leaves are now used this month (for UI state).
$used = $db->prepare(
    "SELECT
        (SELECT COUNT(*) FROM attendance
           WHERE employee_id = ? AND status='Absent' AND leave_classification='paid'
             AND att_date BETWEEN ? AND ?)
      + (SELECT COUNT(*) FROM leave_requests lr JOIN leave_types lt ON lt.id=lr.leave_type_id
           WHERE lr.employee_id = ? AND lr.status='approved' AND lt.is_paid=1
             AND lr.end_date >= ? AND lr.start_date <= ?) AS used"
);
$used->execute([$empId, $monthStart, $monthEnd, $empId, $monthStart, $monthEnd]);
$paidUsed = (int)$used->fetchColumn();

echo json_encode([
    'ok'             => true,
    'classification' => $class,
    'paid_used'      => $paidUsed,
    'message'        => $class === 'none'
        ? 'Absence classification cleared.'
        : ('Absence marked as ' . ucfirst($class) . ' Leave.'),
]);
