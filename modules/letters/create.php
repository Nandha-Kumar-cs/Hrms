<?php
$page_title = 'Create Letter';
require_once __DIR__ . '/../../includes/header.php';
require_permission('letters','create');

$db       = db();
$user     = current_user();
$emp_id   = (int)($_GET['emp_id'] ?? 0);
$employees = $db->query('SELECT id, name, employee_id, designation_id, department_id, join_date FROM employees WHERE status="Active" ORDER BY name')->fetchAll();

// Get salary for increment/promotion templates
function getEmpSalary($db, $eid) {
    $s = $db->prepare('SELECT * FROM salary_structures WHERE employee_id=? AND is_current=1 LIMIT 1');
    $s->execute([$eid]);
    return $s->fetch();
}

function getEmpDetail($db, $eid) {
    $s = $db->prepare('SELECT e.*, d.name AS dept, des.name AS desig FROM employees e LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN designations des ON des.id=e.designation_id WHERE e.id=?');
    $s->execute([$eid]);
    return $s->fetch();
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { $errors[] = 'Invalid request.'; }
    else {
        $sel_emp   = (int)$_POST['employee_id'];
        $type      = sanitize($_POST['type']);
        $ref       = sanitize($_POST['reference'] ?? '');
        $date      = sanitize($_POST['issued_date']);
        $content   = $_POST['content'] ?? '';
        $status    = 'Draft';

        if (!$sel_emp) $errors[] = 'Select an employee.';
        if (!in_array($type, ['Offer','Confirmation','Increment','Promotion'])) $errors[] = 'Invalid type.';
        if (!$date) $errors[] = 'Date is required.';

        if (!$errors) {
            // Auto-generate ref number
            if (!$ref) {
                $cnt = $db->query('SELECT COUNT(*)+1 FROM letters')->fetchColumn();
                $ref = 'HR/' . $type[0] . '/' . date('Y') . '/' . str_pad($cnt, 4, '0', STR_PAD_LEFT);
            }
            $db->prepare('INSERT INTO letters (employee_id,type,issued_date,reference,content,issued_by,status) VALUES(?,?,?,?,?,?,?)')
               ->execute([$sel_emp, $type, $date, $ref, $content, $user['id'], 'Draft']);
            $lid = $db->lastInsertId();
            flash('success', ucfirst(strtolower($type)) . ' letter created (Draft).');
            redirect(BASE_URL . '/modules/letters/view.php?id=' . $lid);
        }
    }
}

// Default selected employee
$selEmpData = $emp_id ? getEmpDetail($db, $emp_id) : null;
?>

<div class="page-head">
    <div>
        <h1>Create Letter</h1>
        <p class="muted">Generate HR letters with templates</p>
    </div>
    <div class="head-actions">
        <a href="index.php" class="btn btn-ghost" accesskey="b" data-shortcut data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-error"><?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<form method="POST" id="letterForm">
<?= csrf_field() ?>
<div class="grid-2">
<div>
<div class="card form-card">
    <div class="section-title">Letter Details</div>
    <div class="form-grid-2">
        <div class="field">
            <label>Employee *</label>
            <select name="employee_id" id="sel_emp" onchange="loadTemplate()" required>
                <option value="">— Select Employee —</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?= $e['id'] ?>" <?= ($emp_id==$e['id']||($_POST['employee_id']??'')==$e['id'])?'selected':'' ?>>
                    <?= h($e['name']) ?> (<?= h($e['employee_id']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Letter <u>T</u>ype *</label>
            <select name="type" id="sel_type" onchange="loadTemplate()" accesskey="t">
                <?php foreach (['Offer','Confirmation','Increment','Promotion'] as $t): ?>
                <option <?= ($_POST['type']??'')===$t?'selected':'' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Reference Number</label>
            <input type="text" name="reference" id="ref_no" placeholder="Auto-generated"
                   value="<?= h($_POST['reference'] ?? '') ?>">
        </div>
        <div class="field">
            <label>Issued Date *</label>
            <input type="date" name="issued_date" required value="<?= h($_POST['issued_date'] ?? date('Y-m-d')) ?>">
        </div>
    </div>

    <!-- Dynamic template fields -->
    <div id="templateFields" style="margin-top:16px"></div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary" accesskey="s" data-shortcut data-key="S">
            ✓ <u>S</u>ave as Draft
        </button>
        <a href="index.php" class="btn btn-ghost">Cancel</a>
    </div>
</div>
</div>

<!-- Preview column -->
<div>
<div class="card form-card" style="position:sticky;top:24px">
    <div class="card-head" style="margin:-22px -22px 16px;padding:12px 18px">
        <h3>Letter Preview</h3>
        <button type="button" class="btn btn-sm" onclick="refreshPreview()">↻ Refresh</button>
    </div>
    <div id="letterPreview" style="font-size:13px;line-height:1.7;color:var(--text);min-height:300px;border:1px dashed var(--border);padding:16px;border-radius:var(--radius);white-space:pre-wrap"></div>
    <textarea name="content" id="letterContent" style="display:none"></textarea>
</div>
</div>
</div>
</form>

<!-- Employee data for JS templates -->
<script>
window.BASE_URL = '<?= BASE_URL ?>';
const EMPLOYEES = <?= json_encode(array_column($employees, null, 'id')) ?>;
const COMPANY_NAME = <?= json_encode(COMPANY_NAME) ?>;
const COMPANY_ADDRESS = <?= json_encode(COMPANY_ADDRESS) ?>;

// Template generators
const TEMPLATES = {
    Offer: (emp, fields) => `${COMPANY_NAME}
${COMPANY_ADDRESS}

Date: ${fields.date}
Ref: ${fields.ref}

Dear ${emp.name},

SUBJECT: OFFER OF EMPLOYMENT

We are delighted to offer you the position of ${fields.designation || emp.designation_id} in our organization effective ${fields.join_date}.

Your compensation will be as follows:
• Annual CTC: ${fields.ctc || ''}
• Monthly Gross: ${fields.gross_monthly || ''}

Your employment will be governed by the terms and conditions of our HR policy.

We look forward to your joining on ${fields.join_date}.

Please sign and return a copy of this letter as acceptance.

Yours sincerely,

${fields.hr_name || 'HR Department'}
${COMPANY_NAME}`,

    Confirmation: (emp, fields) => `${COMPANY_NAME}
${COMPANY_ADDRESS}

Date: ${fields.date}
Ref: ${fields.ref}

Dear ${emp.name},

SUBJECT: CONFIRMATION OF EMPLOYMENT

We are pleased to confirm your appointment as a permanent employee of ${COMPANY_NAME} with effect from ${fields.confirm_date}.

Your service has been reviewed and found satisfactory. All other terms and conditions of service remain unchanged.

Congratulations on your confirmation!

Yours sincerely,

${fields.hr_name || 'HR Department'}
${COMPANY_NAME}`,

    Increment: (emp, fields) => `${COMPANY_NAME}
${COMPANY_ADDRESS}

Date: ${fields.date}
Ref: ${fields.ref}

Dear ${emp.name},

SUBJECT: SALARY INCREMENT LETTER

We are pleased to inform you that the management has decided to revise your compensation package with effect from ${fields.effective_date}.

Revised Salary:
• Previous Monthly Gross: ${fields.prev_gross || ''}
• Revised Monthly Gross: ${fields.new_gross || ''}
• Increment Amount: ${fields.increment_amt || ''}

This revision is in recognition of your valuable contribution and dedication.

Yours sincerely,

${fields.hr_name || 'HR Department'}
${COMPANY_NAME}`,

    Promotion: (emp, fields) => `${COMPANY_NAME}
${COMPANY_ADDRESS}

Date: ${fields.date}
Ref: ${fields.ref}

Dear ${emp.name},

SUBJECT: PROMOTION LETTER

We are delighted to inform you that based on your outstanding performance, you have been promoted to the position of ${fields.new_designation || ''} effective ${fields.effective_date}.

Previous Designation: ${fields.prev_designation || ''}
New Designation: ${fields.new_designation || ''}
Revised Gross Salary: ${fields.new_gross || ''}

We are confident that you will continue to excel in your new role.

Congratulations!

Yours sincerely,

${fields.hr_name || 'HR Department'}
${COMPANY_NAME}`,
};

// Dynamic fields per type
const TYPE_FIELDS = {
    Offer: [
        { id:'designation', label:'Designation Offered', type:'text' },
        { id:'join_date',   label:'Joining Date',        type:'date' },
        { id:'ctc',         label:'Annual CTC',          type:'text', placeholder:'₹...' },
        { id:'gross_monthly',label:'Monthly Gross',      type:'text', placeholder:'₹...' },
        { id:'hr_name',     label:'Signed By',           type:'text', placeholder:'HR Manager Name' },
    ],
    Confirmation: [
        { id:'confirm_date',label:'Confirmation Date',   type:'date' },
        { id:'hr_name',     label:'Signed By',           type:'text' },
    ],
    Increment: [
        { id:'effective_date',label:'Effective Date',    type:'date' },
        { id:'prev_gross',  label:'Previous Gross',      type:'text', placeholder:'₹...' },
        { id:'new_gross',   label:'New Gross',           type:'text', placeholder:'₹...' },
        { id:'increment_amt',label:'Increment Amount',   type:'text', placeholder:'₹...' },
        { id:'hr_name',     label:'Signed By',           type:'text' },
    ],
    Promotion: [
        { id:'effective_date',label:'Effective Date',    type:'date' },
        { id:'prev_designation',label:'Previous Designation',type:'text' },
        { id:'new_designation', label:'New Designation', type:'text' },
        { id:'new_gross',   label:'New Gross',           type:'text' },
        { id:'hr_name',     label:'Signed By',           type:'text' },
    ],
};

function loadTemplate() {
    const empId = document.getElementById('sel_emp').value;
    const type  = document.getElementById('sel_type').value;
    const emp   = EMPLOYEES[empId] || { name: '[Employee Name]' };
    const flds  = TYPE_FIELDS[type] || [];
    const container = document.getElementById('templateFields');

    let html = '<div class="section-title">Template Fields</div><div class="form-grid-2">';
    flds.forEach(f => {
        html += `<div class="field"><label>${f.label}</label><input type="${f.type}" id="tf_${f.id}" placeholder="${f.placeholder||''}" oninput="refreshPreview()" style="width:100%"></div>`;
    });
    html += '</div>';
    container.innerHTML = html;
    refreshPreview();
}

function refreshPreview() {
    const empId = document.getElementById('sel_emp').value;
    const type  = document.getElementById('sel_type').value;
    const emp   = EMPLOYEES[empId] || { name: '[Select Employee]' };
    const ref   = document.getElementById('ref_no').value || '[REF]';
    const date  = document.querySelector('[name="issued_date"]').value;

    const fields = { date, ref };
    document.querySelectorAll('[id^="tf_"]').forEach(el => {
        fields[el.id.replace('tf_','')] = el.value;
    });

    const tmpl = TEMPLATES[type];
    const text  = tmpl ? tmpl(emp, fields) : '';
    document.getElementById('letterPreview').textContent = text;
    document.getElementById('letterContent').value = text;
}

// Init
loadTemplate();
refreshPreview();

window.PAGE_SHORTCUTS = {
    's': () => { refreshPreview(); document.getElementById('letterForm').submit(); },
    'b': () => window.location.href = BASE_URL + '/modules/letters/index.php'
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
