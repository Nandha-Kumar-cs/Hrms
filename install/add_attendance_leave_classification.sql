-- ─────────────────────────────────────────────────────────────────────────────
-- Classify an ABSENT attendance day as Paid or Unpaid leave.
--
-- Only meaningful when status = 'Absent'. NULL = unclassified absence (treated as
-- LOP, same as before). 'paid'  = excluded from LOP, capped at 1 per month/employee.
-- 'unpaid' = explicit LOP (deducted), unlimited.
--
-- Reflected in the Attendance Report and in payroll (includes/PayrollCalculator.php).
-- Idempotent: safe to run multiple times.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE attendance
    ADD COLUMN IF NOT EXISTS leave_classification ENUM('paid','unpaid') NULL AFTER status;
