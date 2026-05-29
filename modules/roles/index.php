<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('roles', 'view');

$roles = db()->query("SELECT r.*, COUNT(DISTINCT u.id) AS user_count,
    COUNT(DISTINCT rp.permission_id) AS perm_count
    FROM roles r
    LEFT JOIN users u ON u.role_id = r.id
    LEFT JOIN role_permissions rp ON rp.role_id = r.id
    GROUP BY r.id ORDER BY r.name")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Roles & Permissions';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Roles &amp; Permissions</h1>
        <p class="page-subtitle">Manage system roles and access control</p>
    </div>
    <div class="page-actions">
        <?php if (can('roles', 'manage')): ?>
            <a href="create.php" class="btn btn-primary" data-key="N"><u>N</u>ew Role</a>
        <?php endif; ?>
    </div>
</div>

<?php render_flash(); ?>

<div class="row">
    <?php foreach ($roles as $r): ?>
    <div class="col-4 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title"><?= h($r['name']) ?></h3>
                <?php if ($r['is_system'] ?? false): ?>
                    <span class="pill pill-primary" style="font-size:.65rem">System</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($r['description']): ?>
                    <p class="text-muted mb-3"><?= h($r['description']) ?></p>
                <?php endif; ?>
                <div class="row text-center">
                    <div class="col-6">
                        <div class="stat-value"><?= $r['user_count'] ?></div>
                        <div class="stat-label">Users</div>
                    </div>
                    <div class="col-6">
                        <div class="stat-value"><?= $r['perm_count'] ?></div>
                        <div class="stat-label">Permissions</div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <a href="edit.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-primary">
                    <?= can('roles', 'manage') ? 'Edit Permissions' : 'View Permissions' ?>
                </a>
                <?php if (can('roles', 'manage') && !($r['is_system'] ?? false)): ?>
                    <form method="POST" action="delete.php" onsubmit="return confirm('Delete this role?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <button class="btn btn-sm btn-danger">Delete</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Permission Reference Card -->
<div class="card mt-2">
    <div class="card-header"><h3 class="card-title">Permission Reference</h3></div>
    <div class="card-body">
        <?php
        $perms = db()->query("SELECT * FROM permissions ORDER BY module, action")->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        foreach ($perms as $p) $grouped[$p['module']][] = $p;
        ?>
        <div class="row">
            <?php foreach ($grouped as $module => $mperms): ?>
            <div class="col-4 mb-3">
                <strong><?= ucfirst($module) ?></strong>
                <ul class="mt-1" style="padding-left:1.2rem;font-size:.8rem">
                    <?php foreach ($mperms as $p): ?>
                        <li><?= h($p['action']) ?> — <span class="text-muted"><?= h($p['label'] ?? '') ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
if (typeof addLocalShortcut === 'function') addLocalShortcut('n', () => location.href='create.php');
</script>
<?php include '../../includes/footer.php'; ?>
