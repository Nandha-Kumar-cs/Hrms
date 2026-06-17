-- ─────────────────────────────────────────────────────────────────────────────
-- Settings module master-data tables & columns
-- Adds the tables/columns the Settings tabs (Departments, Designations,
-- Leave Types, Holiday Types, Benefit Fund Types, Entities) depend on.
-- Idempotent: safe to run more than once (MariaDB IF NOT EXISTS guards).
-- ─────────────────────────────────────────────────────────────────────────────

-- Departments: add status flag (active/inactive)
ALTER TABLE departments
    ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active';

-- Designations: add status flag (department_id already exists)
ALTER TABLE designations
    ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active';

-- Entities: richer company-profile fields used by the Entities settings form
ALTER TABLE entities
    ADD COLUMN IF NOT EXISTS website         VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS signatory_name  VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS signatory_title VARCHAR(255) NULL;

-- Leave Types
CREATE TABLE IF NOT EXISTS leave_types (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL UNIQUE,
    days_allowed  INT          NOT NULL DEFAULT 0,
    is_paid       TINYINT(1)   NOT NULL DEFAULT 1,
    carry_forward TINYINT(1)   NOT NULL DEFAULT 0,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO leave_types (name, days_allowed, is_paid, carry_forward, status) VALUES
('Casual Leave',  12, 1, 0, 'active'),
('Sick Leave',    10, 1, 0, 'active'),
('Earned Leave',  15, 1, 1, 'active'),
('Loss of Pay',    0, 0, 0, 'active');

-- Holiday Types
CREATE TABLE IF NOT EXISTS holiday_types (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(80) NOT NULL UNIQUE,
    color      VARCHAR(20) NOT NULL DEFAULT 'primary',
    created_at DATETIME    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO holiday_types (name, color) VALUES
('National', 'danger'),
('Optional', 'info'),
('Company',  'success');

-- Benefit Fund Types
CREATE TABLE IF NOT EXISTS benefit_fund_types (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)  NOT NULL UNIQUE,
    description VARCHAR(1000) NULL,
    color       VARCHAR(20)   NOT NULL DEFAULT 'primary',
    status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at  DATETIME      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO benefit_fund_types (name, description, color, status) VALUES
('Provident Fund',   'Employee provident fund contribution',          'primary', 'active'),
('Gratuity',         'Gratuity fund as per statutory norms',          'success', 'active'),
('Group Insurance',  'Group health / life insurance benefit',         'info',    'active');
