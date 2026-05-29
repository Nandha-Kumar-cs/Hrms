<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('roles', 'view');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/roles/index.php');

$role = db()->query("SELECT * FROM roles WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
if (!$role) redirect(BASE_URL . '/modules/roles/index.php');

$allPerms = db()->query("SELECT * FROM permissions ORDER BY module, name")->fetchAll(PDO::FETCH_ASSOC);
$grouped  = [];
foreach ($allPerms as $p) $grouped[$p['module']][] = $p;

$rolePerms = db()->query("SELECT permission_id FROM role_permissions WHERE role_id=$id")->fetchAll(PDO::FETCH_COLUMN);

// Notification preferences
$notifTypes = ['email_attendance','email_payroll','email_letter','email_training',
               'push_attendance','push_payroll','push_letter','push_training',
               'sms_attendance','sms_payroll'];
$roleNotifs = db()->query("SELECT pref_key FROM user_notification_prefs WHERE role_id=$id")->fetchAll(PDO::FETCH_COLUMN);

// PWA module access
$pwaModules = ['dashboard','employees','attendance','payroll','letters','assets','training'];
$pwaEnabled = db()->query("SELECT module_key FROM pwa_module_access WHERE role_id=$id")->fetchAll(PDO::FETCH_COLUMN);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && can('roles', 'edit')) {
    verify_csrf($_POST['csrf_token'] ?? '');
    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $perms = $_POST['permissions'] ?? [];
    $notifs = $_POST['notifications'] ?? [];
    $pwa   = $_POST['pwa_modules'] ?? [];

    if (!$name) $errors[] = 'Role name is required.';

    if (empty($errors)) {
        if (!$role['is_system']) {
            db()->prepare("UPDATE roles SET name=:n, description=:d WHERE id=:id")
                 ->execute([':n'=>$name,':d'=>$desc,':id'=>$id]);
        }

        // Permissions
        db()->query("DELETE FROM role_permissions WHERE role_id=$id");
        foreach ($perms as $pid) {
            db()->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (:r,:p)")
                 ->execute([':r'=>$id,':p'=>(int)$pid]);
        }

        // Notifications
        db()->query("DELETE FROM user_notification_prefs WHERE role_id=$id");
        foreach ($notifs as $nk) {
            db()->prepare("INSERT INTO user_notification_prefs (role_id,pref_key) VALUES (:r,:k)")
                 ->execute([':r'=>$id,':k'=>$nk]);
        }

        // PWA
        db()->query("DELETE FROM pwa_module_access WHERE role_id=$id");
        foreach ($pwa as $mk) {
            db()->prepare("INSERT INTO pwa_module_access (role_id,module_key) VALUES (:r,:m)")
                 ->execute([':r'=>$id,':m'=>$mk]);
        }

        flash('success','Role updated successfully.');
        redirect(BASE_URL . "/modules/roles/edit.php?id=$id");
    }
    $rolePerms = $_POST['permissions'] ?? [];
    $roleNotifs = $_POST['notifications'] ?? [];
    $pwaEnabled = $_POST['pwa_modules'] ?? [];
}

$page_title = 'Edit Role: ' . $role['name'];
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= can('roles', 'edit') ? 'Edit Role' : 'View Role' ?>: <?= h($role['name']) ?></h1>
        <?php if ($role['is_system']): ?><p class="page-subtitle">System role — name cannot be changed</p><?php endif; ?>
    </div>
    <div class="page-actions">
        <a href="index.php" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>
<?php render_flash(); ?>

<form method="POST" id="roleForm">
    <?= csrf_field() ?>

    <!-- Tabs -->
    <div class="tabs mb-4">
        <button type="button" class="tab-btn active" onclick="switchTab(event,'permissions')">Permissions</button>
        <button type="button" class="tab-btn" onclick="switchTab(event,'notifications')">Notifications</button>
        <button type="button" class="tab-btn" onclick="switchTab(event,'pwa')">PWA Access</button>
        <?php if (!$role['is_system']): ?>
        <button type="button" class="tab-btn" onclick="switchTab(event,'details')">Details</button>
        <?php endif; ?>
    </div>

    <!-- Permissions Tab -->
    <div id="tab-permissions" class="tab-content">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">Module Permissions</h3>
                <?php if (can('roles', 'edit')): ?>
                <div>
                    <button type="button" class="btn btn-xs btn-secondary" onclick="toggleAllPerms(true)">Select All</button>
                    <button type="button" class="btn btn-xs btn-secondary" onclick="toggleAllPerms(false)">Clear All</button>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php foreach ($grouped as $module => $mperms): ?>
                <div class="perm-module mb-4">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <strong style="text-transform:capitalize"><?= $module ?></strong>
                        <?php if (can('roles', 'edit')): ?>
                        <button type="button" class="btn btn-xs btn-secondary" onclick="toggleModule('<?= $module ?>', true)">All</button>
                        <button type="button" class="btn btn-xs btn-secondary" onclick="toggleModule('<?= $module ?>', false)">None</button>
                        <?php endif; ?>
                    </div>
                    <div class="row">
                        <?php foreach ($mperms as $p): ?>
                        <div class="col-4 mb-1">
                            <label class="form-check">
                                <input type="checkbox" name="permissions[]" value="<?= $p['id'] ?>"
                                    class="perm-check perm-<?= $p['module'] ?>"
                                    <?= in_array($p['id'], $rolePerms) ? 'checked' : '' ?>
                                    <?= !can('roles', 'edit') ? 'disabled' : '' ?>>
                                <span><?= h($p['name']) ?></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <hr>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Notifications Tab -->
    <div id="tab-notifications" class="tab-content" style="display:none">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Notification Preferences</h3></div>
            <div class="card-body">
                <p class="text-muted">Select which notification types this role should receive:</p>
                <div class="row">
                    <?php
                    $notifLabels = [
                        'email_attendance' => ['Email','Attendance notifications'],
                        'email_payroll'    => ['Email','Payroll/slip ready'],
                        'email_letter'     => ['Email','Letter issued'],
                        'email_training'   => ['Email','Training enrolled/reminder'],
                        'push_attendance'  => ['Push','Attendance notifications'],
                        'push_payroll'     => ['Push','Payroll/slip ready'],
                        'push_letter'      => ['Push','Letter issued'],
                        'push_training'    => ['Push','Training enrolled/reminder'],
                        'sms_attendance'   => ['SMS','Attendance notifications'],
                        'sms_payroll'      => ['SMS','Payroll/slip ready'],
                    ];
                    foreach ($notifLabels as $key => $info): ?>
                    <div class="col-6 mb-2">
                        <label class="form-check">
                            <input type="checkbox" name="notifications[]" value="<?= $key ?>"
                                <?= in_array($key, $roleNotifs) ? 'checked' : '' ?>
                                <?= !can('roles', 'edit') ? 'disabled' : '' ?>>
                            <span class="pill pill-secondary" style="font-size:.65rem"><?= $info[0] ?></span>
                            <?= $info[1] ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- PWA Access Tab -->
    <div id="tab-pwa" class="tab-content" style="display:none">
        <div class="card">
            <div class="card-header"><h3 class="card-title">PWA Module Access</h3></div>
            <div class="card-body">
                <p class="text-muted">Select which modules are accessible on mobile/PWA for this role:</p>
                <div class="row">
                    <?php foreach ($pwaModules as $mod): ?>
                    <div class="col-4 mb-3">
                        <div class="card" style="border:2px solid <?= in_array($mod,$pwaEnabled)?'var(--success)':'var(--border)' ?>">
                            <div class="card-body text-center py-3">
                                <label class="form-check justify-content-center">
                                    <input type="checkbox" name="pwa_modules[]" value="<?= $mod ?>"
                                        <?= in_array($mod,$pwaEnabled)?'checked':'' ?>
                                        <?= !can('roles', 'edit')?'disabled':'' ?>
                                        onchange="this.closest('.card').style.borderColor=this.checked?'var(--success)':'var(--border)'">
                                    <strong style="text-transform:capitalize;margin-left:.5rem"><?= $mod ?></strong>
                                </label>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Details Tab -->
    <?php if (!$role['is_system']): ?>
    <div id="tab-details" class="tab-content" style="display:none">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Role Details</h3></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Role Name</label>
                    <input type="text" name="name" class="form-control" value="<?= h($role['name']) ?>" <?= !can('roles', 'edit')?'readonly':'' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3" <?= !can('roles', 'edit')?'readonly':'' ?>><?= h($role['description']) ?></textarea>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
        <!-- Hidden fields for system roles to not override name -->
        <input type="hidden" name="name" value="<?= h($role['name']) ?>">
        <input type="hidden" name="description" value="<?= h($role['description']) ?>">
    <?php endif; ?>

    <?php if (can('roles', 'edit')): ?>
    <div class="mt-4">
        <button type="submit" class="btn btn-primary" data-key="S"><u>S</u>ave Changes</button>
        <a href="index.php" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
    <?php endif; ?>
</form>

<script>
function switchTab(e, tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.style.display='none');
    e.target.classList.add('active');
    document.getElementById('tab-' + tab).style.display = 'block';
}
function toggleAllPerms(checked) {
    document.querySelectorAll('.perm-check:not([disabled])').forEach(c => c.checked = checked);
}
function toggleModule(mod, checked) {
    document.querySelectorAll('.perm-' + mod + ':not([disabled])').forEach(c => c.checked = checked);
}
addLocalShortcut('s', () => document.getElementById('roleForm').submit());
addLocalShortcut('b', () => location.href='index.php');
</script>
<?php include '../../includes/footer.php'; ?>
