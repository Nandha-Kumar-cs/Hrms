-- ─────────────────────────────────────────────────────────────────────────────
-- Sub-module permissions for Attendance.
--
-- Previously several Attendance pages shared the single `attendance.view`
-- permission, so granting "view" opened the hub, the report AND the calendar.
-- This splits them into independently-grantable sub-permissions:
--   attendance.view     → Attendance Records (hub)
--   attendance.report   → Attendance Report      (report.php)
--   attendance.calendar → Monthly Attendance     (calendar.php)
--   attendance.mark     → Mark Attendance        (unchanged)
--
-- Backward compatibility: every role/user that currently has `attendance.view`
-- is also granted the two new sub-permissions, so no existing access is lost.
-- Idempotent (INSERT IGNORE).
-- ─────────────────────────────────────────────────────────────────────────────

INSERT IGNORE INTO permissions (module, action, label) VALUES
('attendance', 'report',   'Attendance Report'),
('attendance', 'calendar', 'Monthly Attendance');

-- Clarify the existing label (the hub / records list).
UPDATE permissions SET label = 'Attendance Records' WHERE module='attendance' AND action='view';

-- Backfill ROLES that already have attendance.view.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id
  FROM role_permissions rp
  JOIN permissions vp ON vp.id = rp.permission_id AND vp.module='attendance' AND vp.action='view'
  JOIN permissions np ON np.module='attendance' AND np.action IN ('report','calendar');

-- Backfill direct USER permissions that already have attendance.view.
INSERT IGNORE INTO user_permissions (user_id, permission_id)
SELECT up.user_id, np.id
  FROM user_permissions up
  JOIN permissions vp ON vp.id = up.permission_id AND vp.module='attendance' AND vp.action='view'
  JOIN permissions np ON np.module='attendance' AND np.action IN ('report','calendar');
