-- ─────────────────────────────────────────────────────────────────────────────
-- Employee Benefits — bring to parity with the reference Employee_Management
-- project: fund-type reference, custom benefit name, and recurring scheduling
-- (frequency + start/end date). The legacy free-text `fund_type` column is kept
-- and populated with the fund-type name so existing slips/reports keep working.
-- Idempotent.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE employee_benefits
  ADD COLUMN IF NOT EXISTS benefit_fund_type_id INT NULL AFTER employee_id;

ALTER TABLE employee_benefits
  ADD COLUMN IF NOT EXISTS frequency ENUM('weekly','fortnightly','monthly','quarterly','half_yearly','annual')
      NOT NULL DEFAULT 'monthly' AFTER benefit_fund_type_id;

ALTER TABLE employee_benefits
  ADD COLUMN IF NOT EXISTS start_date DATE NULL AFTER frequency;

ALTER TABLE employee_benefits
  ADD COLUMN IF NOT EXISTS end_date DATE NULL AFTER start_date;

ALTER TABLE employee_benefits
  ADD COLUMN IF NOT EXISTS benefit_name VARCHAR(255) NULL AFTER end_date;

ALTER TABLE employee_benefits
  ADD COLUMN IF NOT EXISTS added_by INT NULL AFTER status;

ALTER TABLE employee_benefits
  ADD INDEX IF NOT EXISTS idx_eb_fund_type (benefit_fund_type_id);

-- Best-effort backfill: link existing free-text fund_type to a fund-type row by name.
UPDATE employee_benefits eb
  JOIN benefit_fund_types t
    ON LOWER(TRIM(t.name)) COLLATE utf8mb4_general_ci = LOWER(TRIM(eb.fund_type)) COLLATE utf8mb4_general_ci
   SET eb.benefit_fund_type_id = t.id
 WHERE eb.benefit_fund_type_id IS NULL;
