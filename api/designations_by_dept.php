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

$stmt = db()->prepare(
    "SELECT id, name FROM designations
     WHERE department_id = ?
     ORDER BY name"
);
$stmt->execute([$dept_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
