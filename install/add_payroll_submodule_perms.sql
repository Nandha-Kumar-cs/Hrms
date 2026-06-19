-- ─────────────────────────────────────────────────────────────────────────────
-- Sub-menu permissions for the Payroll group.
--
-- Splits the overloaded permissions so each Payroll submenu is independently
-- grantable:
--   Salary Calculation → payroll.calculate   (was payroll.view)
--   Salary Slips       → payroll.view         (unchanged)
--   Loans & Advances   → loans.view/create    (were employee.*)
--   Increments         → increments.view/create
--   Promotions         → promotions.view/create
--   Benefits           → benefits.view/create
--   Bonuses & Incentives → bonuses.view/create
--
-- Backward compatibility:
--   • payroll.view  → granted payroll.calculate
--   • employee.view → granted view+create on loans/increments/promotions/benefits/bonuses
--     (those pages previously required the employee permission, so this preserves access)
-- Idempotent.
-- ─────────────────────────────────────────────────────────────────────────────

-- payroll.view now specifically controls the Salary Slips submenu (Salary
-- Calculation moved to payroll.calculate), so relabel it to match.
UPDATE permissions SET label = 'Salary Slips' WHERE module = 'payroll' AND action = 'view';

INSERT IGNORE INTO permissions (module, action, label) VALUES
('payroll',    'calculate', 'Salary Calculation'),
('loans',      'view',   'View Loans & Advances'),
('loans',      'create', 'Manage Loans & Advances'),
('increments', 'view',   'View Increments'),
('increments', 'create', 'Manage Increments'),
('promotions', 'view',   'View Promotions'),
('promotions', 'create', 'Manage Promotions'),
('benefits',   'view',   'View Benefits'),
('benefits',   'create', 'Manage Benefits'),
('bonuses',    'view',   'View Bonuses & Incentives'),
('bonuses',    'create', 'Manage Bonuses & Incentives');

-- ── Backfill ROLES ───────────────────────────────────────────────────────────
-- payroll.view → payroll.calculate
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id FROM role_permissions rp
  JOIN permissions sp ON sp.id = rp.permission_id AND sp.module='payroll' AND sp.action='view'
  JOIN permissions np ON np.module='payroll' AND np.action='calculate';

-- employee.view → loans/increments/promotions/benefits/bonuses view+create
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id FROM role_permissions rp
  JOIN permissions sp ON sp.id = rp.permission_id AND sp.module='employee' AND sp.action='view'
  JOIN permissions np ON np.module IN ('loans','increments','promotions','benefits','bonuses')
                     AND np.action IN ('view','create');

-- ── Backfill direct USER permissions ─────────────────────────────────────────
INSERT IGNORE INTO user_permissions (user_id, permission_id)
SELECT up.user_id, np.id FROM user_permissions up
  JOIN permissions sp ON sp.id = up.permission_id AND sp.module='payroll' AND sp.action='view'
  JOIN permissions np ON np.module='payroll' AND np.action='calculate';

INSERT IGNORE INTO user_permissions (user_id, permission_id)
SELECT up.user_id, np.id FROM user_permissions up
  JOIN permissions sp ON sp.id = up.permission_id AND sp.module='employee' AND sp.action='view'
  JOIN permissions np ON np.module IN ('loans','increments','promotions','benefits','bonuses')
                     AND np.action IN ('view','create');
