<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('training', 'view');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/training/index.php');

$course = db()->query("SELECT tc.*, u.name AS created_by_name
    FROM training_courses tc LEFT JOIN users u ON tc.created_by = u.id WHERE tc.id=$id")->fetch(PDO::FETCH_ASSOC);

if (!$course) redirect(BASE_URL . '/modules/training/index.php');

$courseRoles = db()->query("SELECT r.id, r.name FROM training_course_roles tcr JOIN roles r ON tcr.role_id = r.id WHERE tcr.course_id=$id")->fetchAll(PDO::FETCH_ASSOC);

$enrollments = db()->query("SELECT te.*, e.name AS emp_name, e.employee_id AS emp_code,
    r.name AS role_name, d.name AS dept_name,
    u2.name AS enrolled_by_name
    FROM training_enrollments te
    JOIN employees e ON te.employee_id = e.id
    LEFT JOIN users u ON u.employee_id = e.id
    LEFT JOIN roles r ON u.role_id = r.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN users u2 ON te.enrolled_by = u2.id
    WHERE te.course_id=$id ORDER BY e.name")->fetchAll(PDO::FETCH_ASSOC);

$completedCount = count(array_filter($enrollments, fn($e) => $e['status'] === 'Completed'));

$page_title = $course['title'];
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= h($course['title']) ?></h1>
        <p class="page-subtitle"><?= h($course['training_type']) ?> &mdash; <?= $course['is_mandatory'] ? '<span class="pill pill-danger">Mandatory</span>' : 'Optional' ?></p>
    </div>
    <div class="page-actions">
        <?php if (can('training', 'edit')): ?>
            <a href="enroll.php?course_id=<?= $id ?>" class="btn btn-primary" data-key="E"><u>E</u>nroll</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php render_flash(); ?>

<div class="row mb-4">
    <div class="col"><div class="stat-card"><div class="stat-value"><?= count($enrollments) ?></div><div class="stat-label">Enrolled</div></div></div>
    <div class="col"><div class="stat-card"><div class="stat-value text-success"><?= $completedCount ?></div><div class="stat-label">Completed</div></div></div>
    <div class="col"><div class="stat-card"><div class="stat-value"><?= count($enrollments) - $completedCount ?></div><div class="stat-label">Pending</div></div></div>
    <?php if ($course['duration_hours']): ?>
    <div class="col"><div class="stat-card"><div class="stat-value"><?= $course['duration_hours'] ?>h</div><div class="stat-label">Duration</div></div></div>
    <?php endif; ?>
</div>

<div class="row">
    <div class="col-4">
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">Course Information</h3></div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr><td class="text-muted">Status</td><td>
                        <?php $sc=['Active'=>'pill-success','Draft'=>'pill-warn','Completed'=>'pill-secondary','Archived'=>'pill-danger'];
                        echo '<span class="pill '.($sc[$course['status']]??'').'">'.$course['status'].'</span>'; ?>
                    </td></tr>
                    <tr><td class="text-muted">Type</td><td><?= h($course['training_type']) ?></td></tr>
                    <?php if ($course['trainer_name']): ?><tr><td class="text-muted">Trainer</td><td><?= h($course['trainer_name']) ?><br><?= h($course['trainer_email']) ?></td></tr><?php endif; ?>
                    <?php if ($course['start_date']): ?><tr><td class="text-muted">Start</td><td><?= date_fmt($course['start_date']) ?></td></tr><?php endif; ?>
                    <?php if ($course['end_date']): ?><tr><td class="text-muted">End</td><td><?= date_fmt($course['end_date']) ?></td></tr><?php endif; ?>
                    <?php if ($course['location']): ?><tr><td class="text-muted">Location</td><td><?= h($course['location']) ?></td></tr><?php endif; ?>
                    <?php if ($course['external_link']): ?><tr><td class="text-muted">Link</td><td><a href="<?= h($course['external_link']) ?>" target="_blank">Open</a></td></tr><?php endif; ?>
                    <tr><td class="text-muted">Roles</td><td><?= $courseRoles ? implode(', ', array_column($courseRoles,'name')) : '—' ?></td></tr>
                </table>
                <?php if ($course['description']): ?>
                    <div class="mt-3"><strong>Description</strong><p class="text-muted"><?= nl2br(h($course['description'])) ?></p></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Progress Chart -->
        <?php if (count($enrollments) > 0):
            $pct = round($completedCount / count($enrollments) * 100);
        ?>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Completion Progress</h3></div>
            <div class="card-body">
                <div class="training-progress-bar" style="height:12px;background:var(--bg-secondary);border-radius:6px;overflow:hidden;">
                    <div style="width:<?= $pct ?>%;height:100%;background:var(--success);border-radius:6px;transition:width .5s;"></div>
                </div>
                <div class="text-center mt-2"><strong><?= $pct ?>% Complete</strong></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Enrollment List</h3></div>
            <div class="card-body">
                <?php if ($enrollments): ?>
                <table class="table datatable">
                    <thead>
                        <tr><th>Employee</th><th>Department</th><th>Role</th><th>Status</th><th>Score</th><th>Completed</th>
                        <?php if (can('training', 'edit')): ?><th>Action</th><?php endif; ?></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrollments as $en):
                            $sc=['Completed'=>'pill-success','In Progress'=>'pill-warn','Not Started'=>'pill-secondary','Failed'=>'pill-danger'];
                        ?>
                        <tr>
                            <td><strong><?= h($en['emp_code']) ?></strong><br><small><?= h($en['emp_name']) ?></small></td>
                            <td><?= h($en['dept_name']) ?></td>
                            <td><?= h($en['role_name']) ?></td>
                            <td><span class="pill <?= $sc[$en['status']]??'' ?>"><?= $en['status'] ?></span></td>
                            <td><?= $en['score'] !== null ? $en['score'].'%' : '—' ?></td>
                            <td><?= $en['completion_date'] ? date_fmt($en['completion_date']) : '—' ?></td>
                            <?php if (can('training', 'edit')): ?>
                            <td>
                                <button onclick="updateStatus(<?= $en['id'] ?>)" class="btn btn-xs btn-primary">Update</button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p class="text-muted">No enrollments yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div class="modal-overlay" id="updateModal" style="display:none">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Update Enrollment Status</h3>
            <button class="modal-close" onclick="closeModal('updateModal')">&times;</button>
        </div>
        <form method="POST" action="enroll.php">
            <?= csrf_field() ?>
            <input type="hidden" name="update_enrollment" value="1">
            <input type="hidden" name="enrollment_id" id="updateEnrollId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control" required>
                        <?php foreach (['Not Started','In Progress','Completed','Failed'] as $s): ?>
                            <option value="<?= $s ?>"><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Score (%)</label>
                    <input type="number" name="score" class="form-control" min="0" max="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Completion Date</label>
                    <input type="date" name="completion_date" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Remarks</label>
                    <textarea name="remarks" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('updateModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function updateStatus(id) {
    document.getElementById('updateEnrollId').value = id;
    openModal('updateModal');
}
addLocalShortcut('b', () => location.href='index.php');
</script>
<?php include '../../includes/footer.php'; ?>
