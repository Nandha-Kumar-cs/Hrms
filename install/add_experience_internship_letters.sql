-- ─────────────────────────────────────────────────────────────────────────────
-- Experience Letter + Internship Certificate letter types.
--
-- Adds two new values to letters.type and their per-type sub-menu permissions,
-- following the same pattern as install/add_employee_submodule_perms.sql.
-- Idempotent — safe to re-run.
--
-- Backward compatibility:
--   • anyone with letters.view → granted letters.experience + letters.internship
-- ─────────────────────────────────────────────────────────────────────────────

-- ── Widen the letters.type ENUM ──────────────────────────────────────────────
ALTER TABLE letters
    MODIFY COLUMN type
    ENUM('Offer','Confirmation','Increment','Promotion','Experience','Internship')
    NOT NULL;

-- ── New per-type permissions ─────────────────────────────────────────────────
INSERT IGNORE INTO permissions (module, action, label) VALUES
('letters', 'experience', 'Experience Letters'),
('letters', 'internship', 'Internship Certificates');

-- ── Backfill ROLES that already hold letters.view ────────────────────────────
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id FROM role_permissions rp
  JOIN permissions sp ON sp.id = rp.permission_id AND sp.module='letters' AND sp.action='view'
  JOIN permissions np ON np.module='letters' AND np.action IN ('experience','internship');

-- ── Backfill direct USER permissions ─────────────────────────────────────────
INSERT IGNORE INTO user_permissions (user_id, permission_id)
SELECT up.user_id, np.id FROM user_permissions up
  JOIN permissions sp ON sp.id = up.permission_id AND sp.module='letters' AND sp.action='view'
  JOIN permissions np ON np.module='letters' AND np.action IN ('experience','internship');
