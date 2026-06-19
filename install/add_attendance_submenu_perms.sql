-- ─────────────────────────────────────────────────────────────────────────────
-- Granular sub-menu permissions for the Attendance group.
--
-- Most Attendance submenus already have their own permission (attendance.mark,
-- attendance.report, attendance.calendar, leaves.*, holidays.*, od.*).
-- Three submenus were still piggy-backing on a shared permission and could not
-- be granted independently — this splits them out:
--
--   Leave History     → leave_history.view        (was leaves.view)
--   Comp Offs         → compoff.view / .edit       (was attendance.view)
--   Comp Off Credits  → compoff_credits.view/.edit (was attendance.view)
--
-- Backward compatibility (so nobody loses access):
--   • leaves.view     → leave_history.view
--   • attendance.view → compoff.view, compoff_credits.view
--   • attendance.edit → compoff.edit, compoff_credits.edit
--   • holidays.edit   → compoff.edit, compoff_credits.edit  (legacy comp-off editors)
-- Idempotent.
-- ─────────────────────────────────────────────────────────────────────────────

INSERT IGNORE INTO permissions (module, action, label) VALUES
('leave_history',    'view', 'View Leave History'),
('compoff',          'view', 'View Comp Offs'),
('compoff',          'edit', 'Manage Comp Offs'),
('compoff_credits',  'view', 'View Comp Off Credits'),
('compoff_credits',  'edit', 'Manage Comp Off Credits');

-- ── Backfill ROLES ───────────────────────────────────────────────────────────
-- leaves.view → leave_history.view
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id FROM role_permissions rp
  JOIN permissions sp ON sp.id = rp.permission_id AND sp.module='leaves' AND sp.action='view'
  JOIN permissions np ON np.module='leave_history' AND np.action='view';

-- attendance.view → compoff.view, compoff_credits.view
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id FROM role_permissions rp
  JOIN permissions sp ON sp.id = rp.permission_id AND sp.module='attendance' AND sp.action='view'
  JOIN permissions np ON np.module IN ('compoff','compoff_credits') AND np.action='view';

-- attendance.edit OR holidays.edit → compoff.edit, compoff_credits.edit
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id FROM role_permissions rp
  JOIN permissions sp ON sp.id = rp.permission_id
       AND ((sp.module='attendance' AND sp.action='edit') OR (sp.module='holidays' AND sp.action='edit'))
  JOIN permissions np ON np.module IN ('compoff','compoff_credits') AND np.action='edit';

-- ── Backfill direct USER permissions ─────────────────────────────────────────
INSERT IGNORE INTO user_permissions (user_id, permission_id)
SELECT up.user_id, np.id FROM user_permissions up
  JOIN permissions sp ON sp.id = up.permission_id AND sp.module='leaves' AND sp.action='view'
  JOIN permissions np ON np.module='leave_history' AND np.action='view';

INSERT IGNORE INTO user_permissions (user_id, permission_id)
SELECT up.user_id, np.id FROM user_permissions up
  JOIN permissions sp ON sp.id = up.permission_id AND sp.module='attendance' AND sp.action='view'
  JOIN permissions np ON np.module IN ('compoff','compoff_credits') AND np.action='view';

INSERT IGNORE INTO user_permissions (user_id, permission_id)
SELECT up.user_id, np.id FROM user_permissions up
  JOIN permissions sp ON sp.id = up.permission_id
       AND ((sp.module='attendance' AND sp.action='edit') OR (sp.module='holidays' AND sp.action='edit'))
  JOIN permissions np ON np.module IN ('compoff','compoff_credits') AND np.action='edit';
