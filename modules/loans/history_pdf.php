<?php
/**
 * Loan History — PDF export.
 * Renders the same derived deduction schedule as show.php to a PDF (mPDF, with
 * TCPDF / printable-HTML fallbacks — same pipeline as the letters/slip PDFs).
 * All processing happens before any output so headers send cleanly.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/loan_history.php';
require_login();
require_permission('loans', 'view');

$db = db();
$id = (int)($_GET['id'] ?? 0);

$st = $db->prepare(
    'SELECT el.*, e.name AS emp_name, e.employee_id AS emp_code
     FROM employee_loans el JOIN employees e ON e.id = el.employee_id
     WHERE el.id = ?'
);
$st->execute([$id]);
$loan = $st->fetch();
if (!$loan) { flash('error', 'Loan record not found.'); redirect(BASE_URL . '/modules/loans/index.php'); }

require_own_employee((int)$loan['employee_id']);

$fig      = loan_figures($db, $loan);
$schedule = loan_schedule($loan, $fig);

$rate          = $fig['rate'];
$months        = $fig['months'];
$totalInterest = $fig['interest'];
$totalDue      = $fig['total_due'];
$returned      = $fig['returned'];
$pending       = $fig['pending'];
$status        = $fig['status'];
$perInstInt    = ($months > 0 && $totalInterest > 0) ? round($totalInterest / $months, 2) : 0.0;

$rs = '&#8377;';
$nf = fn($a) => $rs . ' ' . number_format((float)$a, 2);
$hh = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$companyName = COMPANY_NAME;

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10pt; color: #222; }
    .head { border-bottom: 2px solid #3b82f6; padding-bottom: 8px; margin-bottom: 12px; }
    .company { font-size: 15pt; font-weight: bold; color: #3b82f6; }
    h1 { text-align: center; font-size: 13pt; text-transform: uppercase; letter-spacing: 2px;
         color: #1e293b; margin: 8px 0 12px; border-top: 1px solid #ddd; border-bottom: 1px solid #ddd; padding: 5px 0; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { font-size: 9.5pt; padding: 2px 4px; }
    .meta .lbl { color: #64748b; width: 130px; }
    .sched th, .sched td { border: 1px solid #cbd5e1; padding: 5px 7px; font-size: 8.8pt; }
    .sched th { background: #1e293b; color: #fff; text-align: left; }
    .sched td.r, .sched th.r { text-align: right; }
    .sched td.c, .sched th.c { text-align: center; }
    .sched tr.up td { color: #888; background: #f8fafc; }
    .sched tfoot th { background: #f1f5f9; color: #111; }
    .totals { margin-top: 10px; font-size: 9.5pt; }
    .totals td { padding: 3px 6px; }
    .footer { margin-top: 16px; border-top: 1px solid #e2e8f0; padding-top: 6px; font-size: 7.5pt; color: #999; text-align: center; }
    .badge-ok { color: #16a34a; font-weight: bold; }
    .badge-due { color: #dc2626; font-weight: bold; }
</style>
</head>
<body>
    <div class="head">
        <div class="company"><?= $hh($companyName) ?></div>
    </div>

    <h1>Loan History</h1>

    <table class="meta">
        <tr>
            <td class="lbl">Employee</td><td><b><?= $hh($loan['emp_name']) ?></b> (<?= $hh($loan['emp_code']) ?>)</td>
            <td class="lbl">Type</td><td><?= $hh(ucfirst($loan['type'])) ?></td>
        </tr>
        <tr>
            <td class="lbl">Date Given</td><td><?= $hh(date('d M Y', strtotime($loan['date_given']))) ?></td>
            <td class="lbl">Monthly EMI</td><td><?= $nf($loan['monthly_deduction']) ?></td>
        </tr>
        <tr>
            <td class="lbl">Principal</td><td><?= $nf($fig['amount']) ?></td>
            <td class="lbl">Interest</td><td><?= $rate > 0 ? $nf($totalInterest) . ' (' . $hh($rate) . '% p.a. &times; ' . $months . ' mo)' : 'Nil' ?></td>
        </tr>
        <tr>
            <td class="lbl">Total Due</td><td><b><?= $nf($totalDue) ?></b></td>
            <td class="lbl">Status</td><td><?= $status === 'completed' ? '<span class="badge-ok">Completed</span>' : 'Active' ?></td>
        </tr>
    </table>

    <table class="sched" style="margin-top:12px">
        <thead>
            <tr>
                <th>#</th>
                <th>Month</th>
                <th>Deduction Date</th>
                <th class="r">Amount Deducted</th>
                <?php if ($rate > 0): ?><th class="r">Interest</th><?php endif; ?>
                <th class="r">Returned</th>
                <th class="r">Pending</th>
                <th class="c">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($schedule as $row): ?>
            <tr class="<?= $row['deducted'] ? '' : 'up' ?>">
                <td><?= $row['seq'] ?></td>
                <td><?= $hh(date('M Y', strtotime($row['month'] . '-01'))) ?></td>
                <td><?= $hh(date('d M Y', strtotime($row['date']))) ?></td>
                <td class="r"><?= $nf($row['amount']) ?></td>
                <?php if ($rate > 0): ?><td class="r"><?= $nf($perInstInt) ?></td><?php endif; ?>
                <td class="r"><?= $nf($row['returned']) ?></td>
                <td class="r"><?= $nf($row['pending']) ?></td>
                <td class="c"><?= $row['deducted'] ? 'Deducted' : 'Upcoming' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3">Total</th>
                <th class="r"><?= $nf($returned) ?></th>
                <?php if ($rate > 0): ?><th class="r"><?= $nf($totalInterest) ?></th><?php endif; ?>
                <th class="r"><?= $nf($returned) ?></th>
                <th class="r"><?= $nf($pending) ?></th>
                <th></th>
            </tr>
        </tfoot>
    </table>

    <table class="totals">
        <tr><td>Total Due</td><td class="r"><b><?= $nf($totalDue) ?></b></td></tr>
        <tr><td>Returned</td><td class="r"><span class="badge-ok"><?= $nf($returned) ?></span></td></tr>
        <tr><td>Pending</td><td class="r"><span class="<?= $pending > 0 ? 'badge-due' : 'badge-ok' ?>"><?= $nf($pending) ?></span></td></tr>
    </table>

    <div class="footer">Deductions are taken automatically from monthly salary slips. Upcoming rows are projected from the EMI. &bull; <?= $hh($companyName) ?> &bull; Confidential</div>
</body>
</html>
<?php
$html = ob_get_clean();

$filename  = 'loan-history-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$loan['emp_code']) . '.pdf';
$docTitle  = 'Loan History - ' . $loan['emp_name'];
$xamppRoot = dirname(__DIR__, 4);

// 1) mPDF
$mpdfAutoloads = [
    $xamppRoot . '/htdocs/xibo/vendor/autoload.php',
    'C:/xampp8.2/htdocs/xibo/vendor/autoload.php',
    BASE_PATH . '/vendor/autoload.php',
];
foreach ($mpdfAutoloads as $al) {
    if (!is_file($al)) continue;
    require_once $al;
    if (!class_exists('\\Mpdf\\Mpdf')) continue;
    try {
        $tmp = sys_get_temp_dir() . '/mpdf_hrms';
        if (!is_dir($tmp)) @mkdir($tmp, 0777, true);
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => 12,
            'tempDir' => is_dir($tmp) ? $tmp : sys_get_temp_dir(),
        ]);
        $mpdf->SetTitle($docTitle);
        $mpdf->WriteHTML($html);
        $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
        exit;
    } catch (Throwable $e) { /* fall through */ }
}

// 2) TCPDF fallback
$tcpdfCandidates = [
    $xamppRoot . '/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php',
    'C:/xampp8.2/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php',
];
foreach ($tcpdfCandidates as $cand) {
    if (!is_file($cand)) continue;
    require_once $cand;
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('MagDyn HRMS');
    $pdf->SetTitle($docTitle);
    $pdf->SetMargins(12, 12, 12);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($filename, 'I');
    exit;
}

// 3) Printable HTML
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $hh($docTitle) . '</title></head><body onload="window.print()">' . $html . '</body></html>';
exit;
