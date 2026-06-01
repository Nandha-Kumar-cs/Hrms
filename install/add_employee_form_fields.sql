-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: Employee form feature parity with Laravel
-- Run once: mysql -u root -p magdyn_hrms < install/add_employee_form_fields.sql
-- ─────────────────────────────────────────────────────────────────────────────

-- ── 1. Entities table (company/branch master) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS entities (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL,
    address    TEXT,
    city       VARCHAR(80),
    state      VARCHAR(80),
    pincode    VARCHAR(10),
    phone      VARCHAR(20),
    email      VARCHAR(120),
    logo       VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. entity_id on employees ─────────────────────────────────────────────────
ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS entity_id INT UNSIGNED NULL
    REFERENCES entities(id) ON DELETE SET NULL
    AFTER id;

-- ── 3. Salary + probation columns on employees ────────────────────────────────
ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS fixed_salary    DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER photo,
    ADD COLUMN IF NOT EXISTS variable_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER fixed_salary,
    ADD COLUMN IF NOT EXISTS probation_end   DATE NULL AFTER join_date;

-- ── 4. Extend employees.status enum (add Inactive) ───────────────────────────
ALTER TABLE employees
    MODIFY COLUMN status
        ENUM('Active','Inactive','On Leave','Resigned','Terminated')
        DEFAULT 'Active';

-- ── 5. department_id on designations ─────────────────────────────────────────
ALTER TABLE designations
    ADD COLUMN IF NOT EXISTS department_id INT UNSIGNED NULL
    REFERENCES departments(id) ON DELETE SET NULL
    AFTER name;
