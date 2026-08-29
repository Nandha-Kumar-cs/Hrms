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

    if (!$start) {
        /* Legacy mode: the benefit is anchored to a single effective_month.
         *
         * This used to `return true` whenever effective_month was empty — with no
         * date compared at all, so the benefit was paid on EVERY slip, every
         * month, for ever (security audit L-7). A function asked "does this apply
         * in this month?" must not answer yes when it has nothing to answer with.
         *
         * '0000-00-00' has to be caught too. It is not empty(), so it slipped past
         * the old guard into the comparison below — and strtotime('0000-00-00') does
         * NOT fail: it returns -62169984000, i.e. 30 November of year -1. The
         * comparison then matched no real month, so the benefit was silently never
         * paid, on any slip, ever — the opposite failure to the one above. Hence
         * the year guard below rather than a bare === false check.
         *
         * Both now fail CLOSED and are logged. Not paying is recoverable — an
         * admin sets the date and it pays correctly next run. Paying a row whose
         * date is unreadable is money out of the door that nobody asked for.
         */
        $eff = (string) ($b['effective_month'] ?? '');
        $ts  = $eff !== '' ? strtotime($eff) : false;
        if ($ts === false || (int) date('Y', $ts) < 1970) {
            error_log(sprintf(
                'Benefit "%s" (employee %s) has no usable date anchor '
                . '(start_date empty, effective_month %s) — not paid. Set a Start Date '
                . 'or an Effective Month on it.',
                (string) ($b['fund_type'] ?? '?'),
                (string) ($b['employee_id'] ?? '?'),
                $eff === '' ? 'empty' : '"' . $eff . '"'
            ));
            return false;
        }
        return (int) date('n', $ts) === $month && (int) date('Y', $ts) === $year;
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
            "SELECT employee_id, fund_type, amount, frequency, start_date, end_date, effective_month, payment_mode
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
    } catch (Throwable $e) {
        /* Do NOT swallow this silently. The two CREATE TABLE statements used to
         * disagree, so on some installs this query failed with "Unknown column"
         * and every benefit vanished from every slip with nothing to show for it.
         * The slip must still be produced, but the reason has to be recorded. */
        error_log('Benefits skipped for employee ' . $empId . ': ' . $e->getMessage());
    }

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

    // How much of this month's earnings are still undeducted. Component
    // deductions and any cashless-benefit deduction above are already folded
    // into $deductions — a loan EMI must never take more than what is left
    // after those, or a heavy-LOP month records the loan as repaid out of a
    // paycheck the employee never actually received (security audit H-7).
    // Decremented after each loan below, so a second/third loan for the same
    // employee correctly sees what the first one already used up.
    $netRemaining  = max(0.0, round(array_sum($allowances) - array_sum($deductions), 2));
    $loanShortfall = 0.0;   // total EMI this month's earnings could not cover

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
            $wanted = (($paidCount + 1) >= $months || $remaining <= $emi) ? $remaining : min($emi, $remaining);
            $wanted = round($wanted, 2);
            if ($wanted <= 0) continue;

            // Cap to what the employee actually has left this month — this is
            // the fix: never let deductions_json (and so loan_actual_deductions(),
            // which treats every rupee in it as repaid) claim more than the
            // paycheck could cover.
            $deduct = round(min($wanted, $netRemaining), 2);
            if ($wanted - $deduct > 0.01) {
                $loanShortfall = round($loanShortfall + ($wanted - $deduct), 2);
            }
            if ($deduct <= 0) continue;   // net already exhausted — collect nothing, write no line

            $deductions[ucfirst((string) $loan['type']) . ' Deduction #' . (int) $loan['id']] = $deduct;
            $netRemaining = round($netRemaining - $deduct, 2);
        }
    } catch (Throwable $e) { /* loans table absent — no effect */ }

    // ── Re-sum the two totals the calculator already defines (no formula change) ──
    $gross    = round(array_sum($allowances), 2);
    $totalDed = round(array_sum($deductions), 2);

    // With loans now capped above, this can still be positive if NON-loan
    // deductions alone (PF/ESI/late penalty/...) already exceed a heavily
    // prorated gross. Record it rather than letting the max(0, …) clamp below
    // hide it silently (security audit H-7).
    $overDeduction = max(0.0, round($totalDed - $gross, 2));

    $result['allowances']       = $allowances;
    $result['deductions']       = $deductions;
    $result['gross_earnings']   = $gross;
    $result['total_deductions'] = $totalDed;
    $result['net_pay']          = max(0, round($gross - $totalDed, 2));

    // Surfaced on the payslip (modules/payroll/slip.php) as a visible warning,
    // not just a number nobody has to notice.
    if (!isset($result['attendance_summary']) || !is_array($result['attendance_summary'])) {
        $result['attendance_summary'] = [];
    }
    $result['attendance_summary']['loan_shortfall'] = $loanShortfall;
    $result['attendance_summary']['over_deduction']  = $overDeduction;

    return $result;
}
