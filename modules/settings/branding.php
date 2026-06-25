<?php
/**
 * Branding Settings — choose the sidebar brand NAME and brand LOGO.
 *   brand_name → app_settings('brand_name')   (default "HRMS")
 *   brand_logo → app_settings('brand_logo')    (file in /storage/branding/)
 * The sidebar (includes/header.php) reads both. Logo falls back to an entity
 * logo, then the bundled MagDyn SVG, when none is uploaded.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('settings', 'view');

$db       = db();
$self     = BASE_URL . '/modules/settings/branding.php';
$LOGO_DIR = BASE_PATH . '/storage/branding';
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { flash('error', 'Invalid or expired request.'); redirect($self); }
    $action = $_POST['action'] ?? 'save';

    // Remove the current logo → revert to default (entity logo / MagDyn SVG).
    if ($action === 'remove_logo') {
        $cur = (string) setting_get('brand_logo', '');
        if ($cur && is_file($LOGO_DIR . '/' . $cur)) @unlink($LOGO_DIR . '/' . $cur);
        setting_set('brand_logo', '');
        flash('success', 'Brand logo removed — the default logo will be used.');
        redirect($self);
    }

    // Save brand name (+ optional new logo).
    $name = trim($_POST['brand_name'] ?? '');
    if ($name === '')               $errors[] = 'Brand name is required.';
    if (mb_strlen($name) > 40)      $errors[] = 'Brand name must be 40 characters or fewer.';

    $newLogo = null;
    if (!empty($_FILES['brand_logo']['name']) && ($_FILES['brand_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $f = $_FILES['brand_logo'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Logo upload failed (code ' . (int) $f['error'] . ').';
        } else {
            $ext     = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
            if (!in_array($ext, $allowed, true))      $errors[] = 'Logo must be PNG, JPG, GIF, WEBP or SVG.';
            elseif ($f['size'] > 2 * 1024 * 1024)     $errors[] = 'Logo must be under 2 MB.';
            else {
                if (!is_dir($LOGO_DIR)) @mkdir($LOGO_DIR, 0775, true);
                $newLogo = 'brand_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                if (!move_uploaded_file($f['tmp_name'], $LOGO_DIR . '/' . $newLogo)) {
                    $errors[] = 'Could not save the uploaded logo.';
                    $newLogo  = null;
                }
            }
        }
    }

    if (!$errors) {
        setting_set('brand_name', $name);
        if ($newLogo) {
            $old = (string) setting_get('brand_logo', '');
            if ($old && is_file($LOGO_DIR . '/' . $old)) @unlink($LOGO_DIR . '/' . $old);
            setting_set('brand_logo', $newLogo);
        }
        flash('success', 'Branding saved.');
        redirect($self);
    }

    $_SESSION['errors'] = $errors;
    redirect($self);
}

$brandName  = (string) setting_get('brand_name', 'HRMS');
$brandLogo  = (string) setting_get('brand_logo', '');
if ($brandLogo && is_file($LOGO_DIR . '/' . $brandLogo)) {
    $logoUrl = BASE_URL . '/storage/branding/' . $brandLogo;
} else {
    $logoUrl = BASE_URL . '/storage/branding/default_brand.png';
}
$errors     = $_SESSION['errors'] ?? [];
unset($_SESSION['errors']);

$page_title = 'Branding';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Branding</h1>
        <p class="muted">Set the brand name and logo shown in the sidebar.</p>
    </div>
    <div class="head-actions">
        <a href="<?= BASE_URL ?>/modules/settings/office.php" class="btn btn-ghost">Office Settings</a>
    </div>
</div>

<?= render_flash() ?>

<?php if ($errors): ?>
<div class="alert" style="background:#fdecea;border:1px solid #f5c2c0;border-radius:var(--radius);padding:12px 16px;margin-bottom:16px">
    <ul style="margin:0;padding-left:18px">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card" style="max-width:640px">
    <div class="card-head"><h3>Sidebar Brand</h3></div>
    <div style="padding:20px">
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">

            <div class="mb-3">
                <label class="form-label">Brand Name <span class="text-danger">*</span></label>
                <input type="text" name="brand_name" class="form-control" maxlength="40" required
                       value="<?= h($brandName) ?>" style="max-width:320px">
                <div class="form-text">Shown next to the logo in the sidebar (e.g. “HRMS”, “MagDyn”).</div>
            </div>

            <div class="mb-3">
                <label class="form-label">Brand Logo</label>
                <div style="display:flex;align-items:center;gap:16px;margin-bottom:8px">
                    <div style="width:56px;height:56px;background:#0f172a;border-radius:8px;display:flex;align-items:center;justify-content:center">
                        <img src="<?= h($logoUrl) ?>" alt="Current logo" style="width:48px;height:48px;object-fit:contain">
                    </div>
                    <div class="form-text" style="margin:0">
                        Current logo<?= $brandLogo ? '' : ' (default)' ?>.
                    </div>
                </div>
                <input type="file" name="brand_logo" class="form-control" accept=".png,.jpg,.jpeg,.gif,.webp,.svg" style="max-width:320px">
                <div class="form-text">PNG, JPG, GIF, WEBP or SVG, under 2 MB. Leave empty to keep the current logo.</div>
            </div>

            <div style="display:flex;gap:8px;margin-top:16px">
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Branding</button>
                <?php if ($brandLogo): ?>
                <button type="submit" name="action" value="remove_logo" class="btn btn-outline-danger"
                        onclick="return confirm('Remove the custom logo and use the default?')">
                    Remove Logo
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
