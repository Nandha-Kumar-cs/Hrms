-- ─────────────────────────────────────────────────────────────────────────────
-- Remove the Attendance Calendar module.
--
-- The calendar page (modules/attendance/calendar.php) and its sidebar links were
-- removed; this drops the no-longer-used permission and any role/user grants so
-- it stops appearing in the Roles & Permissions UI. Idempotent.
-- ─────────────────────────────────────────────────────────────────────────────

DELETE rp FROM role_permissions rp
  JOIN permissions p ON p.id = rp.permission_id
 WHERE p.module = 'attendance' AND p.action = 'calendar';

DELETE up FROM user_permissions up
  JOIN permissions p ON p.id = up.permission_id
 WHERE p.module = 'attendance' AND p.action = 'calendar';

DELETE FROM permissions WHERE module = 'attendance' AND action = 'calendar';
