-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: rotational shifts — effective-dated shift assignments
-- Run once: mysql -u root -h 127.0.0.1 -P 3307 magdyn_hrms < install/add_shift_rotation.sql
--
-- employees.shift_id holds ONE shift, which cannot express "2 weeks Morning,
-- then 2 weeks General". This table records which shift an employee is on for a
-- DATE RANGE, so attendance marked (or imported, possibly back-dated) for any
-- day is judged by the shift that actually applied on that day.
--
-- Resolution order used by employee_shift_on() in includes/helpers.php:
--   1. the schedule row covering that date (latest start_date wins on overlap)
--   2. employees.shift_id            (the employee's default / non-rotating shift)
--   3. the General shift, then the legacy global settings
--
-- Employees with no schedule rows are completely unaffected.
-- Idempotent: safe to run multiple times.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `employee_shift_schedule` (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    shift_id    INT UNSIGNED NOT NULL,
    start_date  DATE NOT NULL,
    end_date    DATE NULL,                 -- NULL = open-ended (until superseded)
    note        VARCHAR(160) NULL,         -- e.g. "Rotation cycle 3"
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ess_emp_date (employee_id, start_date),
    KEY idx_ess_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
