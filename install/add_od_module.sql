-- ─────────────────────────────────────────────────────────────────────────────
-- On Duty (OD) module migration
-- Brings the existing `od_requests` table up to the schema the OD screen needs
-- (date range + place + reviewer) and registers the `od` permission set.
-- Idempotent & non-destructive: ADD COLUMN IF NOT EXISTS + INSERT IGNORE.
-- The attendance 'OD' status and att_date column already exist — no change there.
-- ─────────────────────────────────────────────────────────────────────────────

-- ── od_requests: add the working columns alongside the legacy ones ───────────
ALTER TABLE `od_requests`
    ADD COLUMN IF NOT EXISTS `from_date`   DATE        NULL AFTER `employee_id`,
    ADD COLUMN IF NOT EXISTS `to_date`     DATE        NULL AFTER `from_date`,
    ADD COLUMN IF NOT EXISTS `place`       VARCHAR(200) NULL AFTER `to_date`,
    ADD COLUMN IF NOT EXISTS `reason`      TEXT        NULL AFTER `place`,
    ADD COLUMN IF NOT EXISTS `requested_at` DATETIME   NULL DEFAULT CURRENT_TIMESTAMP AFTER `status`,
    ADD COLUMN IF NOT EXISTS `reviewed_by` INT UNSIGNED NULL AFTER `requested_at`,
    ADD COLUMN IF NOT EXISTS `reviewed_at` DATETIME    NULL AFTER `reviewed_by`;

-- Backfill the new columns from the legacy ones for any pre-existing rows.
UPDATE `od_requests`
   SET from_date    = COALESCE(from_date, od_date),
       to_date      = COALESCE(to_date, od_date),
       place        = COALESCE(place, location),
       reason       = COALESCE(reason, purpose),
       reviewed_by  = COALESCE(reviewed_by, approved_by),
       requested_at = COALESCE(requested_at, created_at)
 WHERE from_date IS NULL OR to_date IS NULL;

ALTER TABLE `od_requests`
    ADD INDEX IF NOT EXISTS `idx_od_status` (`status`),
    ADD INDEX IF NOT EXISTS `idx_od_emp` (`employee_id`);

-- ── Permissions ──────────────────────────────────────────────────────────────
INSERT IGNORE INTO permissions (module, action, label) VALUES
('od', 'view',   'View OD Requests'),
('od', 'create', 'Create OD Requests'),
('od', 'edit',   'Approve / Reject OD Requests'),
('od', 'delete', 'Delete OD Requests');

-- Super Admin (1) & HR Manager (2): everything.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions WHERE module = 'od';
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE module = 'od';

-- HR Executive (3): view / create / edit (no delete).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions WHERE module = 'od' AND action IN ('view','create','edit');

-- Manager (5): view + approve/reject their team.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 5, id FROM permissions WHERE module = 'od' AND action IN ('view','edit');

-- Employee (6): view own + create own requests (self-service).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 6, id FROM permissions WHERE module = 'od' AND action IN ('view','create');
