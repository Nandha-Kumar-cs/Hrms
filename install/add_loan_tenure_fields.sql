-- ─────────────────────────────────────────────────────────────────────────────
-- Loans & Advances — bring the schema to parity with the reference
-- Employee_Management project so the add/edit form can capture loan tenure and
-- compute interest / EMI the same way.
--
--   • total_months  — loan tenure in months (drives interest + EMI auto-calc)
--   • paid_months   — installments paid so far (counter)
--   • status        — gains a 'completed' state (fully repaid)
-- Idempotent.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE employee_loans
  ADD COLUMN IF NOT EXISTS total_months INT UNSIGNED NOT NULL DEFAULT 1 AFTER monthly_deduction;

ALTER TABLE employee_loans
  ADD COLUMN IF NOT EXISTS paid_months INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_months;

ALTER TABLE employee_loans
  MODIFY COLUMN status ENUM('active','closed','completed') NOT NULL DEFAULT 'active';
