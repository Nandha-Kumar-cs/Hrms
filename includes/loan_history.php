<?php
/**
 * Loan & Advance history — derived from actual salary-slip deductions.
 *
 * When a generated salary slip deducts a loan's EMI, its deductions_json carries
 * a "<Type> Deduction #<id>" line (added by includes/payroll_extras.php). Summing
 * those lines across the employee's slips is the authoritative "returned" amount,
 * so the loans module reflects exactly what payroll has deducted — without any
 * change to payroll generation. Figures are lazily synced back to employee_loans
 * (returned_amount, paid_months, status) so other readers stay correct.
 */

/** The deductions_json key payroll uses for this loan, e.g. "Loan Deduction #5". */
function loan_deduction_key(array $loan): string
{
    return ucfirst((string)$loan['type']) . ' Deduction #' . (int)$loan['id'];
}

/** Principal, simple interest over the full tenure, total due, months, rate. */
function loan_due(array $loan): array
{
    $amount   = (float)$loan['amount'];
    $rate     = (float)$loan['interest_rate'];
    $months   = max(1, (int)($loan['total_months'] ?? 1));
    $interest = $rate > 0 ? round($amount * ($rate / 100) * ($months / 12), 2) : 0.0;
    return [
        'amount'    => $amount,
        'rate'      => $rate,
        'months'    => $months,
        'interest'  => $interest,
        'total_due' => round($amount + $interest, 2),
    ];
}

/**
 * Actual per-month deductions for this loan, keyed by payroll month (YYYY-MM),
 * read from the employee's salary slips.
 */
function loan_actual_deductions(PDO $db, array $loan): array
{
    $key = loan_deduction_key($loan);
    $st  = $db->prepare(
        "SELECT id, payroll_month, deductions_json, created_at
           FROM salary_slips
          WHERE employee_id = ? AND deductions_json LIKE ?
          ORDER BY payroll_month ASC, id ASC"
    );
    $st->execute([(int)$loan['employee_id'], '%' . $key . '%']);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $slip) {
        $ded = json_decode($slip['deductions_json'] ?: '[]', true);
        // Exact-key check guards against "#5" also matching "#50" in the LIKE.
        if (!is_array($ded) || !array_key_exists($key, $ded)) continue;
        $amt = round((float)$ded[$key], 2);
        if ($amt <= 0) continue;
        $rows[(string)$slip['payroll_month']] = [
            'slip_id' => (int)$slip['id'],
            'month'   => (string)$slip['payroll_month'],
            'date'    => $slip['created_at']
                ? date('Y-m-d', strtotime($slip['created_at']))
                : date('Y-m-t', strtotime($slip['payroll_month'] . '-01')),
            'amount'  => $amt,
        ];
    }
    return $rows;
}

/**
 * Computed loan figures (interest, total due, returned, pending, status) derived
 * from the slips, with a lazy sync of the stored employee_loans columns.
 */
function loan_figures(PDO $db, array $loan): array
{
    $due      = loan_due($loan);
    $actual   = loan_actual_deductions($db, $loan);
    $returned = min(round(array_sum(array_column($actual, 'amount')), 2), $due['total_due']);
    $pending  = max(0.0, round($due['total_due'] - $returned, 2));
    $paid     = count($actual);

    // Status is auto-derived ONLY for loans still 'active': an active loan
    // auto-completes once fully paid. A manually-set 'closed' or 'completed'
    // status is terminal and is preserved (so editing the status sticks, and
    // payroll stops deducting non-active loans).
    $status = $loan['status'] ?? 'active';
    if (!in_array($status, ['closed', 'completed'], true)) {
        $status = $pending <= 0.01 ? 'completed' : 'active';
    }

    if (abs($returned - (float)$loan['returned_amount']) > 0.01
        || (int)$loan['paid_months'] !== $paid
        || $loan['status'] !== $status) {
        try {
            $db->prepare('UPDATE employee_loans SET returned_amount=?, paid_months=?, status=? WHERE id=?')
               ->execute([$returned, $paid, $status, (int)$loan['id']]);
        } catch (Throwable $e) { /* non-fatal — columns may be missing on old schemas */ }
    }

    return $due + [
        'returned'    => $returned,
        'pending'     => $pending,
        'paid_months' => $paid,
        'status'      => $status,
        'actual'      => $actual,
    ];
}

/**
 * Full installment schedule: actual deductions (from slips) merged with the
 * projected upcoming EMIs, in month order, with the running balance after each.
 */
function loan_schedule(array $loan, array $fig): array
{
    $totalDue = $fig['total_due'];
    $months   = $fig['months'];
    $emi      = (float)$loan['monthly_deduction'];
    $start    = date('Y-m', strtotime($loan['date_given']));
    $actual   = $fig['actual'];

    // Union of the projected tenure months and any month that actually deducted.
    $set = [];
    for ($i = 0; $i < $months; $i++) {
        $set[date('Y-m', strtotime($start . '-01 +' . $i . ' months'))] = true;
    }
    foreach ($actual as $ym => $_) $set[$ym] = true;
    $allMonths = array_keys($set);
    sort($allMonths);

    $rows   = [];
    $cum    = 0.0;
    $instNo = 0;
    foreach ($allMonths as $ym) {
        $instNo++;
        if (isset($actual[$ym])) {
            $amt = $actual[$ym]['amount'];
            $date = $actual[$ym]['date'];
            $deducted = true;
        } else {
            if ($cum >= $totalDue - 0.01) continue;          // already cleared
            $rem = max(0.0, round($totalDue - $cum, 2));
            // Final instalment (or ≤ one EMI left) clears the EXACT balance, so the
            // projected schedule ends at ₹0 pending instead of leaving a rounding paise.
            $amt  = ($instNo >= $months || $emi <= 0 || $rem <= $emi) ? $rem : round(min($emi, $rem), 2);
            $date = date('Y-m-t', strtotime($ym . '-01'));
            $deducted = false;
        }
        if ($amt <= 0) continue;
        $cum = round($cum + $amt, 2);
        $rows[] = [
            'seq'      => count($rows) + 1,
            'month'    => $ym,
            'date'     => $date,
            'amount'   => $amt,
            'returned' => $cum,
            'pending'  => max(0.0, round($totalDue - $cum, 2)),
            'deducted' => $deducted,
        ];
    }
    return $rows;
}
