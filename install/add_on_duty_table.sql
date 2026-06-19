-- ─────────────────────────────────────────────────────────────────────────────
-- On Duty (OD) — assign model (ported from Employee_Management `on_duties`)
-- Admin/HR assigns OD to one or many employees for a single date; attendance is
-- auto-marked 'OD'. Replaces the earlier request/approval od_requests workflow.
-- Idempotent.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS on_duties (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    od_date     DATE         NOT NULL,
    reason      VARCHAR(255) NULL,
    created_by  INT UNSIGNED NULL,                       -- user who assigned it
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_emp_date (employee_id, od_date),     -- one OD per employee per day
    KEY idx_od_date (od_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The assign model is an HR/admin tool (Laravel: admin.auth), not employee
-- self-service. Drop the Employee role's od view/create grants from the old
-- request-workflow so employees can't assign OD to others.
DELETE rp FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE rp.role_id = 6 AND p.module = 'od';
