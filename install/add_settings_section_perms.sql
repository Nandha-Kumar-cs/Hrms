-- MagDyn HRMS — granular Settings permissions
-- Adds one permission per Settings sub-section so roles can be granted access to
-- specific settings areas (Entities, Departments, Office, Branding, ...) instead of
-- a single all-or-nothing settings flag. Idempotent (INSERT IGNORE).
--
-- Enforced by: modules/settings/index.php (per tab), office.php, branding.php,
-- and shown per-permission in the sidebar (includes/header.php).

INSERT IGNORE INTO permissions (module, action, label) VALUES
('settings', 'entities',           'Entities'),
('settings', 'departments',        'Departments'),
('settings', 'designations',       'Designations'),
('settings', 'leave_types',        'Leave Types'),
('settings', 'holiday_types',      'Holiday Types'),
('settings', 'benefit_fund_types', 'Benefit Fund Types'),
('settings', 'asset_categories',   'Asset Categories'),
('settings', 'office',             'Office Settings'),
('settings', 'branding',           'Branding');
