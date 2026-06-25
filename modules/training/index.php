<?php
/**
 * Training — Employee onboarding course: "How to use the HRMS".
 * A self-paced, employee-facing training broken into lessons. Read-only.
 */
require_once '../../includes/bootstrap.php';
require_login();
require_permission('training', 'view');

$page_title = 'Training';
include '../../includes/header.php';

// ── Lesson content (employee-facing: how THEY use the system) ────────────────
$lessons = [
    [
        'icon' => 'fa-right-to-bracket', 'title' => 'Lesson 1 — Logging in',
        'sub'  => 'Get into the system and find your way around.',
        'steps' => [
            'Open the HRMS link in your browser and sign in with your <b>email</b> and <b>password</b>.',
            'After login you land on your <b>Dashboard</b> — a summary of your details and quick links.',
            'Use the <b>left sidebar</b> to move between sections. You only see the sections you are allowed to use.',
            'Top-right shows your name; use the menu there to <b>log out</b>.',
        ],
    ],
    [
        'icon' => 'fa-id-badge', 'title' => 'Lesson 2 — Your profile',
        'sub'  => 'Check that your personal and bank details are correct.',
        'steps' => [
            'Go to <b>Employees → Employees List</b> and open your record (you will only see your own).',
            'Review your <b>department, designation, joining date, PAN/UAN and bank details</b>.',
            'Your salary, benefits, loans, increments and letters are shown in the tabs on your profile.',
            'If anything is wrong, inform HR — they will update it for you.',
        ],
    ],
    [
        'icon' => 'fa-clock', 'title' => 'Lesson 3 — Attendance & how your hours count',
        'sub'  => 'Understand check-in / check-out and what counts as a full day.',
        'steps' => [
            'Your daily <b>check-in</b> and <b>check-out</b> are recorded (by device or by HR).',
            'Your <b>worked hours</b> = time between check-in and check-out, <b>minus break time</b> (your lunch batch + the two tea breaks).',
            '<b>Full day = 8 net hours</b> — roughly <b>9:00 AM to 6:00 PM</b> (8 hours of work + ~1 hour of breaks).',
            '<b>4 to 8 net hours → Half Day</b> (half-day salary is deducted).',
            '<b>Less than 4 net hours → Short day</b> — pay is reduced for the hours you did not work.',
            'Arriving after the grace time is marked <b>Late</b>; repeated lateness beyond the monthly limit is penalised.',
            'See your month in <b>Attendance → Report</b> (you will see only your own row).',
        ],
    ],
    [
        'icon' => 'fa-calendar-check', 'title' => 'Lesson 4 — Applying for leave',
        'sub'  => 'Request leave and track its approval.',
        'steps' => [
            'Go to <b>Attendance → Leave Requests → New Request</b>.',
            'Pick the <b>leave type</b>, <b>dates</b>, add a <b>reason</b>, and attach a document if needed, then submit.',
            'Your request stays in the list as <b>Pending</b> until an admin <b>Approves</b> or <b>Rejects</b> it.',
            'Once decided, it moves to <b>Leave History</b> where you can see the status and remarks.',
            'Note: only <b>1 paid leave per month</b> is allowed; extra leave may be unpaid.',
        ],
    ],
    [
        'icon' => 'fa-file-invoice-dollar', 'title' => 'Lesson 5 — Reading your salary slip',
        'sub'  => 'Understand earnings, deductions and net pay.',
        'steps' => [
            'Go to <b>Payroll → Salary Slips</b> and open your slip (or download the <b>PDF</b>).',
            '<b>Earnings</b>: Basic, HRA, Conveyance, etc., plus any Benefits and a combined Bonus line.',
            '<b>Deductions</b>: PF and ESI (statutory), plus <b>Absent</b>, <b>Half Day</b>, <b>Short Hours</b> and <b>Late</b> deductions based on your attendance, and any <b>Loan</b> EMI.',
            '<b>Net Pay</b> = Total Earnings − Total Deductions (also written in words).',
            'Most attendance deductions come from absences / short days — good attendance keeps your net pay high.',
        ],
    ],
    [
        'icon' => 'fa-gift', 'title' => 'Lesson 6 — Benefits, bonuses & loans',
        'sub'  => 'How these appear in your pay.',
        'steps' => [
            '<b>Benefits</b> (e.g. insurance, funds): a <b>Cash</b> benefit adds to your take-home; a <b>Cashless</b> benefit is paid to the provider on your behalf, so it shows as both an earning and a deduction.',
            '<b>Bonuses & incentives</b> approved for the month are added to your earnings.',
            '<b>Loans / advances</b>: a fixed EMI is deducted each month until repaid; check the balance on your profile’s Loans tab.',
        ],
    ],
    [
        'icon' => 'fa-folder-open', 'title' => 'Lesson 7 — Letters, documents & assets',
        'sub'  => 'Your records and company property.',
        'steps' => [
            '<b>Letters</b> (offer, confirmation, increment, promotion) issued to you appear under your profile’s Letters tab.',
            '<b>Documents</b> you have submitted are stored under the Documents tab.',
            '<b>Assets</b> assigned to you (laptop, etc.) are listed on the Assets tab — return them on exit to get a No-Due clearance.',
        ],
    ],
];
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Employee Training</h1>
        <p class="page-subtitle">How to use the HRMS — a short, self-paced guide for every employee.</p>
    </div>
    <div class="page-actions">
        <button onclick="window.print()" class="btn btn-secondary no-print"><i class="fa fa-print me-1"></i>Print</button>
    </div>
</div>

<?php render_flash(); ?>

<div class="alert alert-info">
    <i class="fa fa-circle-info me-1"></i>
    Work through the lessons below in order. Click a lesson to expand it. This training explains everything you need to use the system day to day.
</div>

<div class="accordion" id="trainingAccordion" style="max-width:900px">
    <?php foreach ($lessons as $i => $l): $open = $i === 0; ?>
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button <?= $open ? '' : 'collapsed' ?> fw-semibold" type="button"
                    data-bs-toggle="collapse" data-bs-target="#lesson<?= $i ?>"
                    aria-expanded="<?= $open ? 'true' : 'false' ?>">
                <i class="fa <?= $l['icon'] ?> me-2 text-primary"></i><?= h($l['title']) ?>
            </button>
        </h2>
        <div id="lesson<?= $i ?>" class="accordion-collapse collapse <?= $open ? 'show' : '' ?>"
             data-bs-parent="#trainingAccordion">
            <div class="accordion-body">
                <p class="text-muted mb-2"><?= h($l['sub']) ?></p>
                <ol style="line-height:1.7;font-size:14px;padding-left:18px">
                    <?php foreach ($l['steps'] as $step): ?>
                    <li class="mb-1"><?= $step ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="text-muted small mt-3" style="max-width:900px">
    Need more help? Contact your HR / admin team. Buttons across the app show <u>underlined</u> shortcut keys, and most lists have a search box.
</div>

<style>
@media print {
    #sidebar, #topbar, .sidebar-overlay, .page-actions, .no-print, footer { display:none !important; }
    .main-wrapper, #mainContent { margin:0 !important; padding:0 !important; }
    .accordion-collapse { display:block !important; }   /* expand all lessons when printing */
    .accordion-button { background:#fff !important; color:#000 !important; }
}
</style>
<?php include '../../includes/footer.php'; ?>
