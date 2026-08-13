-- ─────────────────────────────────────────────────────────────────────────────
-- Seed: salary components (earnings breakup)
-- Run: mysql -u root -h 127.0.0.1 -P 3307 magdyn_hrms < install/seed_salary_components.sql
--
-- These five allowances split the monthly CTC. They total 100%, so no residual
-- "Special Allowance" is generated.
--
-- IMPORTANT: the component whose name contains "basic" is what PayrollCalculator
-- treats as the Basic salary — it drives PF (12% of Basic, capped 1,800),
-- overtime pay, and the late deduction. With no Basic component the engine falls
-- back to 40% of CTC, which silently changes PF, OT and late amounts.
--
-- Idempotent: each row is inserted only when a component of that name is absent,
-- so re-running never creates duplicates (the table has no UNIQUE key on name).
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO `salary_components` (name, type, calculation_type, value, sort_order)
SELECT 'Basic','allowance','percentage',55.0000,1
 WHERE NOT EXISTS (SELECT 1 FROM `salary_components` WHERE name='Basic');

INSERT INTO `salary_components` (name, type, calculation_type, value, sort_order)
SELECT 'HRA','allowance','percentage',25.0000,2
 WHERE NOT EXISTS (SELECT 1 FROM `salary_components` WHERE name='HRA');

INSERT INTO `salary_components` (name, type, calculation_type, value, sort_order)
SELECT 'Conveyance allowance','allowance','percentage',5.0000,3
 WHERE NOT EXISTS (SELECT 1 FROM `salary_components` WHERE name='Conveyance allowance');

INSERT INTO `salary_components` (name, type, calculation_type, value, sort_order)
SELECT 'Vehicle allowance','allowance','percentage',5.0000,4
 WHERE NOT EXISTS (SELECT 1 FROM `salary_components` WHERE name='Vehicle allowance');

INSERT INTO `salary_components` (name, type, calculation_type, value, sort_order)
SELECT 'Product Incentive','allowance','percentage',10.0000,5
 WHERE NOT EXISTS (SELECT 1 FROM `salary_components` WHERE name='Product Incentive');
