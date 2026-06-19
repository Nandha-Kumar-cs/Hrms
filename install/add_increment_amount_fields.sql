-- ─────────────────────────────────────────────────────────────────────────────
-- Increments — store the computed increment amount & percentage as columns,
-- matching the reference Employee_Management project (employee_increments).
-- Idempotent.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE employee_increments
  ADD COLUMN IF NOT EXISTS increment_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER new_salary;

ALTER TABLE employee_increments
  ADD COLUMN IF NOT EXISTS increment_percentage DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER increment_amount;

-- Backfill from existing rows.
UPDATE employee_increments
   SET increment_amount = ROUND(new_salary - previous_salary, 2),
       increment_percentage = CASE WHEN previous_salary > 0
            THEN ROUND((new_salary - previous_salary) / previous_salary * 100, 2)
            ELSE 0 END
 WHERE increment_amount = 0 AND increment_percentage = 0;
