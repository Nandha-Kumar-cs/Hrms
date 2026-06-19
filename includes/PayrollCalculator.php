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
        float $variableSalary = 0.0
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

        $presentDays = $att['present'] + ($att['half_day'] * 0.5);
        $absentDays  = max(0.0, $workingDays - $presentDays - $att['paid_leave']);
        $lopDays     = $absentDays;  // LOP = absent after leave is accounted

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
        $otHours    = $att['ot_hours'];
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

        $presentStatuses = ['On Time', 'OD', 'Comp Off'];
        $present = 0;
        foreach ($presentStatuses as $s) {
            $present += $map[$s]['cnt'] ?? 0;
        }
        $lateCnt  = $map['Late']['cnt']     ?? 0;
        $present += $lateCnt;               // late is still present
        $halfDay  = $map['Half Day']['cnt'] ?? 0;
        $leave    = $map['Holiday']['cnt']  ?? 0; // holidays in attendance = paid
        $absent   = $map['Absent']['cnt']   ?? 0;

        // Late minutes only from rows marked Late (already worked but arrived late)
        $lateMinutes = (int)($map['Late']['late'] ?? 0);

        // OT hours: minutes worked past office-end across all present statuses
        // (On Time, Late, OD all eligible — Comp Off and Half Day intentionally excluded)
        $otMinutes = 0.0;
        foreach (['On Time', 'Late', 'OD'] as $s) {
            $otMinutes += $map[$s]['ot'] ?? 0;
        }
        $otHours = round($otMinutes / 60, 2);

        return [
            'present'      => $present,
            'half_day'     => $halfDay,
            'leave'        => $leave,
            'paid_leave'   => $leave,          // holidays counted as paid leave
            'absent'       => $absent,
            'late'         => $lateCnt,
            'late_minutes' => $lateMinutes,
            'ot_hours'     => $otHours,
        ];
    }

    private function formatDays(float $days): string
    {
        if ($days == (int)$days) return (string)(int)$days;
        return number_format($days, 1);
    }
}
