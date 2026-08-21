<?php
/**
 * AJAX — designations filtered by department
 * GET /api/designations_by_dept.php?dept_id=3
 * Returns: [{id, name}, ...]
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$dept_id = (int)($_GET['dept_id'] ?? 0);

if (!$dept_id) {
    echo json_encode([]);
    exit;
}

// Designations flagged all_departments are common to every department (e.g.
// "Intern") and are offered alongside the department's own — see
// install/add_common_designations.sql. NULL department_id on its own does NOT
// qualify: that is how a designation is left behind when its department is
// deleted, and those must stay hidden.
$stmt = db()->prepare(
    "SELECT id, name FROM designations
     WHERE department_id = ? OR all_departments = 1
     ORDER BY all_departments, name"
);
$stmt->execute([$dept_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
