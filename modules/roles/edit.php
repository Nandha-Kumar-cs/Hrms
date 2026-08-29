<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('roles', 'view');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/roles/index.php');

$role = db()->query("SELECT * FROM roles WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
if (!$role) redirect(BASE_URL . '/modules/roles/index.php');
// The `roles` table has no is_system column — protect only Super Admin (id 1).
$role['is_system'] = ((int)$role['id'] === 1) ? 1 : 0;

// `permissions` columns are (module, action, label) — there is no `name`.
$allPerms = db()->query("SELECT * FROM permissions ORDER BY module, action")->fetchAll(PDO::FETCH_ASSOC);
$grouped  = [];
foreach ($allPerms as $p) $grouped[$p['module']][] = $p;

$rolePerms = db()->query("SELECT permission_id FROM role_permissions WHERE role_id=$id")->fetchAll(PDO::FETCH_COLUMN);

// Notification preferences & PWA access are NOT role-scoped in this schema
// (user_notification_prefs is per-user; pwa_module_access is global), so these
// tabs are display-only and are not persisted per role.
$notifTypes = ['email_attendance','email_payroll','email_letter','email_training',
               'push_attendance','push_payroll','push_letter','push_training',
               'sms_attendance','sms_payroll'];
$roleNotifs = [];

$pwaModules = ['dashboard','employees','attendance','payroll','letters','assets','training'];
$pwaEnabled = [];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && can('roles', 'manage')) {
    verify_csrf($_POST['csrf_token'] ?? '');

    // Only a Super Admin may edit the Super Admin role.
    if ($role['is_system'] && !is_super_admin()) {
        flash('error', 'Only a Super Admin can edit this role.');
        redirect(BASE_URL . '/modules/roles/index.php');
    }

    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    // You can only grant permissions you already hold (prevents self-escalation).
    $perms = filter_assignable_permission_ids((array)($_POST['permissions'] ?? []));
    $notifs = $_POST['notifications'] ?? [];
    $pwa   = $_POST['pwa_modules'] ?? [];

    if (!$name) $errors[] = 'Role name is required.';
    // Non-Super-Admins cannot rename a role into ANY administrator role —
    // renaming an ordinary role to "Admin" was the same escalation as creating
    // one outright (security audit H-4).
    if (is_admin_role_name($name) && !is_super_admin())
        $errors[] = 'You are not allowed to give a role an administrator name.';

    // self_scope is a capability boundary, not a preference — it decides whether
    // the holder sees only themselves or the whole company. Filter it the same
    // way permissions are filtered above (security audit M-17).
    $selfScope = !empty($_POST['self_scope']) ? 1 : 0;
    $scopeErr  = self_scope_change_error($selfScope, (int)($role['self_scope'] ?? 0));
    if ($scopeErr !== null) {
        activity_log('updated', 'Role',
            'REFUSED a self_scope change on role #' . $id . ' (' . $role['name'] . '): actor is self-scoped.');
        $errors[]  = $scopeErr;
        $selfScope = (int)($role['self_scope'] ?? 0);   // keep what is already stored
    }

    if (empty($errors)) {
        if (!$role['is_system']) {
            db()->prepare("UPDATE roles SET name=:n, description=:d, self_scope=:s WHERE id=:id")
                 ->execute([':n'=>$name,':d'=>$desc,':s'=>$selfScope,':id'=>$id]);
        }

        // Permissions
        db()->query("DELETE FROM role_permissions WHERE role_id=$id");
        foreach ($perms as $pid) {
            db()->prepare("INSERT INTO role_permissions (role_id,permission_id) VALUES (:r,:p)")
                 ->execute([':r'=>$id,':p'=>(int)$pid]);
        }

        // Notifications & PWA access are not role-scoped in this schema, so they
        // are not persisted here (see note above where they are loaded).

        flash('success','Role permissions updated successfully.');
        redirect(BASE_URL . "/modules/roles/edit.php?id=$id");
    }
    $rolePerms = $_POST['permissions'] ?? [];
    $roleNotifs = $_POST['notifications'] ?? [];
    $pwaEnabled = $_POST['pwa_modules'] ?? [];
    $role['self_scope'] = $selfScope;   // keep checkbox state on re-render
}

$page_title = 'Edit Role: ' . $role['name'];
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= can('roles', 'manage') ? 'Edit Role' : 'View Role' ?>: <?= h($role['name']) ?></h1>
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
    <div class="role-tabs mb-4">
        <button type="button" class="tab-btn active" onclick="switchTab(event,'permissions')"><i class="fa fa-shield-halved"></i> Permissions</button>
        <button type="button" class="tab-btn" onclick="switchTab(event,'notifications')"><i class="fa fa-bell"></i> Notifications</button>
        <button type="button" class="tab-btn" onclick="switchTab(event,'pwa')"><i class="fa fa-mobile-screen"></i> PWA Access</button>
        <?php if (!$role['is_system']): ?>
        <button type="button" class="tab-btn" onclick="switchTab(event,'details')"><i class="fa fa-circle-info"></i> Details</button>
        <?php endif; ?>
    </div>

    <!-- Permissions Tab -->
    <div id="tab-permissions" class="tab-content">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">Module Permissions</h3>
                <?php if (can('roles', 'manage')): ?>
                <div>
                    <button type="button" class="btn btn-xs btn-secondary" onclick="toggleAllPerms(true)">Select All</button>
                    <button type="button" class="btn btn-xs btn-secondary" onclick="toggleAllPerms(false)">Clear All</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="perm-grid">
            <?php foreach ($grouped as $module => $mperms): ?>
            <div class="card perm-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><?= h(module_label($module)) ?></strong>
                    <?php if (can('roles', 'manage')): ?>
                    <span class="perm-card-actions">
                        <button type="button" class="btn btn-xs btn-secondary" onclick="toggleModule('<?= $module ?>', true)">All</button>
                        <button type="button" class="btn btn-xs btn-secondary" onclick="toggleModule('<?= $module ?>', false)">None</button>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php foreach ($mperms as $p): $hint = permission_hint($p['module'], $p['action']); ?>
                    <label class="form-check perm-row">
                        <input type="checkbox" name="permissions[]" value="<?= $p['id'] ?>"
                            class="perm-check perm-<?= $p['module'] ?>"
                            <?= in_array($p['id'], $rolePerms) ? 'checked' : '' ?>
                            <?= !can('roles', 'manage') ? 'disabled' : '' ?>>
                        <span><?= h($p['label'] ?: ucfirst($p['action'])) ?></span>
                        <?php if ($hint !== ''): ?><i class="fa fa-eye perm-hint" title="<?= h($hint) ?>"></i><?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
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
    /* Role edit tabs */
    .role-tabs { display:flex; flex-wrap:wrap; gap:4px; border-bottom:2px solid #e2e8f0; }
    .role-tabs .tab-btn {
        appearance:none; background:transparent; border:none; border-bottom:2px solid transparent;
        margin-bottom:-2px; padding:10px 18px; font-size:.9rem; font-weight:600; color:#64748b;
        cursor:pointer; display:inline-flex; align-items:center; gap:8px;
        border-radius:6px 6px 0 0; transition:all .15s ease;
    }
    .role-tabs .tab-btn i { font-size:.85rem; opacity:.8; }
    .role-tabs .tab-btn:hover { color:#1e3a8a; background:#f1f5f9; }
    .role-tabs .tab-btn.active { color:#1e3a8a; border-bottom-color:#1e3a8a; background:#fff; }
    @media (max-width: 900px){ .perm-grid { grid-template-columns:1fr 1fr; } }
    @media (max-width: 600px){ .perm-grid { grid-template-columns:1fr; } }
    </style>

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
                                <?= !can('roles', 'manage') ? 'disabled' : '' ?>>
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
                                        <?= !can('roles', 'manage')?'disabled':'' ?>
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
                    <input type="text" name="name" class="form-control" value="<?= h($role['name']) ?>" <?= !can('roles', 'manage')?'readonly':'' ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3" <?= !can('roles', 'manage')?'readonly':'' ?>><?= h($role['description']) ?></textarea>
                </div>
                <hr>
                <div class="form-group">
                    <label class="form-label">Data Access Scope</label>
                    <label class="form-check">
                        <input type="checkbox" name="self_scope" value="1"
                            <?= !empty($role['self_scope']) ? 'checked' : '' ?>
                            <?= (!can('roles', 'manage') || is_self_scoped()) ? 'disabled' : '' ?>>
                        <span>Restrict to own records only (self-service)</span>
                    </label>
                    <?php if (is_self_scoped()): ?>
                    <p class="text-muted mt-1" style="font-size:.8rem">
                        Your own access is limited to your employee record, so you cannot change
                        this setting (security audit M-17).
                    </p>
                    <?php endif; ?>
                    <p class="text-muted mt-1" style="font-size:.8rem">
                        When enabled, users with this role see only their <strong>own</strong> employee
                        data across every list, report, profile and tab — they cannot view other
                        employees' details. Leave unchecked for management roles that need full access.
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
        <!-- Hidden fields for system roles to not override name -->
        <input type="hidden" name="name" value="<?= h($role['name']) ?>">
        <input type="hidden" name="description" value="<?= h($role['description']) ?>">
    <?php endif; ?>

    <?php if (can('roles', 'manage')): ?>
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
