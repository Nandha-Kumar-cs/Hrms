<?php
/**
 * Settings → User Management.
 *
 * Admin creates/edits/deletes users, assigns a role (baseline permissions) and
 * ticks individual extra permissions per user (stored in user_permissions).
 * Effective access = role permissions OR direct user permissions (see
 * includes/permissions.php::has_permission). Super Admin bypasses everything.
 *
 * Screens: list (default), ?new=1, ?edit=ID. Writes run before output (PRG).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('users', 'view');

$db        = db();
$me        = current_user();
$canCreate = can('users', 'create');
$canEdit   = can('users', 'edit');
$canDelete = can('users', 'delete');
$self      = BASE_URL . '/modules/settings/users.php';

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        if (!$canDelete) { http_response_code(403); exit('Forbidden'); }
        $uid = (int)($_POST['id'] ?? 0);
        $t = $db->prepare('SELECT u.id, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.id=?');
        $t->execute([$uid]); $tgt = $t->fetch();
        if (!$tgt)                         flash('error', 'User not found.');
        elseif ($uid === (int)$me['id'])   flash('error', 'You cannot delete your own account.');
        elseif ($tgt['role_name'] === 'Super Admin') flash('error', 'Super Admin accounts cannot be deleted.');
        else {
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);   // user_permissions cascade
            activity_log('deleted', 'User', 'Deleted user #' . $uid);
            flash('success', 'User deleted.');
        }
        redirect($self);
    }

    if ($action === 'toggle') {
        if (!$canEdit) { http_response_code(403); exit('Forbidden'); }
        $uid = (int)($_POST['id'] ?? 0);
        if ($uid === (int)$me['id']) flash('error', 'You cannot change your own status.');
        else { $db->prepare('UPDATE users SET is_active = 1 - is_active WHERE id=?')->execute([$uid]); flash('success', 'User status updated.'); }
        redirect($self);
    }

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if (!($action === 'create' ? $canCreate : $canEdit)) { http_response_code(403); exit('Forbidden'); }

        $name   = sanitize($_POST['name'] ?? '');
        $email  = trim((string)($_POST['email'] ?? ''));
        $pw     = (string)($_POST['password'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        $empId  = (int)($_POST['employee_id'] ?? 0) ?: null;
        $active = isset($_POST['is_active']) ? 1 : 0;
        $perms  = array_unique(array_map('intval', (array)($_POST['permissions'] ?? [])));

        $errors = [];
        if ($name === '') $errors[] = 'Name is required.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
        else { $dc = $db->prepare('SELECT id FROM users WHERE email=? AND id<>?'); $dc->execute([$email, $id]); if ($dc->fetchColumn()) $errors[] = 'That email is already in use.'; }
        if (!$roleId) $errors[] = 'Please select a role.';
        else { $rc = $db->prepare('SELECT 1 FROM roles WHERE id=?'); $rc->execute([$roleId]); if (!$rc->fetchColumn()) $errors[] = 'Invalid role.'; }
        if ($action === 'create' && strlen($pw) < 6) $errors[] = 'Password must be at least 6 characters.';
        if ($action === 'update' && $pw !== '' && strlen($pw) < 6) $errors[] = 'Password must be at least 6 characters.';

        if ($errors) {
            flash('error', implode(' ', $errors));
            $_SESSION['user_old'] = $_POST;
            redirect($self . ($id ? '?edit=' . $id : '?new=1'));
        }

        if ($action === 'create') {
            $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare('INSERT INTO users (email,name,password_hash,role_id,employee_id,is_active,created_at) VALUES (?,?,?,?,?,?,NOW())')
               ->execute([$email, $name, $hash, $roleId, $empId, $active]);
            $id = (int)$db->lastInsertId();
            activity_log('created', 'User', "Created user: $name ($email)");
        } else {
            if ($id === (int)$me['id']) $active = 1;   // never lock yourself out
            if ($pw !== '') {
                $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare('UPDATE users SET name=?,email=?,role_id=?,employee_id=?,is_active=?,password_hash=? WHERE id=?')
                   ->execute([$name, $email, $roleId, $empId, $active, $hash, $id]);
            } else {
                $db->prepare('UPDATE users SET name=?,email=?,role_id=?,employee_id=?,is_active=? WHERE id=?')
                   ->execute([$name, $email, $roleId, $empId, $active, $id]);
            }
            activity_log('updated', 'User', "Updated user: $name ($email)");
        }

        // Sync direct permissions — keep only those NOT already granted by the role.
        $rp = $db->prepare('SELECT permission_id FROM role_permissions WHERE role_id=?'); $rp->execute([$roleId]);
        $roleSet = array_flip(array_map('intval', $rp->fetchAll(PDO::FETCH_COLUMN)));
        $db->prepare('DELETE FROM user_permissions WHERE user_id=?')->execute([$id]);
        $ins = $db->prepare('INSERT IGNORE INTO user_permissions (user_id,permission_id) VALUES (?,?)');
        foreach ($perms as $pid) { if ($pid && !isset($roleSet[$pid])) $ins->execute([$id, $pid]); }

        flash('success', $action === 'create' ? 'User created.' : 'User updated.');
        redirect($self);
    }
    redirect($self);
}

// ── Screen data ──────────────────────────────────────────────────────────────
$screen = isset($_GET['new']) ? 'new' : (isset($_GET['edit']) ? 'edit' : 'list');
if ($screen === 'new'  && !$canCreate) $screen = 'list';

$roles      = $db->query('SELECT id, name FROM roles ORDER BY name')->fetchAll();
$allPerms   = $db->query('SELECT id, module, action, label FROM permissions ORDER BY module, action')->fetchAll();
$permByMod  = [];
foreach ($allPerms as $p) $permByMod[$p['module']][] = $p;
$rolePermsMap = [];
foreach ($db->query('SELECT role_id, permission_id FROM role_permissions') as $r) $rolePermsMap[(int)$r['role_id']][] = (int)$r['permission_id'];
$employees  = $db->query("SELECT id, name, employee_id FROM employees WHERE status='Active' ORDER BY name")->fetchAll();

$edit = null; $userPerms = [];
if ($screen === 'edit') {
    $eid = (int)($_GET['edit'] ?? 0);
    $es = $db->prepare('SELECT * FROM users WHERE id=?'); $es->execute([$eid]); $edit = $es->fetch();
    if (!$edit || !$canEdit) { $screen = $edit ? 'view' : 'list'; }
    if ($edit) { $up = $db->prepare('SELECT permission_id FROM user_permissions WHERE user_id=?'); $up->execute([$eid]); $userPerms = array_map('intval', $up->fetchAll(PDO::FETCH_COLUMN)); }
}
$old = $_SESSION['user_old'] ?? []; unset($_SESSION['user_old']);
$val = function (string $k, $d = '') use ($old, $edit) {
    if (array_key_exists($k, $old)) return $old[$k];
    if ($edit && array_key_exists($k, $edit)) return $edit[$k];
    return $d;
};

$page_title = 'User Management';
require_once __DIR__ . '/../../includes/header.php';
render_flash();

/* ═══════════ CREATE / EDIT FORM ═══════════ */
if ($screen === 'new' || $screen === 'edit'):
    $curRole = (int)$val('role_id', 0);
?>
<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0 fw-semibold"><?= $screen === 'new' ? 'Add User' : 'Edit User' ?></h4>
    <a href="<?= $self ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $screen === 'new' ? 'create' : 'update' ?>">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card page-card">
                <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold">Account</h6></div>
                <div class="card-body">
                    <div class="mb-3"><label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= h($val('name')) ?>" required></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" value="<?= h($val('email')) ?>" required></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Password <?= $screen === 'new' ? '<span class="text-danger">*</span>' : '<span class="text-muted fw-normal">(leave blank to keep)</span>' ?></label>
                        <input type="password" name="password" class="form-control" autocomplete="new-password" <?= $screen === 'new' ? 'required' : '' ?>></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                        <select name="role_id" id="roleSel" class="form-select" required>
                            <option value="">Select role</option>
                            <?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>" <?= $curRole === (int)$r['id'] ? 'selected' : '' ?>><?= h($r['name']) ?></option><?php endforeach; ?>
                        </select>
                        <small class="text-muted">The role grants baseline permissions; tick extra ones on the right.</small></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Linked Employee <span class="text-muted fw-normal">(optional)</span></label>
                        <select name="employee_id" class="form-select">
                            <option value="">— None —</option>
                            <?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>" <?= (int)$val('employee_id') === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['name']) ?> (<?= h($e['employee_id']) ?>)</option><?php endforeach; ?>
                        </select></div>
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" name="is_active" id="activeChk" <?= ($edit ? (int)$edit['is_active'] : 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="activeChk">Active (can log in)</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card page-card">
                <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
                    <h6 class="mb-0 fw-semibold">Permissions</h6>
                    <span class="small text-muted">Greyed = granted by role · tick others for this user</span>
                </div>
                <div class="card-body" style="max-height:520px;overflow-y:auto">
                    <?php foreach ($permByMod as $module => $perms): ?>
                    <div class="mb-3 pb-2 border-bottom">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <strong style="text-transform:capitalize"><?= h($module) ?></strong>
                            <a href="#" class="small mod-all" data-mod="<?= h($module) ?>">all</a><span class="text-muted small">·</span>
                            <a href="#" class="small mod-none" data-mod="<?= h($module) ?>">none</a>
                        </div>
                        <div class="row g-1">
                            <?php foreach ($perms as $p):
                                $pid = (int)$p['id'];
                                $byRole = in_array($pid, $rolePermsMap[$curRole] ?? [], true);
                                $byUser = in_array($pid, $userPerms, true);
                            ?>
                            <div class="col-md-4 col-6">
                                <label class="form-check">
                                    <input type="checkbox" class="form-check-input perm-check perm-mod-<?= h($module) ?>"
                                           name="permissions[]" value="<?= $pid ?>" data-pid="<?= $pid ?>"
                                           data-user="<?= $byUser ? 1 : 0 ?>"
                                           <?= ($byRole || $byUser) ? 'checked' : '' ?> <?= $byRole ? 'disabled' : '' ?>>
                                    <span class="ms-1 small"><?= h(ucfirst($p['action'])) ?><?= $byRole ? ' <span class="badge bg-secondary" style="font-size:.6rem">role</span>' : '' ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="mt-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i><?= $screen === 'new' ? 'Create User' : 'Save Changes' ?></button>
        <a href="<?= $self ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
<script>
window.ROLE_PERMS = <?= json_encode(array_map(fn($a) => array_values($a), $rolePermsMap)) ?>;
(function () {
    var roleSel = document.getElementById('roleSel');
    function applyRole() {
        var rid = roleSel.value;
        var rp = (window.ROLE_PERMS[rid] || []);
        document.querySelectorAll('.perm-check').forEach(function (c) {
            var pid = parseInt(c.dataset.pid, 10);
            var byRole = rp.indexOf(pid) !== -1;
            if (byRole) { c.checked = true; c.disabled = true; }
            else { c.disabled = false; c.checked = (c.dataset.user === '1'); }
            var badge = c.parentNode.querySelector('.badge');
            if (badge) badge.style.display = byRole ? '' : 'none';
        });
    }
    roleSel.addEventListener('change', function () {
        // remember manual user ticks before recompute
        document.querySelectorAll('.perm-check').forEach(function (c) { if (!c.disabled) c.dataset.user = c.checked ? '1' : '0'; });
        applyRole();
    });
    document.querySelectorAll('.mod-all').forEach(a => a.addEventListener('click', function (e) { e.preventDefault(); document.querySelectorAll('.perm-mod-' + this.dataset.mod + ':not([disabled])').forEach(c => c.checked = true); }));
    document.querySelectorAll('.mod-none').forEach(a => a.addEventListener('click', function (e) { e.preventDefault(); document.querySelectorAll('.perm-mod-' + this.dataset.mod + ':not([disabled])').forEach(c => c.checked = false); }));
})();
</script>

<?php /* ═══════════ LIST ═══════════ */
else:
    $users = $db->query(
        "SELECT u.id, u.name, u.email, u.is_active, u.last_login, r.name AS role_name, e.name AS emp_name
         FROM users u LEFT JOIN roles r ON r.id=u.role_id LEFT JOIN employees e ON e.id=u.employee_id
         ORDER BY u.name"
    )->fetchAll();
?>
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h4 class="mb-0 fw-semibold">User Management</h4>
        <p class="text-muted small mb-0"><?= count($users) ?> user<?= count($users) === 1 ? '' : 's' ?></p>
    </div>
    <?php if ($canCreate): ?><a href="?new=1" class="btn btn-primary"><i class="fa fa-user-plus me-1"></i>Add User</a><?php endif; ?>
</div>
<div class="card page-card"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-hover align-middle mb-0" id="usersTable">
        <thead class="table-light"><tr>
            <th>Name</th><th>Email</th><th>Role</th><th>Employee</th><th class="text-center">Status</th><th>Last Login</th><th class="text-end">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($users as $u): $isSelf = (int)$u['id'] === (int)$me['id']; ?>
            <tr>
                <td class="fw-semibold"><?= h($u['name']) ?><?= $isSelf ? ' <span class="badge bg-info">you</span>' : '' ?></td>
                <td class="small"><?= h($u['email']) ?></td>
                <td><span class="badge bg-<?= $u['role_name'] === 'Super Admin' ? 'danger' : 'primary' ?>"><?= h($u['role_name'] ?? '—') ?></span></td>
                <td class="small text-muted"><?= h($u['emp_name'] ?? '') ?: '—' ?></td>
                <td class="text-center">
                    <?php if ($canEdit && !$isSelf): ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> this user?')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <button class="btn btn-sm <?= $u['is_active'] ? 'btn-success' : 'btn-outline-secondary' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></button>
                    </form>
                    <?php else: ?><span class="badge bg-<?= $u['is_active'] ? 'success' : 'secondary' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span><?php endif; ?>
                </td>
                <td class="small text-muted"><?= $u['last_login'] ? date_fmt($u['last_login'], 'd M Y H:i') : 'Never' ?></td>
                <td class="text-end text-nowrap">
                    <?php if ($canEdit): ?><a href="?edit=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-pen"></i></a><?php endif; ?>
                    <?php if ($canDelete && !$isSelf && $u['role_name'] !== 'Super Admin'): ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete user &quot;<?= h($u['name']) ?>&quot;? This cannot be undone.')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div></div>
<?php $page_scripts = <<<'JS'
<script>$(function(){ if($.fn.DataTable) $('#usersTable').DataTable({pageLength:25, order:[[0,'asc']], columnDefs:[{orderable:false,targets:[4,6]}], language:{search:'',searchPlaceholder:'Search users…'}}); });</script>
JS;
endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
