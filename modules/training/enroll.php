<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('training_edit');

// Handle enrollment status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_enrollment'])) {
    verify_csrf($_POST['csrf_token'] ?? '');
    $eid = (int)$_POST['enrollment_id'];
    $status = $_POST['status'];
    $score  = $_POST['score'] !== '' ? (float)$_POST['score'] : null;
    $date   = $_POST['completion_date'] ?: null;
    $rem    = trim($_POST['remarks'] ?? '');

    $stmt = db()->prepare("UPDATE training_enrollments SET status=:s, score=:sc, completion_date=:cd, remarks=:r WHERE id=:id");
    $stmt->execute([':s'=>$status,':sc'=>$score,':cd'=>$date,':r'=>$rem,':id'=>$eid]);

    $courseId = db()->query("SELECT course_id FROM training_enrollments WHERE id=$eid")->fetchColumn();
    flash('success','Enrollment updated.');
    redirect(BASE_URL . "/modules/training/view.php?id=$courseId");
}

// Handle bulk enroll
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_enroll'])) {
    verify_csrf($_POST['csrf_token'] ?? '');
    $courseId   = (int)$_POST['course_id'];
    $empIds     = $_POST['employee_ids'] ?? [];
    $enrolled   = 0;
    foreach ($empIds as $eid) {
        $eid = (int)$eid;
        // Check not already enrolled
        $exists = db()->query("SELECT id FROM training_enrollments WHERE course_id=$courseId AND employee_id=$eid")->fetchColumn();
        if (!$exists) {
            db()->prepare("INSERT INTO training_enrollments (course_id,employee_id,status,enrolled_by,enrolled_at) VALUES (:cid,:eid,'Not Started',:uid,NOW())")
                 ->execute([':cid'=>$courseId,':eid'=>$eid,':uid'=>current_user()['id']]);
            $enrolled++;
        }
    }
    flash('success',"$enrolled employee(s) enrolled successfully.");
    redirect(BASE_URL . "/modules/training/view.php?id=$courseId");
}

$courseId = (int)($_GET['course_id'] ?? 0);
if (!$courseId) redirect(BASE_URL . '/modules/training/index.php');

$course = db()->query("SELECT * FROM training_courses WHERE id=$courseId")->fetch(PDO::FETCH_ASSOC);
if (!$course) redirect(BASE_URL . '/modules/training/index.php');

// Get already enrolled IDs
$enrolled = db()->query("SELECT employee_id FROM training_enrollments WHERE course_id=$courseId")->fetchAll(PDO::FETCH_COLUMN);

// Get employees (optionally filtered by course roles)
$employees = db()->query("SELECT e.id, e.employee_id AS emp_code, CONCAT(e.first_name,' ',e.last_name) AS emp_name,
    d.name AS dept, r.name AS role_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN users u ON u.employee_id = e.id
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE e.status='Active' ORDER BY e.first_name")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Enroll Employees';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Enroll Employees</h1>
        <p class="page-subtitle"><?= h($course['title']) ?></p>
    </div>
    <div class="page-actions">
        <a href="view.php?id=<?= $courseId ?>" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php render_flash(); ?>

<form method="POST" id="enrollForm">
    <?= csrf_field() ?>
    <input type="hidden" name="bulk_enroll" value="1">
    <input type="hidden" name="course_id" value="<?= $courseId ?>">

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title">Select Employees to Enroll</h3>
            <div>
                <button type="button" class="btn btn-xs btn-secondary" onclick="toggleAll(true)">Select All</button>
                <button type="button" class="btn btn-xs btn-secondary" onclick="toggleAll(false)">Deselect All</button>
            </div>
        </div>
        <div class="card-body">
            <table class="table datatable">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="masterCheck" onchange="toggleAll(this.checked)"></th>
                        <th>Employee ID</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Role</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $e):
                        $isEnrolled = in_array($e['id'], $enrolled);
                    ?>
                    <tr class="<?= $isEnrolled ? 'row-enrolled' : '' ?>">
                        <td>
                            <?php if ($isEnrolled): ?>
                                <span class="pill pill-success" style="font-size:.65rem">Enrolled</span>
                            <?php else: ?>
                                <input type="checkbox" name="employee_ids[]" value="<?= $e['id'] ?>" class="emp-check">
                            <?php endif; ?>
                        </td>
                        <td><?= h($e['emp_code']) ?></td>
                        <td><?= h($e['emp_name']) ?></td>
                        <td><?= h($e['dept']) ?></td>
                        <td><?= h($e['role_name']) ?></td>
                        <td><?= $isEnrolled ? '<span class="text-success">Already Enrolled</span>' : '<span class="text-muted">Not Enrolled</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary" data-key="S"><u>S</u>ave Enrollments</button>
            <a href="view.php?id=<?= $courseId ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </div>
</form>

<style>
.row-enrolled { opacity:.6; }
</style>

<script>
function toggleAll(checked) {
    document.querySelectorAll('.emp-check').forEach(c => c.checked = checked);
    document.getElementById('masterCheck').checked = checked;
}
addLocalShortcut('s', () => document.getElementById('enrollForm').submit());
addLocalShortcut('b', () => location.href = 'view.php?id=<?= $courseId ?>');
</script>
<?php include '../../includes/footer.php'; ?>
