<?php
/**
 * Salary-slip extras.
 *
 * Folds an employee's active Benefit Funds and approved Bonuses (as EARNINGS)
 * and active Loan/Advance EMIs (as a DEDUCTION) into a payroll result produced
 * by PayrollCalculator::computePayroll() — mirroring the reference
 * Employee_Management payroll.
 *
 * IMPORTANT: this does NOT change any core salary formula. The calculator
 * already defines gross = sum(allowances) and net = gross − sum(deductions);
 * this only appends extra line items and re-sums those two totals. Benefits and
 * bonuses are tagged with [BENEFIT]/[BONUS] label prefixes, which the slip view
 * already renders in their own sub-sections.
 *
 * Eligibility (matches the reference + existing HRMS reports):
 *   • Benefit  → status 'active'  AND effective_month falls in this payroll month
 *   • Bonus    → status 'approved' AND payroll_month/payroll_year = this month
 *   • Loan EMI → status 'active', disbursed on/before month-end, and the payroll
 *                month is within the loan tenure (startOfMonth(date_given)+months)
 */
/**
 * Whether a benefit row applies to a payroll month — ports
 * EmployeeBenefit::isActiveInMonth (legacy effective_month, or recurring
 * start/end date range + frequency).
 */
function benefit_eligible_in_month(array $b, int $month, int $year): bool
{
    $start = !empty($b['start_date']) ? $b['start_date'] : null;
    $first = sprintf('%04d-%02d-01', $year, $month);
    $last  = date('Y-m-t', strtotime($first));

    // Universal end-date guard: once a benefit's end date has passed, it must
    // never appear in a later month — in BOTH recurring and legacy modes. This
    // is what stops an upcoming-month slip from reflecting an ended benefit.
    if (!empty($b['end_date']) && $b['end_date'] < $first) return false;

    if (!$start) {                                   // legacy: match effective_month
        if (empty($b['effective_month'])) return true;
        return (int) date('n', strtotime($b['effective_month'])) === $month
            && (int) date('Y', strtotime($b['effective_month'])) === $year;
    }
    if ($start > $last) return false;                // not started yet

    $sm = (int) date('n', strtotime($start));
    switch ($b['frequency'] ?: 'monthly') {
        case 'quarterly':   return intdiv($month - 1, 3) === intdiv($sm - 1, 3);
        case 'half_yearly': return intdiv($month - 1, 6) === intdiv($sm - 1, 6);
        case 'annual':      return $month === $sm;
        default:            return true;             // monthly / weekly / fortnightly
    }
}

/**
 * How many times the benefit pays in the month — ports
 * EmployeeBenefit::occurrencesInMonth (weekly ≈ 4, fortnightly ≈ 2, else 1),
 * capped to the active days within the month.
 */
function benefit_occurrences_in_month(array $b, int $month, int $year): int
{
    $freq = $b['frequency'] ?: 'monthly';
    if (!in_array($freq, ['weekly', 'fortnightly'], true)) return 1;

    $first = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $last  = (clone $first)->modify('last day of this month');
    $effStart = (!empty($b['start_date']) && $b['start_date'] > $first->format('Y-m-d')) ? new DateTime($b['start_date']) : clone $first;
    $effEnd   = (!empty($b['end_date'])   && $b['end_date']   < $last->format('Y-m-d'))  ? new DateTime($b['end_date'])   : clone $last;
    $activeDays = max(0, (int) $effStart->diff($effEnd)->days + 1);

    return $freq === 'weekly' ? max(1, intdiv($activeDays, 7)) : max(1, intdiv($activeDays, 14));
}

function payroll_apply_extras(PDO $db, array $result, int $empId, int $month, int $year): array
{
    $monthEnd   = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
    $allowances = $result['allowances'] ?? [];
    $deductions = $result['deductions'] ?? [];

    // ── Benefit Funds → earnings (recurring-aware, mirrors EmployeeBenefit) ──
    try {
        $bs = $db->prepare(
            "SELECT fund_type, amount, frequency, start_date, end_date, effective_month, payment_mode
               FROM employee_benefits
              WHERE employee_id = ? AND status = 'active'
              ORDER BY id"
        );
        $bs->execute([$empId]);
        foreach ($bs->fetchAll() as $b) {
            if (!benefit_eligible_in_month($b, $month, $year)) continue;
            $occ = benefit_occurrences_in_month($b, $month, $year);   // weekly/fortnightly may be >1
            $amt = round((float) $b['amount'] * $occ, 2);
            if ($amt <= 0) continue;
            $name = $b['fund_type'] !== '' ? $b['fund_type'] : 'Benefit Fund';
            $key  = '[BENEFIT] ' . $name . ($occ > 1 ? ' (×' . $occ . ')' : '');
            while (isset($allowances[$key])) $key .= ' ';   // keep duplicate names distinct
            $allowances[$key] = $amt;

            // Cashless benefit: the money is paid directly to the provider (insurance
            // company, education fund, …), NOT handed to the employee with salary. So
            // it is earned (above) AND deducted here — net take-home is unaffected.
            // A Cash benefit has no matching deduction, so it adds to take-home as before.
            if (($b['payment_mode'] ?? 'cash') === 'cashless') {
                $dkey = $name . ' (Cashless — paid to provider)';
                while (isset($deductions[$dkey])) $dkey .= ' ';
                $deductions[$dkey] = $amt;
            }
        }
    } catch (Throwable $e) { /* benefits columns absent — no effect */ }

    // ── Bonuses & Incentives (approved for this month) → earnings ────────────
    try {
        $bn = $db->prepare(
            "SELECT type, reason, amount FROM employee_bonuses
              WHERE employee_id = ? AND status = 'approved'
                AND payroll_month = ? AND payroll_year = ?
              ORDER BY id"
        );
        $bn->execute([$empId, $month, $year]);
        foreach ($bn->fetchAll() as $bo) {
            $amt = round((float) $bo['amount'], 2);
            if ($amt <= 0) continue;
            // Friendly type label (mirrors EmployeeBonus::TYPES).
            static $bonusTypeLabels = [
                'monthly_bonus' => 'Monthly Bonus',
                'performance'   => 'Performance Incentive',
                'festival'      => 'Festival Bonus',
                'overtime'      => 'Overtime Incentive',
                'one_time'      => 'One-time Reward',
            ];
            $typeLabel = $bonusTypeLabels[$bo['type']] ?? ucwords(str_replace('_', ' ', (string) $bo['type']));
            $label = $typeLabel . ($bo['reason'] ? ' — ' . $bo['reason'] : '');
            $key   = '[BONUS] ' . $label;
            while (isset($allowances[$key])) $key .= ' ';
            $allowances[$key] = $amt;
        }
    } catch (Throwable $e) { /* bonuses table absent — no effect */ }

    // ── Loans & Advances → deduction (final instalment clears the exact balance) ──
    if (!function_exists('loan_due')) {
        require_once __DIR__ . '/loan_history.php';
    }
    try {
        $ln = $db->prepare(
            "SELECT id, employee_id, type, amount, interest_rate, monthly_deduction, date_given, total_months
               FROM employee_loans
              WHERE employee_id = ? AND status = 'active' AND monthly_deduction > 0
              ORDER BY id"
        );
        $ln->execute([$empId]);
        $curYm = sprintf('%04d-%02d', $year, $month);
        foreach ($ln->fetchAll() as $loan) {
            // Disbursed after this payroll period → not yet deductible.
            if (!empty($loan['date_given']) && $loan['date_given'] > $monthEnd) continue;

            $months   = max(1, (int) $loan['total_months']);
            $totalDue = (float) (loan_due($loan)['total_due'] ?? 0);   // principal + interest

            // Amount already deducted in PRIOR months (from generated slips).
            $actual = function_exists('loan_actual_deductions') ? loan_actual_deductions($db, $loan) : [];
            $returnedBefore = 0.0; $paidCount = 0;
            foreach ($actual as $ym => $row) {
                if ($ym < $curYm) { $returnedBefore += (float) $row['amount']; $paidCount++; }
            }
            $remaining = round($totalDue - $returnedBefore, 2);
            if ($remaining <= 0.0) continue;                          // already fully repaid

            $emi    = round((float) $loan['monthly_deduction'], 2);
            if ($emi <= 0) continue;
            // On the final instalment (or when ≤ one EMI is left), deduct the EXACT
            // remaining balance so rounding never leaves a stray pending amount.
            $deduct = (($paidCount + 1) >= $months || $remaining <= $emi) ? $remaining : min($emi, $remaining);
            $deduct = round($deduct, 2);
            if ($deduct <= 0) continue;
            $deductions[ucfirst((string) $loan['type']) . ' Deduction #' . (int) $loan['id']] = $deduct;
        }
    } catch (Throwable $e) { /* loans table absent — no effect */ }

    // ── Re-sum the two totals the calculator already defines (no formula change) ──
    $gross    = round(array_sum($allowances), 2);
    $totalDed = round(array_sum($deductions), 2);

    $result['allowances']       = $allowances;
    $result['deductions']       = $deductions;
    $result['gross_earnings']   = $gross;
    $result['total_deductions'] = $totalDed;
    $result['net_pay']          = max(0, round($gross - $totalDed, 2));

    return $result;
}
