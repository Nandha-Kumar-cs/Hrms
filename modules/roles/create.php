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
    $selfScope = !empty($_POST['self_scope']) ? 1 : 0;

    if (!$name) $errors[] = 'Role name is required.';
    // Prepared statement (was string-concatenated with addslashes — SQLi risk).
    $dup = db()->prepare("SELECT id FROM roles WHERE name = ?"); $dup->execute([$name]);
    if ($dup->fetchColumn()) $errors[] = 'Role name already exists.';
    // Only a Super Admin may create the privileged "Super Admin" role (it bypasses all checks).
    if (strcasecmp($name, 'Super Admin') === 0 && !is_super_admin())
        $errors[] = 'You are not allowed to create a "Super Admin" role.';
    // You can only grant permissions you already hold (no privilege escalation via roles).
    $perms = filter_assignable_permission_ids((array)$perms);

    if (empty($errors)) {
        db()->prepare("INSERT INTO roles (name, description, self_scope, created_at) VALUES (:n,:d,:s,NOW())")
             ->execute([':n'=>$name,':d'=>$desc,':s'=>$selfScope]);
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

<style>
.perm-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:14px; }
.perm-card { margin:0; }
.perm-card .card-header { padding:8px 12px; background:#f8fafc; }
.perm-card .card-body { padding:10px 12px; }
.perm-card-actions .btn { padding:1px 6px; font-size:10px; }
.perm-row { display:flex; align-items:center; gap:8px; padding:3px 0; font-size:.86rem; }
.perm-row input { flex-shrink:0; }
.perm-row span { flex:1; }
.perm-hint { color:#64748b; cursor:help; font-size:.8rem; flex-shrink:0; }
.perm-hint:hover { color:#1e3a8a; }
@media (max-width: 900px){ .perm-grid { grid-template-columns:1fr 1fr; } }
@media (max-width: 600px){ .perm-grid { grid-template-columns:1fr; } }
</style>
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
                    <div class="form-group">
                        <label class="form-check">
                            <input type="checkbox" name="self_scope" value="1" <?= !empty($_POST['self_scope']) ? 'checked' : '' ?>>
                            <span>Restrict to own records only (self-service)</span>
                        </label>
                        <p class="text-muted mt-1" style="font-size:.8rem">
                            Users with this role see only their own employee data everywhere and
                            cannot view other employees' details.
                        </p>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary w-100" data-key="S"><u>S</u>ave Role</button>
                </div>
            </div>
        </div>

        <div class="col-8">
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title">Assign Permissions</h3>
                    <div>
                        <button type="button" class="btn btn-xs btn-secondary" onclick="toggleAllPerms(true)">Select All</button>
                        <button type="button" class="btn btn-xs btn-secondary" onclick="toggleAllPerms(false)">Clear All</button>
                    </div>
                </div>
            </div>
            <div class="perm-grid">
                <?php foreach ($grouped as $module => $mperms): ?>
                <div class="card perm-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong><?= h(module_label($module)) ?></strong>
                        <span class="perm-card-actions">
                            <button type="button" class="btn btn-xs btn-secondary" onclick="toggleModule('<?= $module ?>', true)">All</button>
                            <button type="button" class="btn btn-xs btn-secondary" onclick="toggleModule('<?= $module ?>', false)">None</button>
                        </span>
                    </div>
                    <div class="card-body">
                        <?php foreach ($mperms as $p): $hint = permission_hint($p['module'], $p['action']); ?>
                        <label class="form-check perm-row">
                            <input type="checkbox" name="permissions[]" value="<?= $p['id'] ?>"
                                class="perm-check perm-<?= $p['module'] ?>"
                                <?= in_array($p['id'], $_POST['permissions']??[]) ? 'checked' : '' ?>>
                            <span><?= h($p['label'] ?: ucfirst($p['action'])) ?></span>
                            <?php if ($hint !== ''): ?><i class="fa fa-eye perm-hint" title="<?= h($hint) ?>"></i><?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
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
