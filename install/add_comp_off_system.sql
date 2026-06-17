-- ─────────────────────────────────────────────────────────────────────────────
-- Comp-Off System migration  (ports the Employee_Management comp-off design)
-- ─────────────────────────────────────────────────────────────────────────────
-- Adds:
--   • holidays.entity_id, holidays.working_day_reason  (for working-day circulars)
--   • leave_types.is_comp_off + a seeded "Comp Off" leave type
--   • comp_offs              — holiday-driven comp-off grants (pending/availed/lapsed)
--   • comp_off_credits       — comp-off earned by working a non-working day
--   • comp_off_working_days  — days the company declared as working
-- Idempotent: safe to run more than once (MariaDB IF NOT EXISTS / INSERT … SELECT).
-- ─────────────────────────────────────────────────────────────────────────────

-- ── Holidays: working-day circular fields ────────────────────────────────────
ALTER TABLE `holidays`
    ADD COLUMN IF NOT EXISTS `entity_id` INT UNSIGNED NULL AFTER `holiday_type_id`;
ALTER TABLE `holidays`
    ADD COLUMN IF NOT EXISTS `working_day_reason` VARCHAR(500) NULL AFTER `is_working_day`;

-- ── Leave types: comp-off flag + seed ────────────────────────────────────────
ALTER TABLE `leave_types`
    ADD COLUMN IF NOT EXISTS `is_comp_off` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_paid`;

INSERT INTO `leave_types` (name, days_allowed, is_paid, carry_forward, is_comp_off, status, created_at)
SELECT 'Comp Off', 365, 1, 1, 1, 'active', NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `leave_types` WHERE is_comp_off = 1);

-- ── comp_offs (holiday-driven grants) ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `comp_offs` (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id  INT UNSIGNED NOT NULL,
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(150) NOT NULL,
    availed_date DATE NULL,
    status       ENUM('pending','availed','lapsed') NOT NULL DEFAULT 'pending',
    notes        VARCHAR(255) NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY comp_off_emp_holiday_unique (employee_id, holiday_date),
    KEY idx_comp_offs_date (holiday_date),
    KEY idx_comp_offs_status (status),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── comp_off_credits (earned by working a non-working day) ────────────────────
CREATE TABLE IF NOT EXISTS `comp_off_credits` (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id  INT UNSIGNED NOT NULL,
    work_date    DATE NOT NULL,
    day_type     ENUM('sunday','saturday','public_holiday') NOT NULL,
    holiday_name VARCHAR(150) NULL,
    status       ENUM('credited','cancelled') NOT NULL DEFAULT 'credited',
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY comp_off_credit_emp_date_unique (employee_id, work_date),
    KEY idx_credits_date (work_date),
    KEY idx_credits_status (status),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── comp_off_working_days (company-declared working days) ─────────────────────
CREATE TABLE IF NOT EXISTS `comp_off_working_days` (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_date    DATE NOT NULL UNIQUE,
    day_type     ENUM('sunday','saturday','public_holiday') NOT NULL,
    holiday_name VARCHAR(150) NULL,
    reason       VARCHAR(500) NULL,
    declared_by  INT UNSIGNED NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_working_days_date (work_date)
) ENGINE=InnoDB;
