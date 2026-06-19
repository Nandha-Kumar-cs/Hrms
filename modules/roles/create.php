<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('roles', 'edit');

$allPerms = db()->query("SELECT * FROM permissions ORDER BY module, action")->fetchAll(PDO::FETCH_ASSOC);
$grouped  = [];
foreach ($allPerms as $p) $grouped[$p['module']][] = $p;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['csrf_token'] ?? '');
    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $perms = $_POST['permissions'] ?? [];

    if (!$name) $errors[] = 'Role name is required.';
    if (db()->query("SELECT id FROM roles WHERE name='".addslashes($name)."'")->fetchColumn()) $errors[] = 'Role name already exists.';

    if (empty($errors)) {
        db()->prepare("INSERT INTO roles (name, description, created_at) VALUES (:n,:d,NOW())")
             ->execute([':n'=>$name,':d'=>$desc]);
        $roleId = db()->lastInsertId();

        foreach ($perms as $pid) {
            db()->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (:r,:p)")
                 ->execute([':r'=>$roleId,':p'=>(int)$pid]);
        }

        flash('success',"Role '$name' created successfully.");
        redirect(BASE_URL . '/modules/roles/index.php');
    }
}

$page_title = 'New Role';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">New Role</h1>
        <p class="page-subtitle">Create a role and assign permissions</p>
    </div>
    <div class="page-actions">
        <a href="index.php" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>

<form method="POST" id="roleForm">
    <?= csrf_field() ?>
    <div class="row">
        <div class="col-4">
            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title">Role Details</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Role Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= h($_POST['name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"><?= h($_POST['description'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary w-100" data-key="S"><u>S</u>ave Role</button>
                </div>
            </div>
        </div>

        <div class="col-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title">Assign Permissions</h3>
                    <div>
                        <button type="button" class="btn btn-xs btn-secondary" onclick="toggleAllPerms(true)">Select All</button>
                        <button type="button" class="btn btn-xs btn-secondary" onclick="toggleAllPerms(false)">Clear All</button>
                    </div>
                </div>
                <div class="card-body">
                    <?php foreach ($grouped as $module => $mperms): ?>
                    <div class="perm-module mb-4">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <strong><?= h(module_label($module)) ?></strong>
                            <button type="button" class="btn btn-xs btn-secondary" onclick="toggleModule('<?= $module ?>', true)">All</button>
                            <button type="button" class="btn btn-xs btn-secondary" onclick="toggleModule('<?= $module ?>', false)">None</button>
                        </div>
                        <div class="row">
                            <?php foreach ($mperms as $p): ?>
                            <div class="col-6 mb-1">
                                <label class="form-check">
                                    <input type="checkbox" name="permissions[]" value="<?= $p['id'] ?>"
                                        class="perm-check perm-<?= $p['module'] ?>"
                                        <?= in_array($p['id'], $_POST['permissions']??[]) ? 'checked' : '' ?>>
                                    <span><?= h($p['label'] ?: ucfirst($p['action'])) ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php if (!array_key_last($grouped) === $module): ?><hr><?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function toggleAllPerms(checked) {
    document.querySelectorAll('.perm-check').forEach(c => c.checked = checked);
}
function toggleModule(mod, checked) {
    document.querySelectorAll('.perm-' + mod).forEach(c => c.checked = checked);
}
addLocalShortcut('s', () => document.getElementById('roleForm').submit());
addLocalShortcut('b', () => location.href='index.php');
</script>
<?php include '../../includes/footer.php'; ?>
