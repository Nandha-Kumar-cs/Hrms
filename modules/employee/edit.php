<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('employee_edit');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect(BASE_URL . '/modules/employee/index.php'); }

$emp = db()->query("SELECT e.*, d.name AS dept_name, des.name AS des_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN designations des ON e.designation_id = des.id
    WHERE e.id = $id")->fetch(PDO::FETCH_ASSOC);

if (!$emp) { redirect(BASE_URL . '/modules/employee/index.php'); }

$departments  = db()->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$designations = db()->query("SELECT id, name FROM designations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$roles        = db()->query("SELECT id, name FROM roles WHERE name != 'Super Admin' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['csrf_token'] ?? '');

    $fields = ['first_name','last_name','email','phone','gender','dob','date_of_joining',
               'department_id','designation_id','employment_type','manager_id',
               'pan_number','aadhaar_number','bank_name','bank_account','bank_ifsc',
               'pf_number','esi_number','emergency_contact_name','emergency_contact_phone',
               'address','status'];

    $data = [];
    foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '');

    if (empty($data['first_name']))  $errors[] = 'First name is required.';
    if (empty($data['email']))       $errors[] = 'Email is required.';

    if (empty($errors)) {
        // Handle photo upload
        $photo = $emp['photo'];
        if (!empty($_FILES['photo']['name'])) {
            $up = upload_file($_FILES['photo'], 'employees');
            if ($up['success']) $photo = $up['path'];
            else $errors[] = $up['error'];
        }

        if (empty($errors)) {
            $stmt = db()->prepare("UPDATE employees SET
                first_name=:fn, last_name=:ln, email=:em, phone=:ph, gender=:ge,
                dob=:dob, date_of_joining=:doj, department_id=:dep, designation_id=:des,
                employment_type=:et, manager_id=:mg, pan_number=:pan, aadhaar_number=:aadhaar,
                bank_name=:bn, bank_account=:ba, bank_ifsc=:bi, pf_number=:pf,
                esi_number=:esi, emergency_contact_name=:ecn, emergency_contact_phone=:ecp,
                address=:addr, status=:st, photo=:photo, updated_at=NOW()
                WHERE id=:id");
            $stmt->execute([
                ':fn'=>$data['first_name'],':ln'=>$data['last_name'],':em'=>$data['email'],
                ':ph'=>$data['phone'],':ge'=>$data['gender'],':dob'=>$data['dob']?:null,
                ':doj'=>$data['date_of_joining']?:null,':dep'=>$data['department_id']?:null,
                ':des'=>$data['designation_id']?:null,':et'=>$data['employment_type'],
                ':mg'=>$data['manager_id']?:null,':pan'=>$data['pan_number'],
                ':aadhaar'=>$data['aadhaar_number'],':bn'=>$data['bank_name'],
                ':ba'=>$data['bank_account'],':bi'=>$data['bank_ifsc'],
                ':pf'=>$data['pf_number'],':esi'=>$data['esi_number'],
                ':ecn'=>$data['emergency_contact_name'],':ecp'=>$data['emergency_contact_phone'],
                ':addr'=>$data['address'],':st'=>$data['status'],':photo'=>$photo,':id'=>$id
            ]);

            // Update user email if changed
            db()->prepare("UPDATE users SET email=:em WHERE employee_id=:eid")
                 ->execute([':em'=>$data['email'],':eid'=>$id]);

            // Update role if provided
            if (!empty($_POST['role_id'])) {
                $rid = (int)$_POST['role_id'];
                $uid = db()->query("SELECT id FROM users WHERE employee_id=$id")->fetchColumn();
                if ($uid) {
                    db()->prepare("UPDATE users SET role_id=:rid WHERE id=:uid")
                         ->execute([':rid'=>$rid,':uid'=>$uid]);
                }
            }

            flash('success', 'Employee updated successfully.');
            redirect(BASE_URL . "/modules/employee/view.php?id=$id");
        }
    }
    // Re-merge POST data for redisplay
    $emp = array_merge($emp, $data);
}

$managers = db()->query("SELECT id, CONCAT(first_name,' ',last_name) AS name FROM employees WHERE status='Active' AND id != $id ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

// Get user's current role
$userRole = db()->query("SELECT u.role_id FROM users u WHERE u.employee_id=$id")->fetch(PDO::FETCH_ASSOC);
$currentRoleId = $userRole['role_id'] ?? null;

$page_title = 'Edit Employee';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Edit Employee</h1>
        <p class="page-subtitle"><?= h($emp['employee_id']) ?> — <?= h($emp['first_name'] . ' ' . $emp['last_name']) ?></p>
    </div>
    <div class="page-actions">
        <a href="view.php?id=<?= $id ?>" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php render_flash(); foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>

<form method="POST" enctype="multipart/form-data" id="editEmpForm">
    <?= csrf_field() ?>
    <div class="row">
        <!-- Personal Info -->
        <div class="col-8">
            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title">Personal Information</h3></div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control" value="<?= h($emp['first_name']) ?>" required>
                        </div>
                        <div class="form-group col-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-control" value="<?= h($emp['last_name']) ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" value="<?= h($emp['email']) ?>" required>
                        </div>
                        <div class="form-group col-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= h($emp['phone']) ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-4">
                            <label class="form-label">Gender</label>
                            <select name="gender" class="form-control">
                                <option value="">Select</option>
                                <?php foreach (['Male','Female','Other'] as $g): ?>
                                    <option value="<?= $g ?>" <?= $emp['gender']==$g?'selected':'' ?>><?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-4">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="dob" class="form-control" value="<?= h($emp['dob']) ?>">
                        </div>
                        <div class="form-group col-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-control">
                                <?php foreach (['Active','Inactive','Terminated','On Leave'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $emp['status']==$s?'selected':'' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="2"><?= h($emp['address']) ?></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label class="form-label">Emergency Contact Name</label>
                            <input type="text" name="emergency_contact_name" class="form-control" value="<?= h($emp['emergency_contact_name']) ?>">
                        </div>
                        <div class="form-group col-6">
                            <label class="form-label">Emergency Contact Phone</label>
                            <input type="text" name="emergency_contact_phone" class="form-control" value="<?= h($emp['emergency_contact_phone']) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Employment Info -->
            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title">Employment Details</h3></div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label class="form-label">Date of Joining</label>
                            <input type="date" name="date_of_joining" class="form-control" value="<?= h($emp['date_of_joining']) ?>">
                        </div>
                        <div class="form-group col-6">
                            <label class="form-label">Employment Type</label>
                            <select name="employment_type" class="form-control">
                                <?php foreach (['Full-Time','Part-Time','Contract','Intern'] as $et): ?>
                                    <option value="<?= $et ?>" <?= $emp['employment_type']==$et?'selected':'' ?>><?= $et ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label class="form-label">Department</label>
                            <select name="department_id" class="form-control">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $emp['department_id']==$d['id']?'selected':'' ?>><?= h($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-6">
                            <label class="form-label">Designation</label>
                            <select name="designation_id" class="form-control">
                                <option value="">Select Designation</option>
                                <?php foreach ($designations as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $emp['designation_id']==$d['id']?'selected':'' ?>><?= h($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label class="form-label">Reporting Manager</label>
                            <select name="manager_id" class="form-control">
                                <option value="">None</option>
                                <?php foreach ($managers as $m): ?>
                                    <option value="<?= $m['id'] ?>" <?= $emp['manager_id']==$m['id']?'selected':'' ?>><?= h($m['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-6">
                            <label class="form-label">System Role</label>
                            <select name="role_id" class="form-control">
                                <option value="">No Change</option>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= $r['id'] ?>" <?= $currentRoleId==$r['id']?'selected':'' ?>><?= h($r['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bank & Statutory -->
            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title">Bank &amp; Statutory Details</h3></div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-4">
                            <label class="form-label">PAN Number</label>
                            <input type="text" name="pan_number" class="form-control" value="<?= h($emp['pan_number']) ?>" maxlength="10" style="text-transform:uppercase">
                        </div>
                        <div class="form-group col-4">
                            <label class="form-label">Aadhaar Number</label>
                            <input type="text" name="aadhaar_number" class="form-control" value="<?= h($emp['aadhaar_number']) ?>" maxlength="12">
                        </div>
                        <div class="form-group col-4">
                            <label class="form-label">UAN / PF Number</label>
                            <input type="text" name="pf_number" class="form-control" value="<?= h($emp['pf_number']) ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-4">
                            <label class="form-label">ESI Number</label>
                            <input type="text" name="esi_number" class="form-control" value="<?= h($emp['esi_number']) ?>">
                        </div>
                        <div class="form-group col-4">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control" value="<?= h($emp['bank_name']) ?>">
                        </div>
                        <div class="form-group col-4">
                            <label class="form-label">Account Number</label>
                            <input type="text" name="bank_account" class="form-control" value="<?= h($emp['bank_account']) ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-4">
                            <label class="form-label">IFSC Code</label>
                            <input type="text" name="bank_ifsc" class="form-control" value="<?= h($emp['bank_ifsc']) ?>" style="text-transform:uppercase">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column -->
        <div class="col-4">
            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title">Profile Photo</h3></div>
                <div class="card-body text-center">
                    <?php if ($emp['photo']): ?>
                        <img src="<?= BASE_URL . '/' . $emp['photo'] ?>" class="emp-avatar-lg mb-3" style="width:120px;height:120px;border-radius:50%;object-fit:cover;">
                    <?php else: ?>
                        <div class="emp-avatar-lg mb-3" style="width:120px;height:120px;border-radius:50%;background:#1e3a8a;display:flex;align-items:center;justify-content:center;margin:0 auto;font-size:2.5rem;color:#fff;">
                            <?= strtoupper(substr($emp['first_name'],0,1) . substr($emp['last_name'],0,1)) ?>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="photo" class="form-control" accept="image/*">
                    <small class="text-muted">JPG, PNG up to 2MB</small>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title">Employee ID</h3></div>
                <div class="card-body">
                    <div class="stat-value" style="font-size:1.5rem;color:var(--primary)"><?= h($emp['employee_id']) ?></div>
                    <small class="text-muted">Cannot be changed</small>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary w-100 mb-2" data-key="S"><u>S</u>ave Changes</button>
                    <a href="view.php?id=<?= $id ?>" class="btn btn-secondary w-100" data-key="B"><u>B</u>ack</a>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
addLocalShortcut('s', () => document.getElementById('editEmpForm').submit());
addLocalShortcut('b', () => location.href = 'view.php?id=<?= $id ?>');
</script>
<?php include '../../includes/footer.php'; ?>
