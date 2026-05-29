<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('training', 'view');

$user = current_user();
$isEmployee = (($user['role_name'] ?? '') === 'Employee');

// For employees, show only their enrolled courses
if ($isEmployee) {
    $courses = db()->query("SELECT tc.*, ac.name AS category,
        te.status AS enroll_status, te.completed_at AS completion_date, te.score,
        COUNT(DISTINCT tcr.role_id) AS role_count
        FROM training_courses tc
        LEFT JOIN asset_categories ac ON 0=1
        JOIN training_course_roles tcr ON tc.id = tcr.course_id
        JOIN users u ON u.role_id = tcr.role_id AND u.id = {$user['id']}
        LEFT JOIN training_enrollments te ON te.course_id = tc.id AND te.employee_id = {$user['employee_id']}
        WHERE tc.status != 'Archived'
        GROUP BY tc.id
        ORDER BY tc.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $courses = db()->query("SELECT tc.*,
        COUNT(DISTINCT te.id) AS enrollments,
        SUM(te.status='Completed') AS completed,
        GROUP_CONCAT(DISTINCT r.name SEPARATOR ', ') AS roles
        FROM training_courses tc
        LEFT JOIN training_enrollments te ON tc.id = te.course_id
        LEFT JOIN training_course_roles tcr ON tc.id = tcr.course_id
        LEFT JOIN roles r ON tcr.role_id = r.id
        GROUP BY tc.id ORDER BY tc.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Training';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Training</h1>
        <p class="page-subtitle">Manage training courses and enrollments</p>
    </div>
    <div class="page-actions">
        <?php if (can('training', 'manage')): ?>
            <a href="create.php" class="btn btn-primary" data-key="N"><u>N</u>ew Course</a>
        <?php endif; ?>
    </div>
</div>

<?php render_flash(); ?>

<!-- Stats (admin only) -->
<?php if (!$isEmployee):
    $stats = db()->query("SELECT
        COUNT(*) AS total,
        SUM(tc.status='Active') AS active,
        SUM(tc.status='Completed') AS completed,
        SUM(tc.status='Draft') AS drafts
        FROM training_courses tc")->fetch(PDO::FETCH_ASSOC);
?>
<div class="row mb-4">
    <div class="col"><div class="stat-card"><div class="stat-value"><?= $stats['total'] ?></div><div class="stat-label">Total Courses</div></div></div>
    <div class="col"><div class="stat-card"><div class="stat-value text-success"><?= $stats['active'] ?></div><div class="stat-label">Active</div></div></div>
    <div class="col"><div class="stat-card"><div class="stat-value"><?= $stats['completed'] ?></div><div class="stat-label">Completed</div></div></div>
    <div class="col"><div class="stat-card"><div class="stat-value text-muted"><?= $stats['drafts'] ?></div><div class="stat-label">Drafts</div></div></div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <table class="table datatable">
            <thead>
                <tr>
                    <th>Course</th>
                    <th>Type</th>
                    <th>Trainer</th>
                    <th>Created</th>
                    <th>Duration</th>
                    <?php if (!$isEmployee): ?>
                        <th>Roles</th>
                        <th>Enrolled</th>
                        <th>Completed</th>
                    <?php else: ?>
                        <th>My Status</th>
                        <th>Score</th>
                    <?php endif; ?>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($courses as $c): ?>
                <tr>
                    <td>
                        <strong><?= h($c['title']) ?></strong>
                        <?php if ($c['is_mandatory']): ?><br><span class="pill pill-danger" style="font-size:.65rem">Mandatory</span><?php endif; ?>
                    </td>
                    <td><?= h($c['training_type']) ?></td>
                    <td><?= h($c['trainer_name'] ?? '—') ?></td>
                    <td><?= date_fmt($c['created_at']) ?></td>
                    <td><?= ($c['duration_hrs'] ?? null) ? $c['duration_hrs'] . 'h' : '—' ?></td>
                    <?php if (!$isEmployee): ?>
                        <td><small><?= h($c['roles'] ?? '—') ?></small></td>
                        <td><?= $c['enrollments'] ?></td>
                        <td><?= $c['completed'] ?> / <?= $c['enrollments'] ?></td>
                    <?php else: ?>
                        <td>
                            <?php
                            $s = $c['enroll_status'] ?? 'Not Enrolled';
                            $sc= ['Completed'=>'pill-success','In Progress'=>'pill-warn','Not Started'=>'pill-secondary','Not Enrolled'=>'pill-danger'];
                            echo '<span class="pill ' . ($sc[$s]??'') . '">' . $s . '</span>';
                            ?>
                        </td>
                        <td><?= $c['score'] !== null ? $c['score'] . '%' : '—' ?></td>
                    <?php endif; ?>
                    <td>
                        <?php
                        $sc2 = ['Active'=>'pill-success','Draft'=>'pill-warn','Completed'=>'pill-secondary','Archived'=>'pill-danger'];
                        echo '<span class="pill ' . ($sc2[$c['status']]??'') . '">' . $c['status'] . '</span>';
                        ?>
                    </td>
                    <td>
                        <a href="view.php?id=<?= $c['id'] ?>" class="btn btn-xs btn-secondary">View</a>
                        <?php if (can('training', 'manage')): ?>
                            <a href="enroll.php?course_id=<?= $c['id'] ?>" class="btn btn-xs btn-primary">Enroll</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
addLocalShortcut('n', () => location.href = 'create.php');
</script>
<?php include '../../includes/footer.php'; ?>
