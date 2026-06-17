-- ─────────────────────────────────────────────────────────────────────────────
-- Activity Log module
-- Adds the activity_logs audit table + the 'activity' (view) permission.
-- Idempotent: CREATE TABLE IF NOT EXISTS / INSERT IGNORE.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS activity_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,                       -- who performed the action (users.id); NULL/system for none
    user_name   VARCHAR(120) NOT NULL DEFAULT 'System',  -- preserved even if the user is later deleted
    action      VARCHAR(60)  NOT NULL,                   -- created / updated / deleted / approved / login / ...
    module      VARCHAR(60)  NOT NULL,                   -- Employee / Holiday / Bonus / Auth / ...
    description TEXT NULL,                                -- plain text, OR JSON {summary, changes:[{field,from,to}]}
    ip_address  VARCHAR(45)  NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_al_module  (module, created_at),
    KEY idx_al_user    (user_id),
    KEY idx_al_action  (action),
    KEY idx_al_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permission (admin-only audit screen). Super Admin also bypasses in code.
INSERT IGNORE INTO permissions (module, action, label) VALUES
('activity', 'view', 'View Activity Log');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions WHERE module = 'activity';
