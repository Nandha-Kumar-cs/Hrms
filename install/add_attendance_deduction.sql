-- ─────────────────────────────────────────────────────────────────────────────
-- Per-day worked hours + salary deduction stored on the attendance row (audit/history).
--   worked_hours     = (check-out − check-in) − breaks (lunch batch + tea), in hours
--   deduction_amount = salary deducted for that day (short-hours < 4h, or half-day)
-- Populated when payroll is generated. Idempotent.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE attendance
    ADD COLUMN IF NOT EXISTS worked_hours     DECIMAL(5,2)  NULL AFTER out_time,
    ADD COLUMN IF NOT EXISTS deduction_amount DECIMAL(10,2) NULL AFTER worked_hours;
