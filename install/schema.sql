-- MagDyn HRMS — Database Schema
-- Run this once: mysql -u root -p < install/schema.sql
-- Compatible with MySQL 5.7+ / MariaDB 10.2+

CREATE DATABASE IF NOT EXISTS magdyn_hrms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE magdyn_hrms;

-- ─── Roles ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS roles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80) NOT NULL UNIQUE,
    description TEXT,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO roles (name, description) VALUES
('Super Admin', 'Full system access'),
('HR Manager', 'Manage employees, payroll, letters, attendance'),
('HR Executive', 'Day-to-day HR operations'),
('Finance', 'Payroll and salary slip access'),
('Manager', 'View team attendance and approve leave'),
('Employee', 'Self-service: own profile, payslips, attendance');

-- ─── Permissions ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS permissions (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module  VARCHAR(60) NOT NULL,
    action  VARCHAR(40) NOT NULL,
    label   VARCHAR(120),
    UNIQUE KEY uniq_mod_act (module, action)
) ENGINE=InnoDB;

INSERT INTO permissions (module, action, label) VALUES
('dashboard',  'view',   'View Dashboard'),
('employee',   'view',   'View Employees'),
('employee',   'create', 'Create Employee'),
('employee',   'edit',   'Edit Employee'),
('employee',   'delete', 'Delete Employee'),
('attendance', 'view',   'View Attendance'),
('attendance', 'mark',   'Mark Attendance'),
('attendance', 'edit',   'Edit Attendance'),
('attendance', 'export', 'Export Attendance'),
('payroll',    'view',   'View Payroll'),
('payroll',    'process','Process Payroll'),
('payroll',    'export', 'Export Payroll'),
('letters',    'view',   'View Letters'),
('letters',    'create', 'Create Letters'),
('letters',    'delete', 'Delete Letters'),
('assets',     'view',   'View Assets'),
('assets',     'assign', 'Assign Assets'),
('assets',     'return', 'Return Assets'),
('training',   'view',   'View Training'),
('training',   'manage', 'Manage Training'),
('training',   'enroll', 'Enroll in Training'),
('roles',      'view',   'View Roles & Permissions'),
('roles',      'manage', 'Manage Roles & Permissions'),
('pwa',        'view',   'View PWA Settings'),
('pwa',        'manage', 'Manage PWA Settings'),
('settings',   'view',   'View Settings'),
('settings',   'manage', 'Manage Settings'),
('sso',        'view',   'View SSO Settings'),
('sso',        'manage', 'Manage SSO Settings');

-- ─── Role-Permission mapping ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id)       REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Super Admin: all permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions;

-- HR Manager
INSERT INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE module IN ('dashboard','employee','attendance','payroll','letters','assets','training') OR (module='roles' AND action='view');

-- HR Executive
INSERT INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions WHERE module IN ('dashboard','employee','attendance','letters') AND action IN ('view','create','edit');

-- Finance
INSERT INTO role_permissions (role_id, permission_id)
SELECT 4, id FROM permissions WHERE module IN ('dashboard','payroll');

-- Manager
INSERT INTO role_permissions (role_id, permission_id)
SELECT 5, id FROM permissions WHERE (module='dashboard' AND action='view') OR (module='attendance' AND action='view') OR (module='employee' AND action='view');

-- Employee: self-service only
INSERT INTO role_permissions (role_id, permission_id)
SELECT 6, id FROM permissions WHERE (module='dashboard' AND action='view') OR (module='attendance' AND action='view') OR (module='payroll' AND action='view') OR (module='training' AND action IN ('view','enroll'));

-- ─── Users ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(180) NOT NULL UNIQUE,
    name          VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255),
    role_id       INT UNSIGNED NOT NULL DEFAULT 6,
    employee_id   INT UNSIGNED NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    sso_uid       VARCHAR(180) NULL,
    avatar        VARCHAR(255) NULL,
    last_login    DATETIME NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- Default super admin placeholder row (no password yet).
-- IMPORTANT: After importing this schema, run install/reset_admin.php
-- (via browser or CLI: php install/reset_admin.php) to generate a valid
-- bcrypt hash and activate the admin account.
INSERT IGNORE INTO users (email, name, password_hash, role_id, is_active) VALUES
('admin@hrms.local', 'System Admin', 'PLACEHOLDER_RUN_RESET_ADMIN_PHP', 1, 0);

-- ─── Departments ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS departments (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    head_id    INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO departments (name) VALUES
('Administration'),('Human Resources'),('Finance'),('Information Technology'),
('Operations'),('Sales'),('Marketing'),('Legal');

-- ─── Designations ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS designations (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

INSERT INTO designations (name) VALUES
('CEO'),('CTO'),('CFO'),('HR Manager'),('HR Executive'),('Software Engineer'),
('Senior Software Engineer'),('Tech Lead'),('Project Manager'),('Business Analyst'),
('Finance Manager'),('Accountant'),('Sales Executive'),('Marketing Executive'),('Legal Counsel');

-- ─── Employees ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS employees (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id     VARCHAR(20)  NOT NULL UNIQUE,
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(180) NOT NULL UNIQUE,
    phone           VARCHAR(20),
    dob             DATE,
    gender          ENUM('Male','Female','Other','Prefer not to say'),
    address         TEXT,
    city            VARCHAR(80),
    state           VARCHAR(80),
    pincode         VARCHAR(10),
    department_id   INT UNSIGNED,
    designation_id  INT UNSIGNED,
    manager_id      INT UNSIGNED NULL,
    join_date       DATE,
    employment_type ENUM('Full Time','Part Time','Contract','Intern') DEFAULT 'Full Time',
    status          ENUM('Active','On Leave','Resigned','Terminated') DEFAULT 'Active',
    bank_name       VARCHAR(100),
    bank_account    VARCHAR(40),
    bank_ifsc       VARCHAR(20),
    pan_number      VARCHAR(15),
    aadhaar_number  VARCHAR(15),
    uan_number      VARCHAR(15),
    esic_number     VARCHAR(20),
    emergency_name  VARCHAR(120),
    emergency_phone VARCHAR(20),
    photo           VARCHAR(255),
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id)  REFERENCES departments(id),
    FOREIGN KEY (designation_id) REFERENCES designations(id),
    FOREIGN KEY (manager_id)     REFERENCES employees(id)
) ENGINE=InnoDB;

-- ─── Salary Structures ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS salary_structures (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id     INT UNSIGNED NOT NULL,
    effective_from  DATE NOT NULL,
    basic           DECIMAL(12,2) DEFAULT 0,
    hra             DECIMAL(12,2) DEFAULT 0,
    conveyance      DECIMAL(12,2) DEFAULT 0,
    medical         DECIMAL(12,2) DEFAULT 0,
    special_allow   DECIMAL(12,2) DEFAULT 0,
    other_allow     DECIMAL(12,2) DEFAULT 0,
    gross           DECIMAL(12,2) GENERATED ALWAYS AS (basic+hra+conveyance+medical+special_allow+other_allow) STORED,
    lop_per_day     DECIMAL(10,4) GENERATED ALWAYS AS ((basic+hra+conveyance+medical+special_allow+other_allow)/26) STORED,
    is_current      TINYINT(1) DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB;

-- ─── Attendance ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS attendance (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    att_date    DATE NOT NULL,
    status      ENUM('On Time','Late','Absent','OD','Comp Off','Half Day','Holiday') NOT NULL,
    in_time     TIME NULL,
    out_time    TIME NULL,
    remarks     VARCHAR(255),
    marked_by   INT UNSIGNED NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_emp_date (employee_id, att_date),
    FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB;

-- ─── Comp-Off Requests ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS comp_off_requests (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    worked_date DATE NOT NULL,
    comp_date   DATE NULL,
    reason      TEXT,
    status      ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    approved_by INT UNSIGNED NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB;

-- ─── OD Requests ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS od_requests (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    od_date     DATE NOT NULL,
    purpose     TEXT,
    location    VARCHAR(200),
    status      ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    approved_by INT UNSIGNED NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB;

-- ─── Payroll Runs ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payroll_runs (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_month CHAR(7) NOT NULL,  -- YYYY-MM
    status       ENUM('Draft','Processed','Finalized') DEFAULT 'Draft',
    processed_by INT UNSIGNED NULL,
    processed_at DATETIME NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_month (payroll_month)
) ENGINE=InnoDB;

-- ─── Application Settings ─────────────────────────────────────────────────────
-- Key/value store for runtime-configurable settings (OT timing, grace periods).
-- Mirrors Laravel `settings` table; `key` is reserved so columns are prefixed.
CREATE TABLE IF NOT EXISTS app_settings (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(100) NOT NULL UNIQUE,
    setting_value VARCHAR(255) NULL,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value) VALUES
 ('office_start_time',     '09:00'),
 ('ot_trigger_time',       '20:30'),
 ('ot_baseline_time',      '18:15'),
 ('daily_grace_minutes',   '15'),
 ('monthly_grace_minutes', '90')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- ─── Salary Components ────────────────────────────────────────────────────────
-- Dynamic allowance/deduction components used in individual payroll calculation.
CREATE TABLE IF NOT EXISTS salary_components (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(255) NOT NULL,
    type             ENUM('allowance','deduction') NOT NULL,
    calculation_type ENUM('percentage','fixed') NOT NULL,
    value            DECIMAL(10,4) NOT NULL DEFAULT 0,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Salary Slips ─────────────────────────────────────────────────────────────
-- payroll_run_id is nullable — individual slips (slip_type='individual') have no run.
-- JSON columns store dynamic allowances/deductions for individual slips.
-- Fixed columns (basic, hra, etc.) are populated for all slips for backwards compat.
CREATE TABLE IF NOT EXISTS salary_slips (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_run_id   INT UNSIGNED NULL,                      -- NULL for individual slips
    employee_id      INT UNSIGNED NOT NULL,
    payroll_month    CHAR(7) NOT NULL,
    fixed_salary     DECIMAL(12,2) DEFAULT 0,               -- effective monthly CTC
    variable_salary  DECIMAL(12,2) DEFAULT 0,               -- variable pay portion
    allowances       JSON NULL,                             -- dynamic earnings (individual)
    deductions_json  JSON NULL,                             -- dynamic deductions (individual)
    attendance_summary JSON NULL,                           -- full attendance breakdown
    working_days     INT DEFAULT 26,
    present_days     INT DEFAULT 0,
    lop_days         INT DEFAULT 0,
    basic            DECIMAL(12,2) DEFAULT 0,
    hra              DECIMAL(12,2) DEFAULT 0,
    conveyance       DECIMAL(12,2) DEFAULT 0,
    medical          DECIMAL(12,2) DEFAULT 0,
    special_allow    DECIMAL(12,2) DEFAULT 0,
    other_allow      DECIMAL(12,2) DEFAULT 0,
    gross_earnings   DECIMAL(12,2) DEFAULT 0,
    pf_employee      DECIMAL(12,2) DEFAULT 0,
    pf_employer      DECIMAL(12,2) DEFAULT 0,
    esi_employee     DECIMAL(12,2) DEFAULT 0,
    esi_employer     DECIMAL(12,2) DEFAULT 0,
    tds              DECIMAL(12,2) DEFAULT 0,
    other_deductions DECIMAL(12,2) DEFAULT 0,
    total_deductions DECIMAL(12,2) DEFAULT 0,
    net_pay          DECIMAL(12,2) DEFAULT 0,
    slip_pdf         VARCHAR(255) NULL,
    status           ENUM('Draft','Generated','Sent') DEFAULT 'Draft',
    slip_type        ENUM('batch','individual') NOT NULL DEFAULT 'batch',
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_emp_payroll_month (employee_id, payroll_month),
    CONSTRAINT fk_slip_payroll_run FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(id) ON DELETE SET NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Letters ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS letters (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    type        ENUM('Offer','Confirmation','Increment','Promotion') NOT NULL,
    issued_date DATE NOT NULL,
    reference   VARCHAR(60),
    content     LONGTEXT,
    pdf_path    VARCHAR(255),
    issued_by   INT UNSIGNED NULL,
    status      ENUM('Draft','Issued','Acknowledged') DEFAULT 'Draft',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB;

-- ─── Asset Categories ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS asset_categories (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL
) ENGINE=InnoDB;

INSERT INTO asset_categories (name) VALUES
('Laptop'),('Desktop'),('Mobile'),('Monitor'),('Keyboard'),('Mouse'),
('Headset'),('ID Card'),('Access Card'),('Vehicle'),('Other');

-- ─── Assets ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS assets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_code  VARCHAR(40) NOT NULL UNIQUE,
    name        VARCHAR(120) NOT NULL,
    category_id INT UNSIGNED,
    brand       VARCHAR(80),
    model       VARCHAR(80),
    serial_no   VARCHAR(100),
    purchase_date DATE,
    purchase_cost DECIMAL(12,2),
    `condition` ENUM('New','Good','Fair','Poor') DEFAULT 'Good',
    status      ENUM('Available','Assigned','Under Repair','Retired') DEFAULT 'Available',
    notes       TEXT,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES asset_categories(id)
) ENGINE=InnoDB;

-- ─── Asset Assignments ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS asset_assignments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id    INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    assigned_date DATE NOT NULL,
    returned_date DATE NULL,
    assigned_by INT UNSIGNED NULL,
    condition_at_assign ENUM('New','Good','Fair','Poor') DEFAULT 'Good',
    condition_at_return ENUM('New','Good','Fair','Poor') NULL,
    notes       TEXT,
    is_returned TINYINT(1) DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id)    REFERENCES assets(id),
    FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB;

-- ─── Training Courses ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS training_courses (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title         VARCHAR(200) NOT NULL,
    description   TEXT,
    category      VARCHAR(80),
    training_type VARCHAR(80)  NULL,
    trainer_name  VARCHAR(120) NULL,
    duration_hrs  INT DEFAULT 1,
    is_mandatory  TINYINT(1) DEFAULT 0,
    status        ENUM('Draft','Active','Completed','Archived') NOT NULL DEFAULT 'Active',
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── Training Access (role-based) ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS training_course_roles (
    course_id INT UNSIGNED NOT NULL,
    role_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (course_id, role_id),
    FOREIGN KEY (course_id) REFERENCES training_courses(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id)   REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Training Enrollments ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS training_enrollments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id   INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status      ENUM('Enrolled','In Progress','Completed','Failed') DEFAULT 'Enrolled',
    score       DECIMAL(5,2) NULL,
    completed_at DATETIME NULL,
    UNIQUE KEY uk_course_emp (course_id, employee_id),
    FOREIGN KEY (course_id)   REFERENCES training_courses(id),
    FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB;

-- ─── PWA Module Access ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pwa_module_access (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module    VARCHAR(60) NOT NULL UNIQUE,
    label     VARCHAR(100),
    is_enabled TINYINT(1) DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO pwa_module_access (module, label, is_enabled) VALUES
('attendance', 'Attendance',  1),
('payroll',    'Salary Slip', 1),
('letters',    'My Letters',  0),
('training',   'Training',    1),
('assets',     'My Assets',   0);

-- ─── Notifications ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    title      VARCHAR(200) NOT NULL,
    body       TEXT,
    type       VARCHAR(30) DEFAULT 'info',
    is_read    TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── User Notification Preferences ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_notification_prefs (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           INT UNSIGNED NOT NULL,
    notification_type VARCHAR(60) NOT NULL,
    enabled           TINYINT(1) DEFAULT 1,
    UNIQUE KEY uk_user_type (user_id, notification_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Push Subscriptions ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    endpoint   TEXT NOT NULL,
    p256dh     TEXT,
    auth_key   TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Holidays ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS holidays (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name    VARCHAR(120) NOT NULL,
    h_date  DATE NOT NULL UNIQUE,
    type    ENUM('National','Optional','Company') DEFAULT 'National'
) ENGINE=InnoDB;

-- Sample holidays
INSERT INTO holidays (name, h_date, type) VALUES
('New Year', '2026-01-01', 'National'),
('Republic Day', '2026-01-26', 'National'),
('Holi', '2026-03-29', 'National'),
('Good Friday', '2026-04-03', 'National'),
('Independence Day', '2026-08-15', 'National'),
('Gandhi Jayanti', '2026-10-02', 'National'),
('Diwali', '2026-10-19', 'National'),
('Christmas', '2026-12-25', 'National');
