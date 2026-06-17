-- ─────────────────────────────────────────────────────────────────────────────
-- Leave Management module  (ports the Employee_Management leave system)
-- ─────────────────────────────────────────────────────────────────────────────
-- Adds:
--   • leave_requests  — apply / approve / reject leave (with optional document)
--   • leave_balances  — per employee / leave type / year quota tracking
--   • `leaves` permission set + role grants
-- Idempotent: safe to run more than once.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `leave_requests` (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id    INT UNSIGNED NOT NULL,
    leave_type_id  INT UNSIGNED NOT NULL,
    start_date     DATE NOT NULL,
    end_date       DATE NOT NULL,
    days_requested DECIMAL(5,1) NOT NULL DEFAULT 1,
    reason         TEXT NULL,
    document       VARCHAR(255) NULL,
    status         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    approved_by    INT UNSIGNED NULL,
    approved_at    DATETIME NULL,
    remarks        TEXT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_lr_emp (employee_id),
    KEY idx_lr_type (leave_type_id),
    KEY idx_lr_status (status),
    KEY idx_lr_start (start_date),
    FOREIGN KEY (employee_id)   REFERENCES employees(id)   ON DELETE CASCADE,
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `leave_balances` (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT UNSIGNED NOT NULL,
    leave_type_id INT UNSIGNED NOT NULL,
    year          SMALLINT UNSIGNED NOT NULL,
    total_days    DECIMAL(5,1) NOT NULL DEFAULT 0,
    used_days     DECIMAL(5,1) NOT NULL DEFAULT 0,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY lb_emp_type_year (employee_id, leave_type_id, year),
    FOREIGN KEY (employee_id)   REFERENCES employees(id)   ON DELETE CASCADE,
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Permissions ──────────────────────────────────────────────────────────────
INSERT IGNORE INTO permissions (module, action, label) VALUES
('leaves', 'view',   'View Leave Requests'),
('leaves', 'create', 'Create Leave Requests'),
('leaves', 'edit',   'Edit / Approve Leave Requests'),
('leaves', 'delete', 'Delete Leave Requests');

-- Super Admin (1) + HR Manager (2): full.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions WHERE module = 'leaves';
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE module = 'leaves';
-- HR Executive (3): view / create / edit.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions WHERE module = 'leaves' AND action IN ('view','create','edit');
-- Manager (5): view.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 5, id FROM permissions WHERE module = 'leaves' AND action = 'view';
-- Employee (6): view / create.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 6, id FROM permissions WHERE module = 'leaves' AND action IN ('view','create');
