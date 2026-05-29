<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('training_edit');

$roles  = db()->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['csrf_token'] ?? '');

    $title       = trim($_POST['title'] ?? '');
    $desc        = trim($_POST['description'] ?? '');
    $type        = $_POST['training_type'] ?? 'Online';
    $trainer     = trim($_POST['trainer_name'] ?? '');
    $trainerEmail= trim($_POST['trainer_email'] ?? '');
    $startDate   = $_POST['start_date'] ?? null;
    $endDate     = $_POST['end_date'] ?? null;
    $duration    = (int)$_POST['duration_hours'];
    $mandatory   = isset($_POST['is_mandatory']) ? 1 : 0;
    $location    = trim($_POST['location'] ?? '');
    $link        = trim($_POST['external_link'] ?? '');
    $status      = $_POST['status'] ?? 'Draft';
    $selectedRoles = $_POST['role_ids'] ?? [];

    if (!$title) $errors[] = 'Course title is required.';

    if (empty($errors)) {
        $stmt = db()->prepare("INSERT INTO training_courses
            (title,description,training_type,trainer_name,trainer_email,start_date,end_date,duration_hours,is_mandatory,location,external_link,status,created_by,created_at)
            VALUES (:ti,:de,:ty,:tr,:te,:sd,:ed,:du,:ma,:lo,:el,:st,:cb,NOW())");
        $stmt->execute([':ti'=>$title,':de'=>$desc,':ty'=>$type,':tr'=>$trainer,':te'=>$trainerEmail,
            ':sd'=>$startDate?:null,':ed'=>$endDate?:null,':du'=>$duration?:null,':ma'=>$mandatory,
            ':lo'=>$location,':el'=>$link,':st'=>$status,':cb'=>current_user()['id']]);
        $courseId = db()->lastInsertId();

        // Save role assignments
        foreach ($selectedRoles as $rid) {
            db()->prepare("INSERT INTO training_course_roles (course_id,role_id) VALUES (:cid,:rid)")
                 ->execute([':cid'=>$courseId,':rid'=>(int)$rid]);
        }

        flash('success','Training course created.');
        redirect(BASE_URL . "/modules/training/view.php?id=$courseId");
    }
}

$page_title = 'New Training Course';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">New Training Course</h1>
        <p class="page-subtitle">Create a new training program</p>
    </div>
    <div class="page-actions">
        <a href="index.php" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>

<form method="POST" id="courseForm">
    <?= csrf_field() ?>
    <div class="row">
        <div class="col-8">
            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title">Course Details</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Course Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" value="<?= h($_POST['title'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="4"><?= h($_POST['description'] ?? '') ?></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-4">
                            <label class="form-label">Training Type</label>
                            <select name="training_type" class="form-control">
                                <?php foreach (['Online','Offline','Blended','Self-Paced','Workshop'] as $t): ?>
                                    <option value="<?= $t ?>" <?= ($_POST['training_type']??'Online')==$t?'selected':'' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-4">
                            <label class="form-label">Duration (hours)</label>
                            <input type="number" name="duration_hours" class="form-control" value="<?= h($_POST['duration_hours'] ?? '') ?>" min="0">
                        </div>
                        <div class="form-group col-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <?php foreach (['Draft','Active','Completed','Archived'] as $s): ?>
                                    <option value="<?= $s ?>" <?= ($_POST['status']??'Draft')==$s?'selected':'' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control" value="<?= h($_POST['start_date'] ?? '') ?>">
                        </div>
                        <div class="form-group col-6">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-control" value="<?= h($_POST['end_date'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label class="form-label">Trainer Name</label>
                            <input type="text" name="trainer_name" class="form-control" value="<?= h($_POST['trainer_name'] ?? '') ?>">
                        </div>
                        <div class="form-group col-6">
                            <label class="form-label">Trainer Email</label>
                            <input type="email" name="trainer_email" class="form-control" value="<?= h($_POST['trainer_email'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label class="form-label">Location / Venue</label>
                            <input type="text" name="location" class="form-control" value="<?= h($_POST['location'] ?? '') ?>">
                        </div>
                        <div class="form-group col-6">
                            <label class="form-label">External Link / LMS URL</label>
                            <input type="url" name="external_link" class="form-control" value="<?= h($_POST['external_link'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-check">
                            <input type="checkbox" name="is_mandatory" value="1" <?= isset($_POST['is_mandatory'])?'checked':'' ?>> Mark as Mandatory Training
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-4">
            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title">Assign to Roles</h3></div>
                <div class="card-body">
                    <p class="text-muted text-sm">Select roles that should attend this training:</p>
                    <?php foreach ($roles as $r): ?>
                    <label class="form-check mb-2">
                        <input type="checkbox" name="role_ids[]" value="<?= $r['id'] ?>"
                            <?= in_array($r['id'], $_POST['role_ids']??[]) ? 'checked' : '' ?>>
                        <?= h($r['name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary w-100 mb-2" data-key="S"><u>S</u>ave Course</button>
                    <a href="index.php" class="btn btn-secondary w-100" data-key="B"><u>B</u>ack</a>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
addLocalShortcut('s', () => document.getElementById('courseForm').submit());
addLocalShortcut('b', () => location.href='index.php');
</script>
<?php include '../../includes/footer.php'; ?>
