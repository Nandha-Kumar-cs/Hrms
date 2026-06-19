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
$preType  = ucfirst(strtolower($_GET['type'] ?? ''));   // e.g. ?type=Promotion preselects the template
$employees = $db->query(
    'SELECT e.id, e.name, e.employee_id, e.designation_id, e.department_id, e.join_date,
            e.gender, e.fixed_salary, e.variable_salary,
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

// Designation / department lists drive the Promotion letter's structured
// dropdowns (whose IDs sync into the promotion-history table).
$designations = $db->query('SELECT id, name FROM designations ORDER BY name')->fetchAll();
$departments  = $db->query('SELECT id, name FROM departments ORDER BY name')->fetchAll();

// Salary components drive the Offer letter's salary breakup — computed from the
// offered salary exactly like the reference (percentage/fixed), not hand-typed.
$salary_components = [];
try {
    $salary_components = $db->query(
        'SELECT name, type, calculation_type, value FROM salary_components ORDER BY id'
    )->fetchAll();
} catch (Throwable $e) { /* table absent — breakup section stays empty */ }

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
        // A promotion letter must name the new designation — it is the record's
        // key field and is what gets applied to the employee profile.
        if ($type === 'Promotion' && !(int)($_POST['new_designation_id'] ?? 0)) {
            $errors[] = 'New Designation is required for a promotion letter.';
        }

        if (!$errors) {
            // Auto-generate ref number
            if (!$ref) {
                $cnt = $db->query('SELECT COUNT(*)+1 FROM letters')->fetchColumn();
                $ref = 'HR/' . $type[0] . '/' . date('Y') . '/' . str_pad($cnt, 4, '0', STR_PAD_LEFT);
            }
            $db->prepare('INSERT INTO letters (employee_id,type,issued_date,reference,content,issued_by,status) VALUES(?,?,?,?,?,?,?)')
               ->execute([$sel_emp, $type, $date, $ref, $content, $user['id'], 'Draft']);
            $lid = $db->lastInsertId();

            // Unified promotion workflow: a promotion LETTER is the single creation
            // path — it also writes the promotion-history row (employee_promotions)
            // and applies the new designation/department to the employee profile.
            if ($type === 'Promotion') {
                require_once __DIR__ . '/../../includes/promotion_sync.php';
                promotion_sync_from_letter($db, (int)$lid, $sel_emp, [
                    'effective_date'          => sanitize($_POST['effective_date'] ?? '') ?: $date,
                    'previous_designation_id' => (int)($_POST['previous_designation_id'] ?? 0) ?: null,
                    'new_designation_id'      => (int)($_POST['new_designation_id'] ?? 0) ?: null,
                    'department_id'           => (int)($_POST['department_id'] ?? 0) ?: null,
                    'salary_revision'         => promotion_parse_salary($_POST['new_gross'] ?? null),
                    'remarks'                 => sanitize($_POST['remarks'] ?? '') ?: null,
                ], $ref, (int)$user['id'], 'Draft', $date);

                if (function_exists('activity_log')) {
                    activity_log('created', 'Promotion', 'Promotion recorded from letter ' . $ref . ' for '
                        . (function_exists('activity_emp_label') ? activity_emp_label($sel_emp) : ('employee #' . $sel_emp)));
                }
            }

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
                <option <?= ($_POST['type'] ?? $preType)===$t?'selected':'' ?>><?= $t ?></option>
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

    <!-- Offer letter: warn when the selected employee has no salary configured -->
    <div id="salaryWarn" class="alert alert-error" style="display:none;margin-top:12px">
        <strong id="salaryWarnName"></strong> has no salary configured. Set the employee's
        salary on their profile before creating the offer letter.
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
const DESIGNATIONS = <?= json_encode($designations) ?>;
const DEPARTMENTS  = <?= json_encode($departments) ?>;
const SALARY_COMPONENTS = <?= json_encode($salary_components) ?>;
const TODAY = '<?= date('Y-m-d') ?>';

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
        const company = coName(emp);
        const salary  = parseFloat(String(fields.salary || '').replace(/[^0-9.]/g, '')) || 0;
        const inr0    = v => Number(Math.round(v)).toLocaleString('en-IN');
        // Salutation by gender (Mr./Mrs.) — mirrors the reference offer letter.
        const sal = emp.gender === 'Male' ? 'Mr. ' : (emp.gender === 'Female' ? 'Mrs. ' : '');
        // Salary breakup computed from configured salary components (percentage of
        // the offered salary, or a fixed amount) — exactly like the reference.
        let breakup = '', i = 1, gross = 0;
        (SALARY_COMPONENTS || []).forEach(c => {
            const amt = c.calculation_type === 'percentage'
                ? (parseFloat(c.value) / 100) * salary
                : parseFloat(c.value);
            breakup += `${i++}. ${c.name}: ${inr0(amt)}\n`;
            if (c.type === 'allowance') gross += amt;
        });
        const joinDate = fields.join_date || '';
        const dayName  = joinDate ? new Date(joinDate + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long' }) : '';
        return `Dear ${sal}${emp.name},

With reference to your application and the interviews you had with ${company}, we are pleased to offer you employment in our company on the following terms and conditions.

1. Designation          : ${emp.designation_name || 'N/A'}
2. Department           : ${emp.dept_name || 'N/A'}
3. Date Of Joining      : ${joinDate}${dayName ? ' ( ' + dayName + ' )' : ''}
4. Compensation         : Rs ${inr0(salary)} per month + retirals
5. Probation            : First six months from the date of joining will be treated as probation period. During this period, no increments will apply.
6. Confirmation         : After completion of six months, we will evaluate your performance and decide whether to retain your services. Unless the employment is confirmed in writing at the end of the probation period, it should be considered terminated.
7. House Of work        : 9.00am to 6.15pm (with weekly off as per company policy)
8. Notice Of termination: During the probation period, your service can be terminated by either side by giving two day's written notice. Upon confirmation, one month's written notice is required from either side. If you are already on an assignment and if your presence in the assignment is necessary as assessed by the management, the management reserves the right to require you to work till the assignment is complete.
9. Leave Policy         : As per the rules of the company, you can avail 6 days casual & 6 days sick leave per year.

Please sign and return the copy of this letter in token of your acceptance, if the terms and conditions specified above and enclosed are acceptable to you.

We welcome you to ${company} and look forward to your contribution to the success and growth of the Company
For ${company}



Authorized Signatory

I agree to the above terms and conditions and will be joining on:

[ ${emp.name} ]                              confirmed Date Of Joining
                                             ${joinDate}

____________________________________________________________
SALARY BREAKUP
____________________________________________________________
${breakup}   Gross Pay: ${inr0(gross)}

   Total Cost to Company: ${inr0(salary)}

Note :
1. All payments are subject to Tax deduction at source (TDS). You are responsible for declaring your tax exemptions & tax liabilities
2. Take home pay will be Gross Pay - Applicable Statutory deductions(PF, ESI, Professional Tax etc.)
3. All reimbursements are at actuals and need to be supported with bills/vouchers whenever available${fields.terms ? '\n\n' + fields.terms : ''}`;
    },

    Confirmation: (emp, fields) => {
        const company = coName(emp);
        const sal = emp.gender === 'Male' ? 'Mr. ' : (emp.gender === 'Female' ? 'Mrs. ' : '');
        const cd  = fields.confirm_date || '';
        const fdy = cd ? new Date(cd + 'T00:00:00').toLocaleDateString('en-US', { month: 'long', day: '2-digit', year: 'numeric' }) : '';
        const dmy = cd ? (() => { const [y, m, d] = cd.split('-'); return `${d}-${m}-${y.slice(2)}`; })() : '';
        return `${fdy}

Dear ${sal}${emp.name},

Based on your performance, the management is pleased to inform you that you have been confirmed on the rolls of ${company} w.e.f ${dmy}. The salary remains the same as given in the offer letter at the time of joining.

For ${company}



Authorized Signatory`;
    },

    Increment: (emp, fields) => {
        const inr = v => { const n = parseFloat(String(v || '').replace(/[^0-9.]/g, '')); return (isNaN(n) || n === 0) ? '' : '₹ ' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
        const pct = fields.increment_pct ? fields.increment_pct + '%' : '';
        return `Dear ${emp.name},

We are pleased to inform you that the management has decided to revise your salary, with effect from ${fields.effective_date || ''}. This reflects your outstanding contribution to the organization.

Previous Monthly Gross: ${inr(fields.old_salary)}
Revised Monthly Gross: ${inr(fields.new_salary)}
Increment: ${pct}

We appreciate your dedication and expect continued excellence in your work. Congratulations on this well-deserved recognition.`;
    },

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
    // Offer fields mirror the reference create form: joining date, offered salary
    // (auto-filled from the employee), and terms. Designation/department come from
    // the employee record and the salary breakup is computed from components.
    Offer: [
        { id:'join_date', label:'Joining Date',      type:'date',     name:'joining_date' },
        { id:'salary',    label:'Offered Salary (₹)', type:'number',  name:'offer_salary', placeholder:'₹', step:'0.01' },
        { id:'terms',     label:'Terms & Conditions', type:'textarea', name:'offer_terms',
          value:'This offer is contingent upon satisfactory completion of all pre-employment requirements.' },
    ],
    // Confirmation mirrors the reference create form: just the confirmation date
    // (employee is the shared field). Company/signatory come from the entity.
    Confirmation: [
        { id:'confirm_date', label:'Confirmation Date', type:'date', value: TODAY },
    ],
    // Increment mirrors the reference create form: Old CTC (auto-filled from the
    // employee), New CTC, a read-only auto-computed percentage, and effective date.
    Increment: [
        { id:'old_salary',     label:'Old CTC / Month (₹)', type:'number', name:'old_salary',           step:'0.01' },
        { id:'new_salary',     label:'New CTC / Month (₹)', type:'number', name:'new_salary',           step:'0.01' },
        { id:'increment_pct',  label:'Increment %',         type:'number', name:'increment_percentage', step:'0.01', readonly:true },
        { id:'effective_date', label:'Effective Date',      type:'date',   name:'effective_date',       value: TODAY },
    ],
    // Promotion fields submit structured IDs (name:) so they sync into the
    // promotion-history table; the letter body still shows the designation names.
    Promotion: [
        { id:'effective_date',  label:'Effective Date',        type:'date',   name:'effective_date' },
        { id:'prev_designation',label:'Previous Designation',  type:'select', name:'previous_designation_id', options:DESIGNATIONS, blank:'— Select —' },
        { id:'new_designation', label:'New Designation',       type:'select', name:'new_designation_id',      options:DESIGNATIONS, blank:'Select designation' },
        { id:'department_id',   label:'Department (if changed)',type:'select', name:'department_id',           options:DEPARTMENTS,  blank:'Same / No Change' },
        { id:'new_gross',       label:'Revised Gross Salary',  type:'text',   name:'new_gross', placeholder:'₹...' },
        { id:'hr_name',         label:'Signed By',             type:'text' },
    ],
};

// Show/hide the "no salary configured" banner for the Offer letter.
function toggleSalaryWarn(emp, total) {
    const w = document.getElementById('salaryWarn');
    if (!w) return;
    if (emp && total <= 0) {
        document.getElementById('salaryWarnName').textContent = emp.name || 'This employee';
        w.style.display = '';
    } else {
        w.style.display = 'none';
    }
}

function loadTemplate() {
    const empId = document.getElementById('sel_emp').value;
    const type  = document.getElementById('sel_type').value;
    const emp   = EMPLOYEES[empId] || { name: '[Employee Name]' };
    const flds  = TYPE_FIELDS[type] || [];
    const container = document.getElementById('templateFields');

    let html = '<div class="section-title">Template Fields</div><div class="form-grid-2">';
    flds.forEach(f => {
        const nameAttr = f.name ? ` name="${f.name}"` : '';
        if (f.type === 'select') {
            let opts = `<option value="">${f.blank || 'Select'}</option>`;
            (f.options || []).forEach(o => { opts += `<option value="${o.id}">${o.name}</option>`; });
            html += `<div class="field"><label>${f.label}</label><select id="tf_${f.id}"${nameAttr} onchange="refreshPreview()" style="width:100%">${opts}</select></div>`;
        } else if (f.type === 'textarea') {
            const tval = (f.value !== undefined ? String(f.value) : '').replace(/</g, '&lt;');
            html += `<div class="field" style="grid-column:1/-1"><label>${f.label}</label><textarea id="tf_${f.id}"${nameAttr} rows="3" placeholder="${f.placeholder||''}" oninput="refreshPreview()" style="width:100%">${tval}</textarea></div>`;
        } else {
            const stepAttr = f.step ? ` step="${f.step}"` : '';
            const roAttr   = f.readonly ? ' readonly' : '';
            const roStyle  = f.readonly ? 'width:100%;background:#f1f5f9' : 'width:100%';
            const val = (f.value !== undefined ? String(f.value) : '').replace(/"/g, '&quot;');
            html += `<div class="field"><label>${f.label}</label><input type="${f.type}" id="tf_${f.id}"${nameAttr}${stepAttr}${roAttr} placeholder="${f.placeholder||''}" value="${val}" oninput="refreshPreview()" style="${roStyle}"></div>`;
        }
    });
    html += '</div>';
    container.innerHTML = html;

    // Offer & Confirmation warn when the employee has no salary configured
    // (mirrors the reference salary guard). Offer also auto-fills Offered Salary
    // from the employee's fixed + variable salary.
    if ((type === 'Offer' || type === 'Confirmation' || type === 'Increment') && EMPLOYEES[empId]) {
        const e = EMPLOYEES[empId];
        const total = (parseFloat(e.fixed_salary) || 0) + (parseFloat(e.variable_salary) || 0);
        if (type === 'Offer') {
            const sf = document.getElementById('tf_salary');
            if (sf && total > 0) sf.value = total;
        }
        if (type === 'Increment') {                   // auto-fill Old CTC like the reference
            const os = document.getElementById('tf_old_salary');
            if (os && total > 0) os.value = total;
        }
        toggleSalaryWarn(e, total);
    } else {
        toggleSalaryWarn(null, 1);
    }

    // Promotion: default Previous Designation + Department to the employee's
    // current values (admin can still override before saving).
    if (type === 'Promotion' && EMPLOYEES[empId]) {
        const pd = document.getElementById('tf_prev_designation');
        const dp = document.getElementById('tf_department_id');
        if (pd && EMPLOYEES[empId].designation_id) pd.value = EMPLOYEES[empId].designation_id;
        if (dp && EMPLOYEES[empId].department_id)  dp.value = EMPLOYEES[empId].department_id;
    }
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
        let v = el.value;
        if (el.tagName === 'SELECT') {            // use the option label in the letter body
            const opt = el.options[el.selectedIndex];
            v = (opt && opt.value) ? opt.text : '';
        }
        fields[el.id.replace('tf_','')] = v;
    });

    // Increment: auto-compute the percentage from Old/New CTC into the read-only
    // field ((new - old) / old * 100), mirroring the reference create form.
    if (type === 'Increment') {
        const o = parseFloat(fields.old_salary) || 0;
        const n = parseFloat(fields.new_salary) || 0;
        const pct = (o > 0 && n > 0) ? (((n - o) / o) * 100).toFixed(2) : '';
        const pctEl = document.getElementById('tf_increment_pct');
        if (pctEl) pctEl.value = pct;
        fields.increment_pct = pct;
    }

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
