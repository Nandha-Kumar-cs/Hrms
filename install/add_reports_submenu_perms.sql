-- ─────────────────────────────────────────────────────────────────────────────
-- Granular sub-menu permissions for the Reports group.
--
-- The Reports sidebar group was gated only by a role flag ($_sbPriv) and the
-- report pages piggy-backed on payroll.view / employee.view, so the four
-- reports could not be granted independently. This gives each its own module
-- with view + export actions:
--
--   Monthly Benefits  → report_benefits.view / .export   (was payroll.view)
--   Bonus Report      → report_bonus.view    / .export   (was payroll.view)
--   Employee History  → report_history.view  / .export   (was employee.view)
--   Payroll Impact    → report_impact.view   / .export   (was payroll.view)
--
-- Backward compatibility (so nobody loses access):
--   • payroll.view  → report_benefits/.bonus/.impact  (view + export)
--   • employee.view → report_history                  (view + export)
-- Idempotent.
-- ─────────────────────────────────────────────────────────────────────────────

INSERT IGNORE INTO permissions (module, action, label) VALUES
('report_benefits', 'view',   'View Monthly Benefits'),
('report_benefits', 'export', 'Export Monthly Benefits'),
('report_bonus',    'view',   'View Bonus Report'),
('report_bonus',    'export', 'Export Bonus Report'),
('report_history',  'view',   'View Employee History'),
('report_history',  'export', 'Export Employee History'),
('report_impact',   'view',   'View Payroll Impact'),
('report_impact',   'export', 'Export Payroll Impact');

-- ── Backfill ROLES ───────────────────────────────────────────────────────────
-- payroll.view → benefits / bonus / impact (view + export)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id FROM role_permissions rp
  JOIN permissions sp ON sp.id = rp.permission_id AND sp.module='payroll' AND sp.action='view'
  JOIN permissions np ON np.module IN ('report_benefits','report_bonus','report_impact')
                     AND np.action IN ('view','export');

-- employee.view → history (view + export)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id FROM role_permissions rp
  JOIN permissions sp ON sp.id = rp.permission_id AND sp.module='employee' AND sp.action='view'
  JOIN permissions np ON np.module='report_history' AND np.action IN ('view','export');

-- ── Backfill direct USER permissions ─────────────────────────────────────────
INSERT IGNORE INTO user_permissions (user_id, permission_id)
SELECT up.user_id, np.id FROM user_permissions up
  JOIN permissions sp ON sp.id = up.permission_id AND sp.module='payroll' AND sp.action='view'
  JOIN permissions np ON np.module IN ('report_benefits','report_bonus','report_impact')
                     AND np.action IN ('view','export');

INSERT IGNORE INTO user_permissions (user_id, permission_id)
SELECT up.user_id, np.id FROM user_permissions up
  JOIN permissions sp ON sp.id = up.permission_id AND sp.module='employee' AND sp.action='view'
  JOIN permissions np ON np.module='report_history' AND np.action IN ('view','export');
