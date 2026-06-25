<?php
/**
 * Settings tab: Entities — CRUD with logo upload.
 * Logos are stored under /storage/entities/ (header.php reads them from there).
 * Included by ../index.php for the JSON phase (?ajax=1) and the view phase.
 */
if (!defined('IN_SETTINGS')) { http_response_code(403); exit('Forbidden'); }

$db = db();

$LOGO_DIR     = BASE_PATH . '/storage/entities';
$LOGO_EXT     = ['jpg','jpeg','png','gif','webp','svg'];
$LOGO_MAX     = 2 * 1024 * 1024; // 2 MB

// ─── AJAX / JSON ─────────────────────────────────────────────────────────────
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    $id     = (int)($_GET['id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid or expired request.']);
            exit;
        }

        if ($action === 'delete' && $id) {
            $cur = $db->prepare('SELECT logo FROM entities WHERE id = ?');
            $cur->execute([$id]);
            $oldLogo = $cur->fetchColumn();
            $db->prepare('DELETE FROM entities WHERE id = ?')->execute([$id]);
            if ($oldLogo && is_file($LOGO_DIR . '/' . $oldLogo)) @unlink($LOGO_DIR . '/' . $oldLogo);
            echo json_encode(['success' => true, 'message' => 'Entity deleted.']);
            exit;
        }

        $name      = trim($_POST['name'] ?? '');
        $address   = trim($_POST['address'] ?? '');
        $city      = trim($_POST['city'] ?? '');
        $state     = trim($_POST['state'] ?? '');
        $pincode   = trim($_POST['pincode'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $website   = trim($_POST['website'] ?? '');
        $sigTitle  = trim($_POST['signatory_title'] ?? '');

        $errors = [];
        if ($name === '')                                           $errors[] = 'Name is required.';
        if (mb_strlen($name) > 150)                                 $errors[] = 'Name must be 150 characters or fewer.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is not valid.';
        if (mb_strlen($pincode) > 10)                               $errors[] = 'Pincode must be 10 characters or fewer.';

        // Logo upload (optional)
        $newLogo = null;
        if (!$errors && !empty($_FILES['logo']['name']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['logo'];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Logo upload failed.';
            } elseif ($f['size'] > $LOGO_MAX) {
                $errors[] = 'Logo must be 2 MB or smaller.';
            } else {
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $LOGO_EXT, true)) {
                    $errors[] = 'Logo must be a JPG, PNG, GIF, WEBP or SVG file.';
                } else {
                    if (!is_dir($LOGO_DIR)) @mkdir($LOGO_DIR, 0775, true);
                    $newLogo = 'entity_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], $LOGO_DIR . '/' . $newLogo)) {
                        $errors[] = 'Could not save the uploaded logo.';
                        $newLogo = null;
                    }
                }
            }
        }

        // Signature image upload (optional) — stored alongside logos.
        $newSig = null;
        if (!$errors && !empty($_FILES['signature']['name']) && $_FILES['signature']['error'] !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES['signature'];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Signature upload failed.';
            } elseif ($f['size'] > $LOGO_MAX) {
                $errors[] = 'Signature must be 2 MB or smaller.';
            } else {
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $LOGO_EXT, true)) {
                    $errors[] = 'Signature must be a JPG, PNG, GIF, WEBP or SVG file.';
                } else {
                    if (!is_dir($LOGO_DIR)) @mkdir($LOGO_DIR, 0775, true);
                    $newSig = 'sign_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], $LOGO_DIR . '/' . $newSig)) {
                        $errors[] = 'Could not save the uploaded signature.';
                        $newSig = null;
                    }
                }
            }
        }

        if ($errors) {
            if ($newLogo && is_file($LOGO_DIR . '/' . $newLogo)) @unlink($LOGO_DIR . '/' . $newLogo);
            if ($newSig  && is_file($LOGO_DIR . '/' . $newSig))  @unlink($LOGO_DIR . '/' . $newSig);
            echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
            exit;
        }

        if ($action === 'create') {
            $st = $db->prepare(
                'INSERT INTO entities (name, address, city, state, pincode, phone, email, website, signatory_title, logo, signature)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            $st->execute([$name, $address, $city, $state, $pincode, $phone, $email, $website, $sigTitle, $newLogo, $newSig]);
            echo json_encode(['success' => true, 'message' => 'Entity created.']);
            exit;
        }
        if ($action === 'update' && $id) {
            $cur = $db->prepare('SELECT logo, signature FROM entities WHERE id = ?');
            $cur->execute([$id]);
            $old      = $cur->fetch();
            $oldLogo  = $old['logo'] ?? null;
            $oldSig   = $old['signature'] ?? null;
            $logo     = $newLogo ?: $oldLogo;
            $sig      = $newSig  ?: $oldSig;
            $db->prepare(
                'UPDATE entities SET name=?, address=?, city=?, state=?, pincode=?, phone=?, email=?, website=?, signatory_title=?, logo=?, signature=? WHERE id=?'
            )->execute([$name, $address, $city, $state, $pincode, $phone, $email, $website, $sigTitle, $logo, $sig, $id]);
            if ($newLogo && $oldLogo && $oldLogo !== $newLogo && is_file($LOGO_DIR . '/' . $oldLogo)) @unlink($LOGO_DIR . '/' . $oldLogo);
            if ($newSig  && $oldSig  && $oldSig  !== $newSig  && is_file($LOGO_DIR . '/' . $oldSig))  @unlink($LOGO_DIR . '/' . $oldSig);
            echo json_encode(['success' => true, 'message' => 'Entity updated.']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// ─── VIEW ────────────────────────────────────────────────────────────────────
$rows = $db->query('SELECT * FROM entities ORDER BY name')->fetchAll();
?>
<div class="card page-card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h5 class="mb-0 fw-semibold"><i class="fa fa-building-flag me-2 text-primary"></i>Entities</h5>
        <button class="btn btn-primary btn-sm" id="btnAddEnt"><i class="fa fa-plus me-1"></i>Add Entity</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0" id="entTable">
                <thead class="table-dark">
                    <tr><th style="width:60px">#</th><th style="width:70px">Logo</th><th>Name</th><th>Location</th><th>Contact</th><th class="text-center" style="width:130px">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <?php if (!empty($r['logo']) && is_file($LOGO_DIR . '/' . $r['logo'])): ?>
                                <img src="<?= BASE_URL ?>/storage/entities/<?= h($r['logo']) ?>" alt="" style="height:34px;max-width:60px;object-fit:contain">
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="fw-semibold"><?= h($r['name']) ?></td>
                        <td><?= h(trim(($r['city'] ?? '') . ($r['city'] && $r['state'] ? ', ' : '') . ($r['state'] ?? ''))) ?: '—' ?></td>
                        <td>
                            <?php if (!empty($r['phone'])): ?><div><i class="fa fa-phone fa-xs text-muted me-1"></i><?= h($r['phone']) ?></div><?php endif; ?>
                            <?php if (!empty($r['email'])): ?><div><i class="fa fa-envelope fa-xs text-muted me-1"></i><?= h($r['email']) ?></div><?php endif; ?>
                            <?php if (empty($r['phone']) && empty($r['email'])): ?>—<?php endif; ?>
                        </td>
                        <td class="text-center text-nowrap">
                            <button class="btn btn-sm btn-outline-primary btn-edit-ent"
                                    data-id="<?= $r['id'] ?>"
                                    data-name="<?= h($r['name']) ?>"
                                    data-address="<?= h($r['address'] ?? '') ?>"
                                    data-city="<?= h($r['city'] ?? '') ?>"
                                    data-state="<?= h($r['state'] ?? '') ?>"
                                    data-pincode="<?= h($r['pincode'] ?? '') ?>"
                                    data-phone="<?= h($r['phone'] ?? '') ?>"
                                    data-email="<?= h($r['email'] ?? '') ?>"
                                    data-website="<?= h($r['website'] ?? '') ?>"
                                    data-signature="<?= h($r['signature'] ?? '') ?>"
                                    data-sigtitle="<?= h($r['signatory_title'] ?? '') ?>"><i class="fa fa-pen"></i></button>
                            <button class="btn btn-sm btn-outline-danger btn-del-ent" data-id="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>"><i class="fa fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="entModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <form id="entForm" class="modal-content" enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold" id="entModalTitle">Add Entity</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="entErr" class="alert alert-danger d-none"></div>
                <input type="hidden" id="entId">
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" id="entName" class="form-control" maxlength="150" placeholder="e.g. Magneto Dynamics Pvt Ltd">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-semibold">Logo</label>
                        <input type="file" id="entLogo" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp,.svg">
                        <small class="text-muted">Max 2 MB. Leave blank to keep existing.</small>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Address</label>
                    <textarea id="entAddress" class="form-control" rows="2"></textarea>
                </div>
                <div class="row">
                    <div class="col-md-5 mb-3">
                        <label class="form-label fw-semibold">City</label>
                        <input type="text" id="entCity" class="form-control" maxlength="80">
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label fw-semibold">State</label>
                        <input type="text" id="entState" class="form-control" maxlength="80">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label fw-semibold">Pincode</label>
                        <input type="text" id="entPincode" class="form-control" maxlength="10">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-semibold">Phone</label>
                        <input type="text" id="entPhone" class="form-control" maxlength="20">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" id="entEmail" class="form-control" maxlength="120">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label fw-semibold">Website</label>
                        <input type="text" id="entWebsite" class="form-control" maxlength="255" placeholder="https://">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-1">
                        <label class="form-label fw-semibold">Signature Image</label>
                        <input type="file" id="entSignature" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp,.svg">
                        <small class="text-muted d-block" id="entSigCurrent"></small>
                        <div class="form-text">Used as the authorised signature on the Offer Letter. Max 2 MB. Leave blank to keep existing.</div>
                    </div>
                    <div class="col-md-6 mb-1">
                        <label class="form-label fw-semibold">Signatory Title</label>
                        <input type="text" id="entSigTitle" class="form-control" maxlength="255" placeholder="e.g. Director">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save</button>
            </div>
        </form>
    </div>
</div>

<script>
window.SET_URL_ENT = '<?= BASE_URL ?>/modules/settings/index.php?ajax=1&tab=entities';
window.SET_CSRF    = '<?= h(csrf_token()) ?>';
</script>
<?php $page_scripts = <<<'JS'
<script>
$(function () {
    document.body.appendChild(document.getElementById('entModal'));
    var modal = new bootstrap.Modal(document.getElementById('entModal'));
    if ($.fn.DataTable) $('#entTable').DataTable({ pageLength: 25, order: [[2,'asc']], columnDefs: [{ orderable:false, targets:[0,1,5] }], language: { emptyTable: 'No entities defined.' } });

    function showErr(msg){ $('#entErr').html(msg).removeClass('d-none'); }
    function clearForm(){
        $('#entId,#entName,#entAddress,#entCity,#entState,#entPincode,#entPhone,#entEmail,#entWebsite,#entSigTitle').val('');
        $('#entLogo,#entSignature').val('');
        $('#entSigCurrent').text('');
    }

    $('#btnAddEnt').on('click', function () {
        $('#entModalTitle').text('Add Entity'); clearForm();
        $('#entErr').addClass('d-none'); modal.show();
    });

    $(document).on('click', '.btn-edit-ent', function () {
        var b = $(this);
        $('#entModalTitle').text('Edit Entity'); clearForm();
        $('#entId').val(b.data('id'));
        $('#entName').val(b.data('name'));
        $('#entAddress').val(b.data('address'));
        $('#entCity').val(b.data('city'));
        $('#entState').val(b.data('state'));
        $('#entPincode').val(b.data('pincode'));
        $('#entPhone').val(b.data('phone'));
        $('#entEmail').val(b.data('email'));
        $('#entWebsite').val(b.data('website'));
        $('#entSigTitle').val(b.data('sigtitle'));
        $('#entSigCurrent').text(b.data('signature') ? 'Current: ' + b.data('signature') : 'No signature uploaded');
        $('#entErr').addClass('d-none'); modal.show();
    });

    $('#entForm').on('submit', function (e) {
        e.preventDefault();
        var id = $('#entId').val();
        var action = id ? 'update' : 'create';
        var fd = new FormData();
        fd.append('csrf_token', window.SET_CSRF);
        fd.append('name', $('#entName').val().trim());
        fd.append('address', $('#entAddress').val().trim());
        fd.append('city', $('#entCity').val().trim());
        fd.append('state', $('#entState').val().trim());
        fd.append('pincode', $('#entPincode').val().trim());
        fd.append('phone', $('#entPhone').val().trim());
        fd.append('email', $('#entEmail').val().trim());
        fd.append('website', $('#entWebsite').val().trim());
        fd.append('signatory_title', $('#entSigTitle').val().trim());
        var logo = $('#entLogo')[0].files[0];
        if (logo) fd.append('logo', logo);
        var sig = $('#entSignature')[0].files[0];
        if (sig) fd.append('signature', sig);

        $.ajax({
            url: window.SET_URL_ENT + '&action=' + action + (id ? '&id=' + id : ''),
            method: 'POST', data: fd, processData: false, contentType: false
        }).done(function (res) {
            if (res.success) location.reload(); else showErr(res.message || 'Save failed.');
        }).fail(function (x) { showErr(x.responseJSON && x.responseJSON.message || 'Save failed.'); });
    });

    $(document).on('click', '.btn-del-ent', function () {
        var b = $(this);
        if (!confirm('Delete entity "' + b.data('name') + '"?')) return;
        $.post(window.SET_URL_ENT + '&action=delete&id=' + b.data('id'), { csrf_token: window.SET_CSRF })
            .done(function (res) { if (res.success) location.reload(); else alert(res.message || 'Delete failed.'); })
            .fail(function (x) { alert(x.responseJSON && x.responseJSON.message || 'Delete failed.'); });
    });
});
</script>
JS;
?>
