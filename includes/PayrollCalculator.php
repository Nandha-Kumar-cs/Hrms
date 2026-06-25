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
        // Weekly off rule:
        //   • Every Sunday is off.
        //   • The 1st and 3rd Saturday of the month are off.
        //   • 2nd / 4th / 5th Saturdays are WORKING days.
        // Plus any declared holiday is excluded.  The figure therefore reflects
        // the real month (varies Jan/Feb/etc.) rather than a flat constant.
        $holRows = $this->db->prepare('SELECT h_date FROM holidays WHERE h_date BETWEEN ? AND ?');
        $holRows->execute([$monthStart, $monthEnd]);
        $holidayDates = array_flip($holRows->fetchAll(PDO::FETCH_COLUMN));

        $workingDays     = 0;
        $satCount        = 0;                        // counts Saturdays as we pass them
        $workingDayDates = [];                       // 'Y-m-d' of each working day
        for ($d = 1; $d <= $calDays; $d++) {
            $ts  = mktime(0, 0, 0, $month, $d, $year);
            $dow = (int)date('N', $ts);              // 1 = Mon … 6 = Sat … 7 = Sun
            $ds  = date('Y-m-d', $ts);

            if ($dow === 7) continue;                // Sunday — weekly off
            if ($dow === 6) {                        // Saturday
                $satCount++;
                if ($satCount === 1 || $satCount === 3) continue; // 1st & 3rd Sat off
            }
            if (isset($holidayDates[$ds])) continue; // declared holiday
            $workingDays++;
            $workingDayDates[] = $ds;
        }

        // ── Attendance counts ─────────────────────────────────────────────────
        $att = $this->getAttendance($empId, $monthStart, $monthEnd);

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

        // Step 4: variable pay
        if ($variableSalary > 0) {
            $allowances['Variable Pay'] = round($variableSalary, 2);
        }

        // ── Step 5: OT ────────────────────────────────────────────────────────
        // Per-day rate = CTC ÷ calendar days (drives absent & late deductions).
        // OT rate = Basic ÷ calendar days ÷ 8 × 2  (formula: Basic ÷ days ÷ 8 × 2 × hrs).
        // OT hours: admin-entered value overrides the auto-calculated value when given.
        $otHours    = ($manualOtHours !== null) ? max(0.0, (float)$manualOtHours) : $att['ot_hours'];
        $perDay     = $calDays > 0 ? round($fixedSalary / $calDays, 4) : 0;
        $perHour    = round($perDay / 8, 4);
        $otPerDay   = $calDays > 0 ? round($basicSalary / $calDays, 4) : 0;
        $otPerHour  = round($otPerDay / 8, 4);
        $otAmount   = round($otHours * $otPerHour * 2, 2);
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

        // Step 8: PF (12% of basic, capped at ₹1800)
        $pfEmployee = min(round($basicSalary * PAYROLL_PF_EMPLOYEE, 2), round(self::PF_CAP_BASIC * PAYROLL_PF_EMPLOYEE, 2));
        $pfEmployer = $pfEmployee;
        if ($pfEmployee > 0) {
            $deductions['Provident Fund (PF)'] = $pfEmployee;
        }

        // Step 9: ESI
        $esiEmployee = 0.0;
        $esiEmployer = 0.0;
        if ($fixedSalary < PAYROLL_ESI_WAGE_LIMIT) {
            $esiEmployee = round($fixedSalary * PAYROLL_ESI_EMPLOYEE, 2);
            $esiEmployer = round($fixedSalary * PAYROLL_ESI_EMPLOYER, 2);
            if ($esiEmployee > 0) {
                $deductions['ESI (Employee)'] = $esiEmployee;
            }
        }

        // Step 10: Absent deduction
        if ($absentDays > 0) {
            $absentDeduction = round($absentDays * $perDay, 2);
            if ($absentDeduction > 0) {
                $deductions['Absent Deduction (' . $this->formatDays($absentDays) . ' days)'] = $absentDeduction;
            }
        } else {
            $absentDeduction = 0.0;
        }

        // Half days (check-in after ~11:00, or net ≤ 4h) → NO late penalty; salary
        // pro-rated on worked hours (≥ ½ day; at exactly 4h this equals ½ day).
        $halfDayCount     = (int)($att['half_day'] ?? 0);
        $halfDayDeduction = round((float)($att['half_ded_days'] ?? 0) * $perDay, 2);
        if ($halfDayDeduction > 0) {
            $deductions['Half Day Deduction (' . $halfDayCount . ' day' . ($halfDayCount === 1 ? '' : 's') . ')'] = $halfDayDeduction;
        }
        // Present but under 8h (check-in within cutoff, worked > 4h, early checkout) →
        // pro-rate the shortfall hours. These days still incur the late penalty below.
        $shortDays      = (int)($att['short_days'] ?? 0);
        $shortDeduction = round((float)($att['short_ded_days'] ?? 0) * $perDay, 2);
        if ($shortDeduction > 0) {
            $deductions['Short Hours Deduction (' . $shortDays . ' day' . ($shortDays === 1 ? '' : 's') . ')'] = $shortDeduction;
        }

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
        //   deduction          = deductable minutes × per-hour rate ÷ 60
        // (matches Laravel SalarySlipController behaviour.)
        $lateDeduction     = 0.0;
        $monthlyGrace      = setting_monthly_grace_mins(); // from Grace Settings (default 90)
        $totalLateMinutes  = $att['late_minutes'];
        $deductableLateMin = 0;
        if ($totalLateMinutes > $monthlyGrace) {
            $deductableLateMin = $totalLateMinutes * 2;                 // full total late, doubled
            $lateDeduction     = round($deductableLateMin * ($perHour / 60), 2);
            if ($lateDeduction > 0) {
                $deductions['Late Deduction (' . $totalLateMinutes . ' min, 2× rate)'] = $lateDeduction;
            }
        }

        $totalDeductions = array_sum($deductions);
        $netPay          = max(0, $grossEarnings - $totalDeductions);

        // ── Attendance summary (stored as JSON) ───────────────────────────────
        $attendanceSummary = [
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
            'ot_per_hour_rate'      => round($otPerHour, 2),
            'late_minutes'          => $totalLateMinutes,
            'late_grace_minutes'    => $monthlyGrace,
            'deductable_late_mins'  => $deductableLateMin,
            'late_deduction'        => $lateDeduction,
            'absent_deduction'      => $absentDeduction,
            'per_day_salary'        => round($perDay, 2),
            'per_hour_rate'         => round($perHour, 2),
            'calendar_days'         => $calDays,
            'basic_salary'          => $basicSalary,
            'ctc_per_month'         => $fixedSalary,
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
            'lop_days'           => (float)$absentDays,
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

    private function getAttendance(int $empId, string $from, string $to): array
    {
        // Timing pulled from app_settings (OT Settings / Grace Settings pages).
        $officeStart  = setting_office_start();        // 'HH:MM' — late measured from here
        $triggerMins  = setting_ot_trigger_mins();     // checkout must reach this for OT
        $baselineMins = setting_ot_baseline_mins();    // OT hours counted from here

        // Late minutes counted from office start.
        // OT minutes = checkout − baseline, but ONLY when checkout ≥ OT trigger time
        // (matches Laravel OT rule: "checkout ≥ Trigger → OT = (checkout − baseline)/60").
        $stmt = $this->db->prepare(
            'SELECT status, COUNT(*) AS cnt,
                    SUM(CASE WHEN in_time IS NOT NULL AND out_time IS NOT NULL
                             THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, CONCAT(att_date," ",?), CONCAT(att_date," ",in_time)))
                             ELSE 0 END) AS late_mins,
                    SUM(CASE WHEN out_time IS NOT NULL
                              AND (HOUR(out_time) * 60 + MINUTE(out_time)) >= ?
                             THEN GREATEST(0, (HOUR(out_time) * 60 + MINUTE(out_time)) - ?)
                             ELSE 0 END) AS ot_mins
             FROM attendance
             WHERE employee_id = ? AND att_date BETWEEN ? AND ?
             GROUP BY status'
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

        $leave   = $map['Holiday']['cnt']  ?? 0;   // holidays in attendance = paid
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
        $officeStartM = (int) setting_office_start_mins();
        $officeEndM   = (int) setting_office_end_mins();
        $graceM       = (int) setting_daily_grace_mins();
        $lunchWin     = function_exists('employee_lunch_window') ? employee_lunch_window($empId) : null;
        $eoStmt = $this->db->prepare(
            "SELECT att_date, in_time, out_time, status FROM attendance
              WHERE employee_id = ? AND att_date BETWEEN ? AND ?
                AND status IN ('On Time','Late','Half Day')
                AND in_time IS NOT NULL AND out_time IS NOT NULL"
        );
        $eoStmt->execute([$empId, $from, $to]);
        $fullWorked = 0; $halfWorked = 0; $presentPartial = 0;
        $halfDedDays = 0.0; $shortDedDays = 0.0;
        $lateMinsPool = 0; $lateDaysPool = 0; $workedDays = [];
        foreach ($eoStmt->fetchAll() as $r) {
            $inM  = time_to_mins((string)$r['in_time']);
            $outM = time_to_mins((string)$r['out_time']);
            if ($outM <= $inM) continue;
            $net = max(0, ($outM - $inM) - break_minutes_within($inM, $outM, $lunchWin));
            // Type (short / half / present) is decided by the actual hours + check-in
            // time, not by the stored status label — see attendance_classify().
            $c   = attendance_classify($net, $inM, $officeStartM, $graceM, $outM, $officeEndM);
            if ($c['status'] === 'half') {
                $halfWorked++;     $halfDedDays  += $c['ded_days'];   // "Half Day" line, no late
            } elseif ($c['status'] === 'short' || $c['status'] === 'present') {
                $presentPartial++; $shortDedDays += $c['ded_days'];   // "Short Hours" line (late only if 'present')
            } else {                                                  // 'full'
                $fullWorked++;
            }
            if ($c['late']) { $lateMinsPool += ($inM - $officeStartM); $lateDaysPool++; }
            $workedDays[] = ['date' => $r['att_date'], 'net' => $net, 'status' => $c['status'], 'ded_days' => $c['ded_days']];
        }
        $present = $fullWorked + $presentPartial + $halfWorked + $odCnt + $compCnt;   // all "not absent"

        return [
            'present'        => $present,
            'half_day'       => $halfWorked,
            'half_ded_days'  => round($halfDedDays, 4),    // total ½-day-equivalent to deduct
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
