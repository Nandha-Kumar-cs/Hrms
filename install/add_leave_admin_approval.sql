-- ─────────────────────────────────────────────────────────────────────────────
-- Leave Request: separate "edit" and "approve" permissions + independent
-- admin approval status.
--
-- Previously `leaves.edit` meant "Edit / Approve" (combined) and the single
-- `status` column drove both the request lifecycle and the approval decision.
-- This splits the concerns:
--
--   • leaves.edit    → "Allow User to Edit Leave Request"   (edit fields only)
--   • leaves.approve → "Allow Admin to Approve Leave Request"(approve / reject)
--   • admin_approval_status (pending|approved|rejected) is the independent admin
--     decision field. The legacy `status` column is kept in sync with it so the
--     payroll / leave-balance / comp-off logic (which keys on status='approved')
--     keeps working unchanged.
--
-- Backward compatibility: every role/user that already had leaves.edit (which
-- implied approval) is granted leaves.approve so current admins keep that
-- ability. Only privileged roles hold leaves.edit today.
-- Idempotent.
-- ─────────────────────────────────────────────────────────────────────────────

-- 1) Independent admin-approval status field.
ALTER TABLE leave_requests
  ADD COLUMN IF NOT EXISTS admin_approval_status ENUM('pending','approved','rejected')
  NOT NULL DEFAULT 'pending' AFTER status;

-- Seed it from the existing approval state.
UPDATE leave_requests SET admin_approval_status = status WHERE admin_approval_status <> status;

-- 2) Relabel edit + add the separate approve permission.
UPDATE permissions SET label = 'Allow User to Edit Leave Request'
  WHERE module = 'leaves' AND action = 'edit';

INSERT IGNORE INTO permissions (module, action, label) VALUES
  ('leaves', 'approve', 'Allow Admin to Approve Leave Request');

-- 3) Backfill approve to whoever currently has edit (preserve approval ability).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id FROM role_permissions rp
  JOIN permissions sp ON sp.id = rp.permission_id AND sp.module='leaves' AND sp.action='edit'
  JOIN permissions np ON np.module='leaves' AND np.action='approve';

INSERT IGNORE INTO user_permissions (user_id, permission_id)
SELECT up.user_id, np.id FROM user_permissions up
  JOIN permissions sp ON sp.id = up.permission_id AND sp.module='leaves' AND sp.action='edit'
  JOIN permissions np ON np.module='leaves' AND np.action='approve';
