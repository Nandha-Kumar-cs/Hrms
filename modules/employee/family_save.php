<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$db = db();

// Ensure table exists
$db->exec('CREATE TABLE IF NOT EXISTS employee_family_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    relationship VARCHAR(50) NOT NULL,
    dob DATE NULL,
    occupation VARCHAR(100) NULL,
    contact_number VARCHAR(30) NULL,
    dependency_status ENUM(\'dependent\',\'independent\') DEFAULT \'dependent\',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$action  = $_POST['action'] ?? '';
$canEdit = can('employee', 'edit');

// ── ADD (AJAX JSON response) ──────────────────────────────────────────────────
if ($action === 'add') {
    header('Content-Type: application/json');

    if (!csrf_verify()) {
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit;
    }

    $emp_id            = (int)($_POST['emp_id'] ?? 0);
    // Authorization: must be allowed to edit employees, and (if self-scoped) only own record.
    if (!$canEdit || (is_self_scoped() && $emp_id !== current_employee_id())) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You are not allowed to modify this record.']);
        exit;
    }
    $name              = trim($_POST['name'] ?? '');
    $relationship      = trim($_POST['relationship'] ?? '');
    $dob               = ($_POST['dob'] ?? '') ?: null;
    $occupation        = trim($_POST['occupation'] ?? '') ?: null;
    $contact_number    = trim($_POST['contact_number'] ?? '') ?: null;
    $dep_status_raw    = $_POST['dependency_status'] ?? 'dependent';
    $dependency_status = in_array($dep_status_raw, ['dependent', 'independent']) ? $dep_status_raw : 'dependent';

    if (!$emp_id || !$name || !$relationship) {
        echo json_encode(['success' => false, 'error' => 'Name and Relationship are required.']);
        exit;
    }

    $stmt = $db->prepare(
        'INSERT INTO employee_family_members
         (employee_id, name, relationship, dob, occupation, contact_number, dependency_status)
         VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->execute([$emp_id, $name, $relationship, $dob, $occupation, $contact_number, $dependency_status]);

    echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);
    exit;
}

// ── DELETE (form POST, redirect back) ────────────────────────────────────────
if ($action === 'delete') {
    verify_csrf($_POST['csrf_token'] ?? '');
    $fid    = (int)($_POST['fid'] ?? 0);
    $emp_id = (int)($_POST['emp_id'] ?? 0);

    if (!$canEdit) { flash('error', 'You are not allowed to modify this record.'); redirect(BASE_URL . '/index.php'); }

    if ($fid) {
        // Resolve the row's true owner and enforce ownership before deleting.
        $own = $db->prepare('SELECT employee_id FROM employee_family_members WHERE id=?');
        $own->execute([$fid]);
        $ownerEmp = (int)$own->fetchColumn();
        if ($ownerEmp) {
            require_own_employee($ownerEmp);   // self-scoped users: own record only
            $db->prepare('DELETE FROM employee_family_members WHERE id=? AND employee_id=?')->execute([$fid, $ownerEmp]);
            $emp_id = $emp_id ?: $ownerEmp;
        }
    }

    redirect(BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#family');
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);
