-- ─────────────────────────────────────────────────────────────────────────────
-- Deployment / System Update module
--
-- Adds:
--   • deployments        — one row per deployment attempt (the history)
--   • deployment_files   — per-file record of what each deployment touched
--   • the `deployment` permission set, granted to Super Admin only
--
-- The audit trail itself reuses the existing activity_logs table via
-- activity_log(), which already records the user, IP address and timestamp.
--
-- Idempotent: safe to run more than once (IF NOT EXISTS / INSERT IGNORE).
-- ─────────────────────────────────────────────────────────────────────────────

-- ─── Deployment history ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS deployments (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deployment_id  VARCHAR(32)  NOT NULL,          -- DEP-YYYYMMDD-HHMMSS
    user_id        INT UNSIGNED NULL,
    user_name      VARCHAR(120) NULL,
    package_name   VARCHAR(255) NOT NULL,
    package_size   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    package_sha256 CHAR(64)     NULL,
    environment    VARCHAR(16)  NOT NULL DEFAULT 'LOCAL',
    total_files    INT UNSIGNED NOT NULL DEFAULT 0,
    files_added    INT UNSIGNED NOT NULL DEFAULT 0,
    files_updated  INT UNSIGNED NOT NULL DEFAULT 0,
    files_skipped  INT UNSIGNED NOT NULL DEFAULT 0,
    status         ENUM('PENDING','SUCCESS','FAILED','ROLLED_BACK') NOT NULL DEFAULT 'PENDING',
    backup_path    VARCHAR(255) NULL,              -- relative to the deploy storage dir
    db_backup_path VARCHAR(255) NULL,              -- set only when SQL was executed
    error_message  TEXT         NULL,
    rolled_back_at DATETIME     NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at   DATETIME     NULL,
    UNIQUE KEY uk_deployment_id (deployment_id),
    KEY idx_status (status),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Per-file detail ──────────────────────────────────────────────────────────
-- rel_path is the path INSIDE the HRMS root, always forward-slashed.
CREATE TABLE IF NOT EXISTS deployment_files (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deployment_id VARCHAR(32)  NOT NULL,
    rel_path      VARCHAR(512) NOT NULL,
    action        ENUM('ADD','UPDATE','SKIP','PROTECTED','SQL') NOT NULL,
    size_before   BIGINT UNSIGNED NULL,
    size_after    BIGINT UNSIGNED NULL,
    sha256_before CHAR(64)     NULL,
    sha256_after  CHAR(64)     NULL,
    status        VARCHAR(24)  NOT NULL DEFAULT 'PENDING',
    note          VARCHAR(255) NULL,
    KEY idx_dep (deployment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Permissions ──────────────────────────────────────────────────────────────
INSERT IGNORE INTO permissions (module, action, label) VALUES
('deployment', 'view',     'View System Update'),
('deployment', 'deploy',   'Upload & Deploy Packages'),
('deployment', 'rollback', 'Roll Back Deployments');

-- Super Admin by seed id (also covered by the code-level bypass).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions WHERE module = 'deployment';

-- …and by role NAME, because installs that created roles through the UI do not
-- use the seed ids. Deliberately Super Admin ONLY: this module writes PHP into
-- the application root, so it is not granted to Admin / HR / Manager by default.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  WHERE p.module = 'deployment' AND LOWER(r.name) = 'super admin';
