-- ─────────────────────────────────────────────────────────────────────────────
-- User Management — per-user permission grants (on top of role permissions).
-- Adds user_permissions and the `users` permission set. Idempotent.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS user_permissions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_perm (user_id, permission_id),
    KEY idx_up_user (user_id),
    FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permission set for the Users admin screen.
INSERT IGNORE INTO permissions (module, action, label) VALUES
('users', 'view',   'View Users'),
('users', 'create', 'Create Users'),
('users', 'edit',   'Edit Users'),
('users', 'delete', 'Delete Users');

-- Grant the user-management permissions to Super Admin (role 1).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions WHERE module = 'users';
