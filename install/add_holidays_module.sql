-- ─────────────────────────────────────────────────────────────────────────────
-- Holiday Management Module migration
-- Adds the holiday_type_id link, the `source` audit column and the
-- `holidays` permission set so the new modules/holidays/ CRUD screens work.
-- Idempotent: safe to run more than once (MariaDB IF NOT EXISTS / INSERT IGNORE).
-- ─────────────────────────────────────────────────────────────────────────────

-- Link a holiday to a managed Holiday Type (settings master).
-- The legacy `type` ENUM stays for backward compatibility with older reports.
ALTER TABLE `holidays`
    ADD COLUMN IF NOT EXISTS `holiday_type_id` INT UNSIGNED NULL AFTER `type`;

-- Track how a holiday was created (audit trail).
ALTER TABLE `holidays`
    ADD COLUMN IF NOT EXISTS `source` ENUM('manual','national','import') NOT NULL DEFAULT 'manual'
    AFTER `is_working_day`;

ALTER TABLE `holidays`
    ADD INDEX IF NOT EXISTS `idx_holidays_type` (`holiday_type_id`);

-- Backfill the type link from the legacy ENUM by matching the type name.
UPDATE `holidays` h
    JOIN `holiday_types` t ON t.name = h.type
    SET h.holiday_type_id = t.id
    WHERE h.holiday_type_id IS NULL;

-- ─── Permissions ──────────────────────────────────────────────────────────────
INSERT IGNORE INTO permissions (module, action, label) VALUES
('holidays', 'view',   'View Holidays'),
('holidays', 'create', 'Create Holidays'),
('holidays', 'edit',   'Edit Holidays'),
('holidays', 'delete', 'Delete Holidays');

-- Super Admin (role 1): every holiday permission (also covered by code-level bypass).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions WHERE module = 'holidays';

-- HR Manager (role 2): full holiday management.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE module = 'holidays';

-- HR Executive (role 3): view / create / edit (no delete).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions WHERE module = 'holidays' AND action IN ('view','create','edit');

-- Manager (role 5): view only.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 5, id FROM permissions WHERE module = 'holidays' AND action = 'view';

-- Employee (role 6): view only (read the company holiday list).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 6, id FROM permissions WHERE module = 'holidays' AND action = 'view';
