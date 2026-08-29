<?php
/**
 * MagDyn HRMS — Payroll Calculation Engine
 *
 * Encapsulates the multi-step salary calculation used for individual slip
 * generation and the Salary Calculation view.  Call computePayroll() with a
 * loaded employee row + components list + calendar info; it returns every
 * figure needed to render and persist a salary slip.
 *
 * Skipped steps (tables not yet in schema): loan deductions, benefit funds,
 * employee bonuses.  Add them here when those modules are migrated.
 */

class PayrollCalculator
{
    private PDO $db;

    /** Minimum basic used for PF cap check (INR) */
    private const PF_CAP_BASIC = 15000;

    /** Default basic-to-CTC ratio when no "Basic Salary" component exists */
    private const BASIC_FALLBACK_RATIO = 0.40;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every working day in a month, as 'Y-m-d'. The single authority for the
     * payroll denominator — computePayroll() and the Salary Calculation screen
     * both read it, so a badge can never contradict what an employee is paid on.
     *
     * Rules, in this order — deliberately identical to
     * WorkCalendar::isWorkingDay() in includes/comp_off.php:
     *   1. A holidays row flagged is_working_day = 1 is a FULL override: that
     *      date is a working day even if it is a Sunday or a 1st/3rd Saturday.
     *   2. Sunday is off.
     *   3. The 1st and 3rd Saturday are off (2nd/4th/5th are working days).
     *   4. Any other declared holiday is off.
     *
     * Rule 1 is what this used to get wrong: the query had no is_working_day
     * filter, so declaring a Saturday a WORKING day put it in the holiday set
     * and REMOVED it from the denominator — the opposite of what was asked for,
     * paying every employee for a day they were required to work (audit M-1).
     */
    public function workingDayDates(int $month, int $year): array
    {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd   = date('Y-m-t', strtotime($monthStart));
        $calDays    = (int) date('t', strtotime($monthStart));

        $rows = $this->db->prepare('SELECT h_date, is_working_day FROM holidays WHERE h_date BETWEEN ? AND ?');
        $rows->execute([$monthStart, $monthEnd]);

        $holidayDates    = [];   // declared days off
        $workingOverride = [];   // declared WORKING days (override the weekly-off rules)
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $h) {
            if ((int) ($h['is_working_day'] ?? 0) === 1) $workingOverride[$h['h_date']] = true;
            else                                          $holidayDates[$h['h_date']]    = true;
        }

        $dates    = [];
        $satCount = 0;                               // counts Saturdays as we pass them
        for ($d = 1; $d <= $calDays; $d++) {
            $ts  = mktime(0, 0, 0, $month, $d, $year);
            $dow = (int) date('N', $ts);             // 1 = Mon … 6 = Sat … 7 = Sun
            $ds  = date('Y-m-d', $ts);

            // Saturdays must be counted before any `continue`, or the 1st/3rd
            // rule mis-numbers them once an override skips one.
            if ($dow === 6) $satCount++;

            if (isset($workingOverride[$ds])) {      // 1. explicit override wins
                $dates[] = $ds;
                continue;
            }
            if ($dow === 7) continue;                                    // 2. Sunday
            if ($dow === 6 && ($satCount === 1 || $satCount === 3)) continue; // 3. 1st/3rd Sat
            if (isset($holidayDates[$ds])) continue;                     // 4. declared holiday

            $dates[] = $ds;
        }
        return $dates;
    }

    /**
     * Resolve the fixed & variable salary that was IN EFFECT at the end of the
     * given month — so a slip for a past month uses the salary applicable then,
     * never the current (post-increment) salary.
     *
     * Resolution (point-in-time):
     *   1. The most recent salary EVENT on/before month-end, taking whichever is
     *      later of:
     *        • a salary_structures row (covers manual CTC edits), or
     *        • an employee_increments row (the audit trail of increments).
     *   2. If the month predates every salary event, the pre-increment original
     *      salary = the earliest increment's `previous_salary`.
     *   3. Fallback: employees.fixed_salary, else 0.
     *
     * NB: `is_current` is intentionally NOT used here — it marks today's active
     * salary, which is wrong for historical months.
     *
     * Returns ['fixed' => float, 'variable' => float]
     */
    public function getSalaryForMonth(int $employeeId, int $month, int $year): array
    {
        $lastDay = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));

        // Latest structure effective on/before month-end.
        $struct = $this->db->prepare(
            'SELECT gross AS amount, effective_from AS eff FROM salary_structures
              WHERE employee_id = ? AND effective_from <= ?
              ORDER BY effective_from DESC, id DESC LIMIT 1'
        );
        $struct->execute([$employeeId, $lastDay]);
        $sRow = $struct->fetch();

        // Latest increment effective on/before month-end.
        $inc = $this->db->prepare(
            'SELECT new_salary AS amount, effective_date AS eff FROM employee_increments
              WHERE employee_id = ? AND effective_date <= ?
              ORDER BY effective_date DESC, id DESC LIMIT 1'
        );
        $inc->execute([$employeeId, $lastDay]);
        $iRow = $inc->fetch();

        $candidate = null;
        if ($sRow && $iRow) {
            // The later event wins; on a tie prefer the increment (explicit change).
            $candidate = ($iRow['eff'] >= $sRow['eff']) ? $iRow['amount'] : $sRow['amount'];
        } elseif ($sRow) {
            $candidate = $sRow['amount'];
        } elseif ($iRow) {
            $candidate = $iRow['amount'];
        }

        if ($candidate === null) {
            // Month predates every salary event → original pre-increment salary.
            $prev = $this->db->prepare(
                'SELECT previous_salary FROM employee_increments
                  WHERE employee_id = ? AND effective_date > ?
                  ORDER BY effective_date ASC, id ASC LIMIT 1'
            );
            $prev->execute([$employeeId, $lastDay]);
            $p = $prev->fetchColumn();
            if ($p !== false && $p !== null) $candidate = (float)$p;
        }

        if ($candidate !== null && (float)$candidate > 0) {
            return ['fixed' => (float)$candidate, 'variable' => 0.0];
        }

        // Fallback: current CTC on the employee master.
        $emp = $this->db->prepare('SELECT fixed_salary FROM employees WHERE id = ? LIMIT 1');
        $emp->execute([$employeeId]);
        $fixed = (float)($emp->fetchColumn() ?: 0);
        return ['fixed' => $fixed, 'variable' => 0.0];
    }

    /**
     * Main calculation.  Returns a rich array consumed by both generate_slip.php
     * (to persist) and calculate.php (to render the register).
     *
     * @param  array $employee        Row from employees JOIN salary_structures
     * @param  array $components      All rows from salary_components
     * @param  int   $month           1-12
     * @param  int   $year            YYYY
     * @param  float $fixedSalary     Effective monthly CTC
     * @param  float $variableSalary  Variable pay
     * @return array
     */
    public function computePayroll(
        array $employee,
        array $components,
        int   $month,
        int   $year,
        float $fixedSalary,
        float $variableSalary = 0.0,
        bool  $persist = false,        // when true, store per-day worked_hours + deduction_amount on attendance rows
        int   $manualPaidLeaveDays = 0, // admin-entered paid leave days that convert absent days to paid (no deduction)
        ?float $manualOtHours = null    // admin-entered OT hours; null → auto-calculate from attendance
    ): array {
        $empId       = (int)$employee['id'];
        $monthStart  = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd    = date('Y-m-t', strtotime($monthStart));
        $calDays     = (int)date('t', strtotime($monthStart));   // calendar days

        // ── Working days (actual weekdays in month − weekly offs − holidays) ──
        $workingDayDates = $this->workingDayDates($month, $year);
        $workingDays     = count($workingDayDates);

        // ── Attendance counts ─────────────────────────────────────────────────
        $att = $this->getAttendance($empId, $monthStart, $monthEnd, $workingDayDates);

        // Approved PAID leave (e.g. Casual Leave) for the month counts as paid
        // leave: excluded from LOP and shown in the Paid Leave column. Approval
        // already caps paid leave at 1 per month — we honour what was approved.
        // Unpaid leave types (e.g. Loss of Pay) are NOT included, so they remain
        // LOP. Only the existing paid_leave/leave counters are adjusted — no
        // salary formula or rate is changed.
        $approvedPaidLeave = $this->getApprovedPaidLeaveDays($empId, $monthStart, $monthEnd, $workingDayDates);
        $att['paid_leave'] += $approvedPaidLeave;
        $att['leave']      += $approvedPaidLeave;

        // Absences classified as Paid Leave on the Attendance Report (attendance
        // .leave_classification = 'paid') are paid: excluded from LOP, counted as
        // paid leave. 'unpaid' / unclassified absences remain LOP. Restricted to
        // working days so a Sunday/holiday row can't accidentally pay.
        $classifiedPaidLeave = $this->getClassifiedPaidLeaveDays($empId, $monthStart, $monthEnd, $workingDayDates);
        $att['paid_leave'] += $classifiedPaidLeave;
        $att['leave']      += $classifiedPaidLeave;

        // 'present' already includes full + half + short + OD + Comp Off (all "not
        // absent"). Half and short days then incur their own deductions below.
        $presentDays = $att['present'];
        $absentDays  = max(0.0, $workingDays - $presentDays - $att['paid_leave']);

        // Admin-entered paid leave: convert up to that many ABSENT days into paid
        // leave (no deduction). E.g. 4 absent + 2 paid leaves entered → deduct only 2.
        $manualPaid = max(0, min($manualPaidLeaveDays, (int) floor($absentDays)));
        if ($manualPaid > 0) {
            $att['paid_leave'] += $manualPaid;
            $att['leave']      += $manualPaid;
            $absentDays         = max(0.0, $absentDays - $manualPaid);
        }
        $lopDays     = $absentDays;  // LOP = full absent days after leave is accounted

        // ── SANDWICH LEAVE ───────────────────────────────────────────────────
        // A weekly off or holiday BRACKETED by unpaid absences is itself unpaid.
        // Absent on the Friday and again on the Monday, with Saturday and Sunday
        // off in between? The employee was away for four days, so four are
        // deducted — the offs no longer ride free between two absences. A single
        // holiday behaves the same way: absent either side of Diwali makes Diwali
        // itself LOP.
        //
        // Classifying either bracketing day as PAID LEAVE breaks the sandwich and
        // only the remaining unpaid day is deducted. That is the escape hatch a
        // manager uses on the Attendance Report.
        $sandwichDates = $this->sandwichLeaveDates($empId, $month, $year, $workingDayDates);
        $sandwichDays  = count($sandwichDates);
        $lopDays      += $sandwichDays;

        // ── Step 1-3: Build allowances from components ────────────────────────
        $allowances = [];
        $basicFound = false;
        $sumAllowances = 0.0;

        foreach ($components as $comp) {
            if (strtolower($comp['type']) !== 'allowance') continue;
            // PF/ESI are deductions only — never treat them as earnings
            if (in_array(strtolower(trim($comp['name'])), ['pf', 'esi'], true)) continue;
            $amount = $this->applyComponent($comp, $fixedSalary);
            if ($amount <= 0) continue;

            $label = $comp['name'];
            $allowances[$label] = $amount;
            $sumAllowances += $amount;

            if (stripos($comp['name'], 'basic') !== false) {
                $basicFound = true;
            }
        }

        // Step 2: basic fallback
        $basicSalary = 0.0;
        if ($basicFound && isset($allowances['Basic Salary'])) {
            $basicSalary = $allowances['Basic Salary'];
        } elseif ($basicFound) {
            foreach ($allowances as $k => $v) {
                if (stripos($k, 'basic') !== false) { $basicSalary = $v; break; }
            }
        } else {
            $basicSalary = round($fixedSalary * self::BASIC_FALLBACK_RATIO, 2);
            $allowances  = array_merge(['Basic Salary' => $basicSalary], $allowances);
            $sumAllowances += $basicSalary;
        }

        // Step 3: special allowance fills remaining CTC
        $specialAllow = round($fixedSalary - $sumAllowances, 2);
        if ($specialAllow > 0) {
            $allowances['Special Allowance'] = $specialAllow;
        }

        /* A NEGATIVE remainder means the configured components already add up to
         * more than the CTC — e.g. someone edits HRA from 25% to 30% and the
         * percentages now total 105%. Dropping it (the old `if > 0` alone) left
         * gross earnings quietly ABOVE the salary the employee is on, on every
         * slip, with nothing anywhere to say so (security audit L-8).
         *
         * The figure is NOT clamped: silently scaling components down would be a
         * second invisible change, and the honest answer to "the configuration is
         * wrong" is to say so rather than to paper over it. It is recorded here
         * and shown on the slip, the same way H-7 surfaces an uncollectable loan.
         */
        $componentOverrun = $specialAllow < 0 ? round(-$specialAllow, 2) : 0.0;
        if ($componentOverrun > 0.01) {
            error_log(sprintf(
                'Payroll: salary components total %s against a CTC of %s (over by %s) '
                . 'for employee %s — gross earnings exceed the CTC. Check '
                . 'Settings → Salary Components.',
                number_format($sumAllowances, 2), number_format($fixedSalary, 2),
                number_format($componentOverrun, 2), (string) ($employee['id'] ?? '?')
            ));
        }

        // Step 4: variable pay
        if ($variableSalary > 0) {
            $allowances['Variable Pay'] = round($variableSalary, 2);
        }

        // ── Step 4b: UNPAID DAYS (charged on the DEDUCTION side) ─────────────
        // The EARNINGS column always shows the FULL monthly CTC breakdown — a
        // ₹15,000 employee sees the ₹15,000 split across Basic/HRA/… no matter
        // how many days were lost. Unpaid days come off in the deductions column
        // instead (LOP Amount / Others, Steps 10-12), so the slip reads "this is
        // your salary, this is what came off it".
        //
        //   paid days = calendar days − unpaid absent days − half/short shortfall
        //
        // Weekly-offs and holidays are NOT subtracted, so an employee who works
        // every working day loses nothing.
        //
        // EVERY half day counts as unpaid time here — one that was half-worked
        // and one caused by a LATE ARRIVAL alike. Both are LOP at the CTC
        // day-rate, so a single "LOP Amount" line covers them and LOP Days ×
        // per-day reconciles with it.
        $lateHalfDays = (float)($att['late_half_ded_days'] ?? 0);   // reported only
        $halfDedDays  = (float)($att['half_ded_days'] ?? 0);
        $shortDedDays = (float)($att['short_ded_days'] ?? 0);
        $unpaidDays   = $lopDays + $halfDedDays + $shortDedDays;
        $paidDays     = max(0.0, $calDays - $unpaidDays);
        $earnRatio    = $calDays > 0 ? ($paidDays / $calDays) : 0.0;

        // Full-month Basic. OT and the late penalty are priced off the standard
        // wage rate, so they keep using this figure — as does the Basic Salary
        // line printed in the earnings column.
        $basicFullMonth = $basicSalary;

        // Basic AFTER the unpaid days come off — the "new basic". PF is charged
        // on THIS figure (Step 8), not on the full-month Basic shown above: an
        // employee who lost a day contributes PF on what they actually earned.
        $basicEarned = round($basicSalary * $earnRatio, 2);

        // The whole CTC after the same unpaid days come off — ESI's wage base
        // (Step 9), the gross counterpart of $basicEarned. Equals the full CTC
        // minus the LOP Amount + Others lines, so ESI is charged on what was
        // actually earned rather than on the salary that was never paid.
        $ctcEarned = round($fixedSalary * $earnRatio, 2);

        // ── Step 5: OT ────────────────────────────────────────────────────────
        // Per-day rate = CTC ÷ calendar days (drives absent & late deductions).
        // OT rate = Basic ÷ calendar days ÷ 8 × 2  (formula: Basic ÷ days ÷ 8 × 2 × hrs).
        // OT hours: admin-entered value overrides the auto-calculated value when given.
        // A shift with OT switched off (a straight shift) never earns overtime —
        // the manual override cannot re-enable it, matching what the attendance
        // sheet stores for those employees.
        $otHours    = ($manualOtHours !== null) ? max(0.0, (float)$manualOtHours) : $att['ot_hours'];
        if (!attendance_shift_timing($empId)['shift_ot_on']) {
            $otHours = 0.0;
        }
        // Two rate bases:
        //   perDay/perHour       — CTC-based. Absent, half-day and short-hours
        //                          deductions are charged at this rate.
        //   basicPerDay/PerHour  — BASIC-based. Overtime is PAID at 2× this, and
        //                          the late penalty is CHARGED at this rate.
        $perDay       = $calDays > 0 ? round($fixedSalary / $calDays, 4) : 0;
        $perHour      = round($perDay / 8, 4);
        $basicPerDay  = $calDays > 0 ? round($basicFullMonth / $calDays, 4) : 0;
        $basicPerHour = round($basicPerDay / 8, 4);
        $otAmount     = round($otHours * $basicPerHour * 2, 2);
        if ($otAmount > 0) {
            $allowances['Overtime (' . number_format($otHours, 1) . ' hrs)'] = $otAmount;
        }

        // ── Gross earnings ────────────────────────────────────────────────────
        $grossEarnings = array_sum($allowances);

        // ── Step 6: LOP-proportional earnings ────────────────────────────────
        $lopDeduction = round($lopDays * $perDay, 2);

        // ── Step 7-9: Deductions ──────────────────────────────────────────────
        $deductions = [];

        // Component deductions
        // Skip PF/ESI rows — those are calculated authoritatively in Steps 8 & 9
        // (matches Laravel SalarySlipController::computePayroll behaviour)
        foreach ($components as $comp) {
            if (strtolower($comp['type']) !== 'deduction') continue;
            if (in_array(strtolower(trim($comp['name'])), ['pf', 'esi'], true)) continue;
            $amount = $this->applyComponent($comp, $fixedSalary);
            if ($amount <= 0) continue;
            $deductions[$comp['name']] = $amount;
        }

        // Step 8: PF (12% of the EARNED basic, capped at ₹1800) — only for
        // employees with the PF toggle on (employees.pf_enabled). Employees
        // opted out contribute nothing and get no employer match, so no PF line
        // appears on the slip.
        //
        // The base is $basicEarned — Basic after the month's unpaid days have
        // come off — not the full-month Basic printed in the earnings column.
        $pfEmployee = 0.0;
        if (!empty($employee['pf_enabled'])) {
            $pfEmployee = min(round($basicEarned * PAYROLL_PF_EMPLOYEE, 2), round(self::PF_CAP_BASIC * PAYROLL_PF_EMPLOYEE, 2));
        }
        $pfEmployer = $pfEmployee;
        if ($pfEmployee > 0) {
            $deductions['Provident Fund (PF)'] = $pfEmployee;
        }

        // Step 9: ESI — charged on $ctcEarned, the CTC AFTER the month's unpaid
        // days have come off, mirroring how PF is charged on the earned Basic.
        // A month with no LOP is unaffected: $ctcEarned == $fixedSalary.
        //
        // ELIGIBILITY is still judged on the FULL monthly CTC, not the earned
        // figure. The ESI wage ceiling applies to the wage an employee is ON;
        // testing the reduced figure would drag someone above the ceiling into
        // ESI in any month they lost enough days, and back out the next month.
        //
        // Gated by the same pf_enabled toggle, so switching PF off for an
        // employee also stops ESI.
        $esiEmployee = 0.0;
        $esiEmployer = 0.0;
        if (!empty($employee['pf_enabled']) && $fixedSalary < PAYROLL_ESI_WAGE_LIMIT) {
            $esiEmployee = round($ctcEarned * PAYROLL_ESI_EMPLOYEE, 2);
            $esiEmployer = round($ctcEarned * PAYROLL_ESI_EMPLOYER, 2);
            if ($esiEmployee > 0) {
                $deductions['ESI (Employee)'] = $esiEmployee;
            }
        }

        // Steps 10-12: LOP / Short Hours deduction lines. The earnings column
        // carries the full CTC, so every unpaid day is charged here — exactly
        // once. Together the lines come to CTC × (unpaid days ÷ calendar days),
        // so net pay matches what the earned-salary proration used to produce;
        // only the presentation changed.
        //
        // Half days are LOP: unpaid time at the CTC day-rate, folded into the LOP
        // day count and the LOP amount rather than carrying a line of their own —
        // so 2 absences + 1 half day reads as "LOP Days 2.5".
        $halfDayCount     = (int)($att['half_day'] ?? 0);
        $halfDayDeduction = round($halfDedDays * $perDay, 2);   // kept for the summary/reports

        $lopTotalDays    = $lopDays + $halfDedDays;
        $absentDeduction = $lopTotalDays > 0 ? round($lopTotalDays * $perDay, 2) : 0.0;
        if ($absentDeduction > 0) {
            $deductions['LOP Amount'] = $absentDeduction;   // slip prints "LOP Days" beneath it
        }

        // Short-hours shortfall — printed as "Others" on the slip.
        $shortDays      = (int)($att['short_days'] ?? 0);
        $shortDeduction = round($shortDedDays * $perDay, 2);
        if ($shortDeduction > 0) {
            // Added, not assigned: a salary component could itself be named
            // 'Others', and overwriting it would silently drop that deduction.
            $deductions['Others'] = round(($deductions['Others'] ?? 0) + $shortDeduction, 2);
        }

        // A half day caused by arriving at/after the cutoff (~11:00) no longer
        // gets its own Basic-rate line: it is part of $halfDedDays, so the LOP
        // Amount above already charged it at the CTC day-rate. The figure is
        // still reported in the attendance summary for the attendance screens.
        $lateHalfDeduction = round($lateHalfDays * $perDay, 2);

        // Persist per-day worked hours + deduction to the attendance rows (audit/history).
        if ($persist && !empty($att['worked_days'])) {
            $upd = $this->db->prepare('UPDATE attendance SET worked_hours = ?, deduction_amount = ? WHERE employee_id = ? AND att_date = ?');
            foreach ($att['worked_days'] as $wd) {
                $workedH = round(((int)$wd['net']) / 60, 2);
                $dedAmt  = round(((float)($wd['ded_days'] ?? 0)) * $perDay, 2);
                $upd->execute([$workedH, $dedAmt, $empId, $wd['date']]);
            }
        }

        // Late penalty: once total late for the month exceeds the grace allowance,
        // charge the FULL total late doubled (NOT just the minutes beyond grace).
        //   deductable minutes = total late × 2
        //   deduction          = deductable minutes × BASIC per-hour rate ÷ 60
        // The rate is BASIC-based (not CTC) — a late hour is charged against the
        // same wage base overtime is paid from. Absent / half-day / short-hours
        // deductions remain on the CTC rate.
        $lateDeduction     = 0.0;
        $monthlyGrace      = attendance_shift_timing($empId)['monthly_grace']; // employee's shift (legacy setting when shift-less)
        $totalLateMinutes  = $att['late_minutes'];
        $deductableLateMin = 0;
        if ($totalLateMinutes > $monthlyGrace) {
            $deductableLateMin = $totalLateMinutes * 2;                 // full total late, doubled
            $lateDeduction     = round($deductableLateMin * ($basicPerHour / 60), 2);
            if ($lateDeduction > 0) {
                $deductions['Late Deduction (' . $totalLateMinutes . ' min, 2× rate)'] = $lateDeduction;
            }
        }

        // ── Whole-rupee deductions ───────────────────────────────────────────
        // Every deduction the engine computes is charged in WHOLE RUPEES, so the
        // slip's Deductions column carries no paise and the printed lines add up
        // to the printed Total exactly. Rounding the LINES (not just what is
        // displayed) is the point: rounding at render time would leave a column
        // that visibly fails to sum to its own total.
        //
        // The statutory figures stored in their own salary_slips columns are
        // rounded with the same call, so pf_employee / esi_employee and the PF /
        // ESI lines on the slip can never disagree by a rupee.
        //
        // NOT rounded here: loan/advance EMIs added later by
        // payroll_apply_extras(). That array doubles as the loan repayment
        // ledger — loan_actual_deductions() reads every rupee in it as repaid —
        // and the final instalment is deliberately the exact outstanding
        // balance, so rounding it would strand paise on the loan forever.
        foreach ($deductions as $dLabel => $dAmt) {
            $deductions[$dLabel] = round((float)$dAmt);
        }
        $pfEmployee  = round($pfEmployee);
        $pfEmployer  = round($pfEmployer);
        $esiEmployee = round($esiEmployee);
        $esiEmployer = round($esiEmployer);
        // The attendance-summary copies of these amounts feed the CSV export and
        // the Salary Calculation register; round them to the same rupee so no
        // screen quotes a different number for the same deduction.
        $absentDeduction   = round($absentDeduction);
        $halfDayDeduction  = round($halfDayDeduction);
        $shortDeduction    = round($shortDeduction);
        $lateDeduction     = round($lateDeduction);
        $lateHalfDeduction = round($lateHalfDeduction);

        $totalDeductions = array_sum($deductions);
        $netPay          = max(0, $grossEarnings - $totalDeductions);

        // ── Attendance summary (stored as JSON) ───────────────────────────────
        // Shift(s) actually worked this month, frozen onto the slip. Reads the
        // stamped shift on each attendance row, so a slip regenerated after the
        // employee moves shifts still names the shift they worked back then.
        $shiftNames = [];
        try {
            $shSt = $this->db->prepare(
                'SELECT DISTINCT s.name FROM attendance a
                   JOIN shifts s ON s.id = a.shift_id
                  WHERE a.employee_id = ? AND a.att_date BETWEEN ? AND ?
                  ORDER BY s.name'
            );
            $shSt->execute([$empId, $monthStart, $monthEnd]);
            $shiftNames = $shSt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) { /* shifts tables absent */ }
        if (!$shiftNames) {   // no stamped rows (legacy month) → the employee's shift now
            $curShiftId = attendance_shift_timing($empId)['shift_id'];
            if ($curShiftId) {
                $cs = get_shift($curShiftId);
                if (!empty($cs['name'])) $shiftNames = [$cs['name']];
            }
        }

        $attendanceSummary = [
            'shift_name'            => $shiftNames ? implode(', ', $shiftNames) : null,
            'total_working_days'    => $workingDays,
            'present_days'          => $att['present'],
            'half_days'             => $att['half_day'],
            'leave_days'            => $att['leave'],
            'paid_leave_days'       => $att['paid_leave'],
            'approved_leave_days'   => $approvedPaidLeave,
            'absent_days'           => (float)$absentDays,
            'late_days'             => $att['late'],
            'no_checkout_absent'    => 0,
            'half_day_deduction'    => $halfDayDeduction,
            'short_days'            => $shortDays,
            'short_deduction'       => $shortDeduction,
            'ot_hours'              => $otHours,
            'ot_amount'             => $otAmount,
            'ot_per_hour_rate'      => round($basicPerHour, 2),
            'late_minutes'          => $totalLateMinutes,
            'late_grace_minutes'    => $monthlyGrace,
            'deductable_late_mins'  => $deductableLateMin,
            'late_deduction'        => $lateDeduction,
            'absent_deduction'      => $absentDeduction,
            'lop_days'              => round($lopTotalDays, 2), // absences + sandwich + half days -> the "LOP Days" slip line
            'sandwich_days'         => $sandwichDays,          // offs/holidays bracketed by unpaid absences
            'sandwich_dates'        => $sandwichDates,         // which dates those were
            'late_half_days'        => round($lateHalfDays, 2), // of half days: caused by a late arrival
            'paid_days'             => round($paidDays, 2),   // calendar days actually earned
            'unpaid_days'           => round($unpaidDays, 2), // LOP + half/short shortfall
            'earn_ratio'            => round($earnRatio, 6),  // paid_days ÷ calendar_days
            'basic_full_month'      => round($basicFullMonth, 2),
            'basic_earned'          => round($basicEarned, 2), // "new basic" after LOP — the PF base
            'ctc_earned'            => round($ctcEarned, 2),   // CTC after LOP — the ESI base
            'per_day_salary'        => round($perDay, 2),
            'per_hour_rate'         => round($perHour, 2),
            'calendar_days'         => $calDays,
            'basic_salary'          => $basicSalary,          // full-month Basic (earnings column)
            'ctc_per_month'         => $fixedSalary,
            // >0 when the configured components already exceed the CTC, so the slip
            // can say so instead of quietly showing an inflated gross (L-8).
            'component_overrun'     => $componentOverrun,
        ];

        return [
            'employee_id'        => $empId,
            'fixed_salary'       => $fixedSalary,
            'variable_salary'    => $variableSalary,
            'allowances'         => $allowances,
            'deductions'         => $deductions,
            'gross_earnings'     => $grossEarnings,
            'total_deductions'   => $totalDeductions,
            'net_pay'            => $netPay,
            'pf_employee'        => $pfEmployee,
            'pf_employer'        => $pfEmployer,
            'esi_employee'       => $esiEmployee,
            'esi_employer'       => $esiEmployer,
            'working_days'       => $workingDays,
            'present_days'       => $att['present'],
            // Whole unpaid days only: salary_slips.lop_days is an INT column, so a
            // half day would be silently rounded. The slip reads its "LOP Days"
            // line from attendance_summary['lop_days'], which keeps the fraction.
            'lop_days'           => (float)$lopDays,
            'attendance_summary' => $attendanceSummary,
            // Backward-compat fixed columns
            'basic'              => $allowances['Basic Salary'] ?? $basicSalary,
            'hra'                => $allowances['HRA'] ?? 0.0,
            'conveyance'         => $allowances['Conveyance Allowance'] ?? ($allowances['Conveyance'] ?? 0.0),
            'medical'            => $allowances['Medical Allowance'] ?? ($allowances['Medical'] ?? 0.0),
            'special_allow'      => $allowances['Special Allowance'] ?? 0.0,
            'other_allow'        => 0.0,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function applyComponent(array $comp, float $ctc): float
    {
        if ($comp['calculation_type'] === 'percentage') {
            return round((float)$comp['value'] / 100 * $ctc, 2);
        }
        return round((float)$comp['value'], 2);
    }

    /**
     * Count working-day dates in [$from,$to] covered by APPROVED, paid leave
     * requests for the employee. Used to credit approved Casual/Sick/etc. leave
     * as paid leave (no LOP). Unpaid leave types (is_paid = 0) are excluded.
     * Each working day is counted once even if multiple requests overlap.
     */
    private function getApprovedPaidLeaveDays(int $empId, string $from, string $to, array $workingDayDates): int
    {
        if (!$workingDayDates) return 0;
        try {
            $stmt = $this->db->prepare(
                "SELECT lr.start_date, lr.end_date
                   FROM leave_requests lr
                   JOIN leave_types lt ON lt.id = lr.leave_type_id
                  WHERE lr.employee_id = ?
                    AND lr.status = 'approved'
                    AND lt.is_paid = 1
                    AND lr.end_date >= ? AND lr.start_date <= ?"
            );
            $stmt->execute([$empId, $from, $to]);
            $reqs = $stmt->fetchAll();
        } catch (Throwable $e) {
            return 0;   // leave tables not present on this install — no effect
        }

        $workingSet = array_flip($workingDayDates);
        $counted    = [];
        foreach ($reqs as $r) {
            $d = new DateTime($r['start_date'] < $from ? $from : $r['start_date']);
            $e = new DateTime($r['end_date']   > $to   ? $to   : $r['end_date']);
            for (; $d <= $e; $d->modify('+1 day')) {
                $ds = $d->format('Y-m-d');
                if (isset($workingSet[$ds])) $counted[$ds] = true;
            }
        }
        return count($counted);
    }

    /**
     * Weekly offs / holidays that turn into LOP because they are SANDWICHED
     * between two unpaid absences — "sandwich leave".
     *
     * A maximal run of consecutive non-working days is charged when BOTH the
     * working day immediately before it and the working day immediately after it
     * are unpaid absences. Fri absent + Sat/Sun off + Mon absent → the Sat and Sun
     * join the Fri and Mon, so 4 days are deducted rather than 2. A lone holiday
     * behaves the same way: absent either side of Diwali makes Diwali unpaid.
     *
     * BOTH bracketing days must fall inside the payroll month. An absence in the
     * neighbouring month never reaches in to charge this one: it belongs to that
     * month's payroll, which already deducted it, and a slip must not move when a
     * different month's attendance is edited. So with 1-3 May off (May Day plus
     * the weekend), absent on 30 April and again on 4 May, May is charged for the
     * 4th alone — the 30th is April's business. The same applies at the end of the
     * month: an off-run closing the month is never sandwiched by an absence dated
     * the 1st of the next.
     *
     * A bracketing day counts as unpaid ONLY when it carries an explicit 'Absent'
     * attendance row that is not classified 'paid' and is not covered by an
     * approved paid leave request. Two consequences worth knowing:
     *   • Marking either side as Paid Leave dissolves the sandwich — the intended
     *     manager override.
     *   • A working day with NO attendance row at all never brackets a sandwich.
     *     Ordinary LOP already treats a missing row as an absence, but charging
     *     EXTRA days off the back of data nobody has entered would be a poor
     *     trade; import or mark the day and the sandwich follows.
     *
     * @return string[] dates ('Y-m-d') inside the month that become LOP
     */
    private function sandwichLeaveDates(int $empId, int $month, int $year, array $workingDayDates): array
    {
        return $this->sandwichLeaveDatesBulk([$empId], $month, $year, $workingDayDates)[$empId] ?? [];
    }

    /**
     * The same rule for MANY employees in one pass — what the attendance screens
     * call so a month grid does not fire five queries per employee.
     *
     * @param  int[] $empIds
     * @return array<int, string[]> employee id => sandwich dates ('Y-m-d')
     */
    public function sandwichLeaveDatesBulk(array $empIds, int $month, int $year, ?array $workingDayDates = null): array
    {
        $out = [];
        foreach ($this->sandwichSpansBulk($empIds, $month, $year, $workingDayDates) as $eid => $spans) {
            $out[$eid] = $spans ? array_merge(...array_column($spans, 'dates')) : [];
        }
        return $out;
    }

    /**
     * The sandwiches themselves, not just the days they cost.
     *
     * Each span is ['before' => date, 'after' => date, 'dates' => [off dates]]:
     * the two LEAVE days that closed the sandwich, and the company-leave days in
     * between that become LOP because of them. The attendance grids badge the
     * 'before'/'after' days — a non-working day is drawn as one cell spanning
     * every employee row, so the offs themselves have nowhere to carry a
     * per-employee marker.
     *
     * The runs of non-working days and their bracketing dates are pure calendar,
     * identical for everyone, so they are worked out once; only the "was this
     * employee absent on both brackets?" test is per employee. Two queries total,
     * whatever the headcount.
     *
     * @param  int[] $empIds
     * @return array<int, array<int, array{before:string,after:string,dates:string[]}>>
     */
    public function sandwichSpansBulk(array $empIds, int $month, int $year, ?array $workingDayDates = null): array
    {
        $empIds = array_values(array_unique(array_map('intval', $empIds)));
        $out    = array_fill_keys($empIds, []);
        if (!$empIds) return $out;

        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd   = date('Y-m-t', strtotime($monthStart));
        $from       = date('Y-m-d', strtotime($monthStart . ' -1 month'));
        $to         = date('Y-m-d', strtotime($monthEnd   . ' +1 month'));

        // Working days across the previous, current and next month.
        $workingSet = [];
        foreach ($workingDayDates ?? $this->workingDayDates($month, $year) as $ds) $workingSet[$ds] = true;
        foreach (['-1 month', '+1 month'] as $shift) {
            $ts = strtotime($monthStart . ' ' . $shift);
            foreach ($this->workingDayDates((int)date('n', $ts), (int)date('Y', $ts)) as $ds) {
                $workingSet[$ds] = true;
            }
        }

        // ── Calendar-only: the runs of non-working days worth testing ────────
        $runs   = [];
        $cursor = new DateTime($from);
        $last   = new DateTime($to);
        while ($cursor <= $last) {
            if (isset($workingSet[$cursor->format('Y-m-d')])) { $cursor->modify('+1 day'); continue; }

            $run = [];
            while ($cursor <= $last && !isset($workingSet[$cursor->format('Y-m-d')])) {
                $run[] = $cursor->format('Y-m-d');
                $cursor->modify('+1 day');
            }
            // The run is maximal, so the day before it and the day the loop
            // stopped on are the bracketing WORKING days. A run touching either
            // edge of the window has no known bracket and is left alone.
            $before = date('Y-m-d', strtotime($run[0] . ' -1 day'));
            $after  = $cursor <= $last ? $cursor->format('Y-m-d') : null;
            if ($after === null) continue;

            // Both brackets must be THIS month's days — see the note above.
            if ($before < $monthStart || $after > $monthEnd) continue;

            $inMonth = array_values(array_filter($run, fn($ds) => $ds >= $monthStart && $ds <= $monthEnd));
            if ($inMonth) $runs[] = ['before' => $before, 'after' => $after, 'dates' => $inMonth];
        }
        if (!$runs) return $out;

        // ── Per employee: unpaid absences on working days across the window ──
        $ph = implode(',', array_fill(0, count($empIds), '?'));
        try {
            $stmt = $this->db->prepare(
                "SELECT employee_id, att_date FROM attendance
                  WHERE employee_id IN ($ph) AND status = 'Absent'
                    AND (leave_classification IS NULL OR leave_classification <> 'paid')
                    AND att_date BETWEEN ? AND ?"
            );
            $stmt->execute(array_merge($empIds, [$from, $to]));
        } catch (Throwable $e) {
            // leave_classification column absent on this install — every Absent
            // row is unpaid there, so the plain query is the right fallback.
            $stmt = $this->db->prepare(
                "SELECT employee_id, att_date FROM attendance
                  WHERE employee_id IN ($ph) AND status = 'Absent' AND att_date BETWEEN ? AND ?"
            );
            $stmt->execute(array_merge($empIds, [$from, $to]));
        }
        $unpaid = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ds = $r['att_date'];
            if (isset($workingSet[$ds])) $unpaid[(int)$r['employee_id']][$ds] = true;
        }

        // An approved PAID leave request also makes a day paid, so it can never
        // bracket a sandwich even if an Absent row was left behind for it.
        foreach ($this->approvedPaidLeaveDatesBulk($empIds, $from, $to) as $eid => $dates) {
            foreach ($dates as $ds) unset($unpaid[$eid][$ds]);
        }

        foreach ($empIds as $eid) {
            if (empty($unpaid[$eid])) continue;
            foreach ($runs as $run) {
                if (isset($unpaid[$eid][$run['before']]) && isset($unpaid[$eid][$run['after']])) {
                    $out[$eid][] = $run;
                }
            }
        }
        return $out;
    }

    /**
     * Dates covered by APPROVED, paid leave requests in [$from,$to], for many
     * employees in a single query. Not filtered to working days: the sandwich
     * check needs to know a day was paid however the calendar falls.
     *
     * @param  int[] $empIds
     * @return array<int, string[]> employee id => dates ('Y-m-d')
     */
    private function approvedPaidLeaveDatesBulk(array $empIds, string $from, string $to): array
    {
        if (!$empIds) return [];
        $ph = implode(',', array_fill(0, count($empIds), '?'));
        try {
            $stmt = $this->db->prepare(
                "SELECT lr.employee_id, lr.start_date, lr.end_date
                   FROM leave_requests lr
                   JOIN leave_types lt ON lt.id = lr.leave_type_id
                  WHERE lr.employee_id IN ($ph)
                    AND lr.status = 'approved'
                    AND lt.is_paid = 1
                    AND lr.end_date >= ? AND lr.start_date <= ?"
            );
            $stmt->execute(array_merge($empIds, [$from, $to]));
            $reqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];   // leave tables not present on this install — no effect
        }

        $seen = [];
        foreach ($reqs as $r) {
            $eid = (int)$r['employee_id'];
            $d = new DateTime($r['start_date'] < $from ? $from : $r['start_date']);
            $e = new DateTime($r['end_date']   > $to   ? $to   : $r['end_date']);
            for (; $d <= $e; $d->modify('+1 day')) $seen[$eid][$d->format('Y-m-d')] = true;
        }
        $out = [];
        foreach ($seen as $eid => $dates) $out[$eid] = array_keys($dates);
        return $out;
    }

    /**
     * Working days in the month where an Absent attendance row was classified as
     * Paid Leave (attendance.leave_classification = 'paid'). These are paid and
     * excluded from LOP. Capped implicitly at 1/month by the report's save guard.
     */
    private function getClassifiedPaidLeaveDays(int $empId, string $from, string $to, array $workingDayDates): int
    {
        if (!$workingDayDates) return 0;
        try {
            $stmt = $this->db->prepare(
                "SELECT att_date FROM attendance
                  WHERE employee_id = ? AND status = 'Absent'
                    AND leave_classification = 'paid'
                    AND att_date BETWEEN ? AND ?"
            );
            $stmt->execute([$empId, $from, $to]);
            $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return 0;   // leave_classification column absent on this install — no effect
        }
        $workingSet = array_flip($workingDayDates);
        $counted = 0;
        foreach ($dates as $ds) {
            if (isset($workingSet[$ds])) $counted++;
        }
        return $counted;
    }

    /**
     * Attendance rows marked 'Holiday' that fall on an actual working day.
     *
     * A 'Holiday' row only represents PAID time off when the day would otherwise
     * have been worked. Sundays, 1st/3rd Saturdays and declared holidays are
     * already excluded from $workingDays, so a Holiday row on one of those dates
     * must NOT be counted — otherwise it cancels a genuine absent day. Biometric
     * imports map week-offs to 'Holiday' and produce 5-8 such rows per month.
     */
    private function countHolidayDaysOnWorkingDays(int $empId, string $from, string $to, array $workingDayDates): int
    {
        if (!$workingDayDates) return 0;
        $stmt = $this->db->prepare(
            "SELECT att_date FROM attendance
              WHERE employee_id = ? AND status = 'Holiday'
                AND att_date BETWEEN ? AND ?"
        );
        $stmt->execute([$empId, $from, $to]);
        $workingSet = array_flip($workingDayDates);
        $counted = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ds) {
            if (isset($workingSet[$ds])) $counted++;
        }
        return $counted;
    }

    private function getAttendance(int $empId, string $from, string $to, array $workingDayDates = []): array
    {
        // The employee's CURRENT shift timing (legacy global settings fall back
        // inside the helper when no shift is assigned).
        $curT = attendance_shift_timing($empId);

        // Legacy global fallbacks — used only for rows with no shift anywhere.
        $officeStart  = setting_office_start();        // 'HH:MM' — late measured from here
        $triggerMins  = setting_ot_trigger_mins();     // checkout must reach this for OT
        $baselineMins = setting_ot_baseline_mins();    // OT hours counted from here

        // Late minutes counted from the SHIFT's start; OT from the SHIFT's
        // trigger/baseline. Thresholds resolve PER ROW: the shift stamped on the
        // attendance row (frozen at mark time), else the employee's current
        // shift, else the legacy globals. A shift with ot_enabled = 0 (straight
        // shift) contributes ZERO OT minutes regardless of checkout time.
        $stmt = $this->db->prepare(
            'SELECT a.status, COUNT(*) AS cnt,
                    SUM(CASE WHEN a.in_time IS NOT NULL AND a.out_time IS NOT NULL
                             THEN GREATEST(0, TIMESTAMPDIFF(MINUTE,
                                  CONCAT(a.att_date," ",COALESCE(s.start_time, ?)),
                                  CONCAT(a.att_date," ",a.in_time)))
                             ELSE 0 END) AS late_mins,
                    SUM(CASE
                             /* shift says no OT, or has OT on but no trigger/baseline
                                configured — mark.php grants no auto-OT in either
                                state, so payroll must agree. */
                             WHEN s.id IS NOT NULL AND (s.ot_enabled = 0
                                  OR s.ot_trigger_time IS NULL OR s.ot_baseline_time IS NULL) THEN 0
                             WHEN a.out_time IS NOT NULL
                              AND (HOUR(a.out_time) * 60 + MINUTE(a.out_time)) >=
                                  COALESCE(HOUR(s.ot_trigger_time) * 60 + MINUTE(s.ot_trigger_time), ?)
                             THEN GREATEST(0, (HOUR(a.out_time) * 60 + MINUTE(a.out_time)) -
                                  COALESCE(HOUR(s.ot_baseline_time) * 60 + MINUTE(s.ot_baseline_time), ?))
                             ELSE 0 END) AS ot_mins
             FROM attendance a
             LEFT JOIN employees emp ON emp.id = a.employee_id
             LEFT JOIN shifts    s   ON s.id = COALESCE(a.shift_id, emp.shift_id)
             WHERE a.employee_id = ? AND a.att_date BETWEEN ? AND ?
             GROUP BY a.status'
        );
        $stmt->execute([$officeStart, $triggerMins, $baselineMins, $empId, $from, $to]);
        $rows = $stmt->fetchAll();

        $map = [];
        foreach ($rows as $r) {
            $map[$r['status']] = [
                'cnt'  => (int)$r['cnt'],
                'late' => (float)$r['late_mins'],
                'ot'   => (float)$r['ot_mins'],
            ];
        }

        // Holiday rows count as paid leave ONLY on days that were actually
        // working days. The importers map 'h' / 'holiday' / 'week off' / 'weekoff'
        // to 'Holiday', so a biometric export writes a Holiday row for every
        // Sunday, 1st/3rd Saturday and declared holiday — days already excluded
        // from $workingDays. Counting those would cancel one genuine absent day
        // each and silently pay an absent employee in full.
        $leave   = $this->countHolidayDaysOnWorkingDays($empId, $from, $to, $workingDayDates);
        $absent  = $map['Absent']['cnt']   ?? 0;
        $odCnt   = $map['OD']['cnt']        ?? 0;
        $compCnt = $map['Comp Off']['cnt']  ?? 0;

        // OT hours: minutes worked past office-end across present statuses.
        // OT applies to any worked day whose checkout reaches the OT trigger —
        // including Half Day (an employee can still work past the OT baseline).
        $otMinutes = 0.0;
        foreach (['On Time', 'Late', 'Half Day', 'OD'] as $s) {
            $otMinutes += $map[$s]['ot'] ?? 0;
        }
        $otHours = round($otMinutes / 60, 2);

        // ── Classify each punched worked day (see attendance_classify) ────────
        // Net worked = (check-out − check-in) − the break windows (the employee's
        // lunch batch + the 2 tea breaks) that fall within the presence window.
        //   check-in after ~11:00     → HALF    (no late; ≥ ½ day off)        → "Half Day Deduction"
        //   net < 4h (on-time-ish)    → SHORT   (no late; pro-rated on hours)  → "Short Hours Deduction"
        //   net = 4h (on-time-ish)    → HALF    (no late; ½ day)               → "Half Day Deduction"
        //   net > 4h (on-time-ish)    → PRESENT (late applies; pro-rated)      → "Short Hours Deduction"
        //                               (FULL when net ≥ 8h → no shortfall)
        // Per-row timing (stamped shift → current shift → legacy globals) is
        // resolved inside the loop by attendance_row_timing().
        $eoStmt = $this->db->prepare(
            "SELECT att_date, in_time, out_time, status, shift_id FROM attendance
              WHERE employee_id = ? AND att_date BETWEEN ? AND ?
                AND status IN ('On Time','Late','Half Day')
                AND in_time IS NOT NULL AND out_time IS NOT NULL"
        );
        $eoStmt->execute([$empId, $from, $to]);
        $fullWorked = 0; $halfWorked = 0; $presentPartial = 0;
        $halfDedDays = 0.0; $shortDedDays = 0.0; $lateHalfDedDays = 0.0;
        $lateMinsPool = 0; $lateDaysPool = 0; $workedDays = [];
        foreach ($eoStmt->fetchAll() as $r) {
            $inM  = time_to_mins((string)$r['in_time']);
            $outM = time_to_mins((string)$r['out_time']);
            // Overnight shift (e.g. 22:00 -> 06:00): out-time-of-day is numerically
            // SMALLER than in-time-of-day. `continue`-ing here dropped the row
            // entirely — neither present nor absent-by-status, so LOP charged it
            // as an absence even though the employee worked. attendance/index.php
            // and report.php already add 1440 for exactly this case; this used to
            // be the one place that didn't (security audit H-8).
            if ($outM <= $inM) $outM += 1440;

            // Resolve this ROW's shift: the one stamped at mark time, else the
            // employee's current shift, else the legacy globals. This freezes
            // historical months to the shift the employee actually worked.
            // Shared with the attendance reports so both agree — see helpers.php.
            $rt  = attendance_row_timing(isset($r['shift_id']) ? (int)$r['shift_id'] : null, $empId);
            $osM = $rt['start_mins'];
            $oeM = $rt['end_mins'];
            // The shift's own declared end is likewise a same-day clock time
            // (e.g. 06:00 -> 360), so it needs the identical +1440 normalization
            // whenever it is an overnight shift (end <= start) — otherwise
            // attendance_classify()'s "left before office end" check compares an
            // adjusted $outM against an unadjusted $oeM and silently mis-fires:
            // outM (now 1400+) always looks >= the tiny raw $oeM, so an employee
            // who left an overnight shift hours early would be credited a full,
            // undeducted day instead of the short/half day they actually worked.
            if ($oeM <= $osM) $oeM += 1440;
            $grM = $rt['daily_grace'];
            $hcM = $rt['shift_id'] !== null ? $rt['half_cutoff'] : null;

            $net = max(0, ($outM - $inM) - break_minutes_within($inM, $outM, $rt['lunch'], $rt['breaks']));
            // Type (short / half / present) is decided by the actual hours + check-in
            // time, not by the stored status label — see attendance_classify().
            $c   = attendance_classify($net, $inM, $osM, $grM, $outM, $oeM, $hcM);
            if ($c['status'] === 'half') {
                $halfWorked++;     $halfDedDays  += $c['ded_days'];   // "Half Day" line, no late
                // A half day caused by arriving at/after the cutoff is tracked
                // separately — payroll charges THAT one against Basic, while a
                // genuinely half-worked/availed day stays on the CTC rate.
                if (($c['reason'] ?? '') === 'late_arrival') $lateHalfDedDays += $c['ded_days'];
            } elseif ($c['status'] === 'short' || $c['status'] === 'present') {
                $presentPartial++; $shortDedDays += $c['ded_days'];   // "Short Hours" line (late only if 'present')
            } else {                                                  // 'full'
                $fullWorked++;
            }
            if ($c['late']) { $lateMinsPool += ($inM - $osM); $lateDaysPool++; }
            $workedDays[] = ['date' => $r['att_date'], 'net' => $net, 'status' => $c['status'], 'ded_days' => $c['ded_days']];
        }
        $present = $fullWorked + $presentPartial + $halfWorked + $odCnt + $compCnt;   // all "not absent"

        return [
            'present'        => $present,
            'half_day'       => $halfWorked,
            'half_ded_days'  => round($halfDedDays, 4),    // total ½-day-equivalent to deduct
            'late_half_ded_days' => round($lateHalfDedDays, 4), // of which caused by a late (≥cutoff) arrival
            'short_days'     => $presentPartial,           // present-but-under-8h days
            'short_ded_days' => round($shortDedDays, 4),   // total shortfall (in days) to deduct
            'worked_days'    => $workedDays,               // per-day [date, net, status, ded_days] for write-back
            'leave'          => $leave,
            'paid_leave'     => $leave,                    // holidays counted as paid leave
            'absent'         => $absent,
            'late'           => $lateDaysPool,             // late present/full days
            'late_minutes'   => $lateMinsPool,             // late-penalty pool
            'ot_hours'       => $otHours,
        ];
    }

    private function formatDays(float $days): string
    {
        if ($days == (int)$days) return (string)(int)$days;
        return number_format($days, 1);
    }
}
