-- ─────────────────────────────────────────────────────────────────────────────
-- Sub-menu permissions for the Employees group.
--
-- Splits the shared letters.view (Offer/Confirmation/Increment/Promotion all used
-- one permission) into per-type sub-permissions, and gives Documents its own
-- permission (it previously rode on the employee permission). Idempotent.
--
-- Backward compatibility:
--   • anyone with letters.view  → granted all four letter-type permissions
--   • anyone with employee.view → granted documents.view + documents.create
--   • anyone with employee.edit → granted documents.delete
-- ─────────────────────────────────────────────────────────────────────────────

INSERT IGNORE INTO permissions (module, action, label) VALUES
('letters',   'offer',        'Offer Letters'),
('letters',   'confirmation', 'Confirmation Letters'),
('letters',   'increment',    'Increment Letters'),
('letters',   'promotion',    'Promotion Letters'),
('documents', 'view',         'View Documents'),
('documents', 'create',       'Upload Documents'),
('documents', 'delete',       'Delete Documents');

-- ── Backfill ROLES ───────────────────────────────────────────────────────────
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id FROM role_permissions rp
  JOIN permissions sp ON sp.id = rp.permission_id AND sp.module='letters' AND sp.action='view'
  JOIN permissions np ON np.module='letters' AND np.action IN ('offer','confirmation','increment','promotion');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id FROM role_permissions rp
  JOIN permissions sp ON sp.id = rp.permission_id AND sp.module='employee' AND sp.action='view'
  JOIN permissions np ON np.module='documents' AND np.action IN ('view','create');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id FROM role_permissions rp
  JOIN permissions sp ON sp.id = rp.permission_id AND sp.module='employee' AND sp.action='edit'
  JOIN permissions np ON np.module='documents' AND np.action='delete';

-- ── Backfill direct USER permissions ─────────────────────────────────────────
INSERT IGNORE INTO user_permissions (user_id, permission_id)
SELECT up.user_id, np.id FROM user_permissions up
  JOIN permissions sp ON sp.id = up.permission_id AND sp.module='letters' AND sp.action='view'
  JOIN permissions np ON np.module='letters' AND np.action IN ('offer','confirmation','increment','promotion');

INSERT IGNORE INTO user_permissions (user_id, permission_id)
SELECT up.user_id, np.id FROM user_permissions up
  JOIN permissions sp ON sp.id = up.permission_id AND sp.module='employee' AND sp.action='view'
  JOIN permissions np ON np.module='documents' AND np.action IN ('view','create');
