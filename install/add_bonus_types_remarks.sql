-- ─────────────────────────────────────────────────────────────────────────────
-- Bonuses & Incentives — bring to parity with the reference Employee_Management
-- project: the richer `type` set, a separate `remarks` field and `added_by`.
--
--   type set  : monthly_bonus | performance | festival | overtime | one_time
--   remarks   : free-text note, separate from the required `reason`
--   added_by  : the user who recorded the bonus
--
-- Existing rows are mapped: bonus→monthly_bonus, incentive→performance,
-- commission→one_time. Idempotent.
-- ─────────────────────────────────────────────────────────────────────────────

-- 1) Widen the enum to hold BOTH old and new values during the remap.
ALTER TABLE employee_bonuses
  MODIFY COLUMN type ENUM('bonus','incentive','commission',
                          'monthly_bonus','performance','festival','overtime','one_time') NOT NULL;

-- 2) Remap any legacy values to the new set.
UPDATE employee_bonuses SET type='monthly_bonus' WHERE type='bonus';
UPDATE employee_bonuses SET type='performance'   WHERE type='incentive';
UPDATE employee_bonuses SET type='one_time'      WHERE type='commission';

-- 3) Lock the enum to the final reference set.
ALTER TABLE employee_bonuses
  MODIFY COLUMN type ENUM('monthly_bonus','performance','festival','overtime','one_time') NOT NULL;

-- 4) New columns.
ALTER TABLE employee_bonuses ADD COLUMN IF NOT EXISTS remarks TEXT NULL AFTER reason;
ALTER TABLE employee_bonuses ADD COLUMN IF NOT EXISTS added_by INT NULL AFTER payroll_year;

-- 5) Default new bonuses to "approved" (so they apply to the payslip), like the reference.
ALTER TABLE employee_bonuses
  MODIFY COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved';
