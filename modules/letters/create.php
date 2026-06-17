<?php
/**
 * Create Letter.
 * Bootstrap + permission + POST handling run BEFORE any output so the
 * post/redirect/get (PRG) redirect() can send its Location header cleanly.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('letters','create');

$db       = db();
$user     = current_user();
$emp_id   = (int)($_GET['emp_id'] ?? 0);
$employees = $db->query(
    'SELECT e.id, e.name, e.employee_id, e.designation_id, e.department_id, e.join_date,
            d.name AS dept_name, des.name AS designation_name,
            ent.name AS entity_name, ent.address AS entity_address, ent.city AS entity_city,
            ent.state AS entity_state, ent.pincode AS entity_pincode,
            ent.email AS entity_email, ent.phone AS entity_phone
     FROM employees e
     LEFT JOIN departments d   ON d.id   = e.department_id
     LEFT JOIN designations des ON des.id = e.designation_id
     LEFT JOIN entities ent    ON ent.id = e.entity_id
     WHERE e.status = "Active"
     ORDER BY e.name'
)->fetchAll();

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

// All POST processing is done — now it is safe to emit output.
$page_title = 'Create Letter';
require_once __DIR__ . '/../../includes/header.php';
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

// Company name/address come from the employee's linked entity; fall back to the
// global company constants when the employee has no entity assigned.
function coName(emp) {
    return (emp && emp.entity_name) ? emp.entity_name : COMPANY_NAME;
}
function coAddress(emp) {
    if (emp && emp.entity_name) {
        const cityLine = [emp.entity_city, emp.entity_state, emp.entity_pincode].filter(Boolean).join(' ');
        return [emp.entity_address, cityLine].filter(Boolean).join(', ');
    }
    return COMPANY_ADDRESS;
}

// Template generators
const TEMPLATES = {
    Offer: (emp, fields) => {
        const num = v => { const x = parseFloat(String(v || '').replace(/[^0-9.]/g, '')); return isNaN(x) ? 0 : x; };
        const inr = v => '₹' + Number(v).toLocaleString('en-IN');
        const basic = num(fields.basic), hra = num(fields.hra), veh = num(fields.vehicle_maint),
              conv = num(fields.conveyance), inc = num(fields.prod_incentive), pf = num(fields.pf_esi);
        const gross = basic + hra + veh + conv + inc;
        const total = gross + pf;
        return `Dear ${emp.name},

With reference to your application and the interviews you had with ${coName(emp)}, we are pleased to offer you employment in our company on the following terms and conditions.

1. Designation: ${fields.designation || ''}
2. Department: ${fields.department || ''}
3. Date of Joining: ${fields.join_date || ''}
4. Compensation: ${fields.compensation || ''}
5. Probation: First six months from the date of joining will be treated as probation period. During this period, no increments will apply.
6. Confirmation: After completion of six months, we will evaluate your performance and decide whether to retain your services. Unless the employment is confirmed in writing at the end of the probation period, it should be considered terminated.
7. Hours of Work: ${fields.hours_of_work || ''}
8. Notice of Termination: During the probation period, your service can be terminated by either side by giving two day's written notice. Upon confirmation, one month's written notice is required from either side. If you are already on an assignment and if your presence in the assignment is necessary as assessed by the management, the management reserves the right to require you to work till the assignment is complete.
9. Leave Policy: As per the rules of the company, you can avail ${fields.casual_leave || '0'} days casual & ${fields.sick_leave || '0'} days sick leave per year.

Please sign and return the copy of this letter in token of your acceptance, if the terms and conditions specified above and enclosed are acceptable to you.

We welcome you to ${coName(emp)} and look forward to your contribution to the success and growth of the Company.

For ${coName(emp)}



${fields.hr_name || 'Authorized Signatory'}

I agree to the above terms and conditions and will be joining on:

[ ${emp.name} ]                              Confirmed Date of Joining: ${fields.join_date || ''}

____________________________________________________________
SALARY BREAKUP
____________________________________________________________
1. HRA: ${inr(hra)}
2. Basic: ${inr(basic)}
3. Vehicle Maintenance: ${inr(veh)}
4. Conveyance: ${inr(conv)}
5. Production Incentive: ${inr(inc)}
   Gross Pay: ${inr(gross)}

6. Benefits — PF / ESI: ${inr(pf)}
7. Total Cost to Company: ${inr(total)}

Note:
1. All payments are subject to Tax deduction at source (TDS). You are responsible for declaring your tax exemptions & tax liabilities.
2. Take home pay will be Gross Pay - Applicable Statutory deductions (PF, ESI, Professional Tax etc.).
3. All reimbursements are at actuals and need to be supported with bills/vouchers whenever available.`;
    },

    Confirmation: (emp, fields) => `${coName(emp)}
${coAddress(emp)}

Date: ${fields.date}
Ref: ${fields.ref}

Dear ${emp.name},

SUBJECT: CONFIRMATION OF EMPLOYMENT

We are pleased to confirm your appointment as a permanent employee of ${coName(emp)} with effect from ${fields.confirm_date}.

Your service has been reviewed and found satisfactory. All other terms and conditions of service remain unchanged.

Congratulations on your confirmation!

Yours sincerely,

${fields.hr_name || 'HR Department'}
${coName(emp)}`,

    Increment: (emp, fields) => `${coName(emp)}
${coAddress(emp)}

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
${coName(emp)}`,

    Promotion: (emp, fields) => `${coName(emp)}
${coAddress(emp)}

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
${coName(emp)}`,
};

// Dynamic fields per type
const TYPE_FIELDS = {
    Offer: [
        { id:'designation',   label:'Designation',          type:'text',   placeholder:'e.g. Junior Developer' },
        { id:'department',    label:'Department',            type:'text',   placeholder:'e.g. System Admin' },
        { id:'join_date',     label:'Date of Joining',       type:'date' },
        { id:'compensation',  label:'Compensation',          type:'text',   placeholder:'e.g. Rs 1250 per month + retirals' },
        { id:'hours_of_work', label:'Hours of Work',         type:'text',   value:'9.00am to 6.15pm (with weekly off as per company policy)' },
        { id:'casual_leave',  label:'Casual Leave (days/yr)',type:'number', value:'6' },
        { id:'sick_leave',    label:'Sick Leave (days/yr)',  type:'number', value:'6' },
        { id:'basic',         label:'Basic',                 type:'number', placeholder:'₹' },
        { id:'hra',           label:'HRA',                   type:'number', placeholder:'₹' },
        { id:'vehicle_maint', label:'Vehicle Maintenance',   type:'number', placeholder:'₹' },
        { id:'conveyance',    label:'Conveyance',            type:'number', placeholder:'₹' },
        { id:'prod_incentive',label:'Production Incentive',  type:'number', placeholder:'₹' },
        { id:'pf_esi',        label:'PF / ESI (Benefits)',   type:'number', placeholder:'₹' },
        { id:'hr_name',       label:'Signed By',             type:'text',   placeholder:'e.g. Suresh Kumar' },
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
        const val = (f.value !== undefined ? String(f.value) : '').replace(/"/g, '&quot;');
        html += `<div class="field"><label>${f.label}</label><input type="${f.type}" id="tf_${f.id}" placeholder="${f.placeholder||''}" value="${val}" oninput="refreshPreview()" style="width:100%"></div>`;
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
