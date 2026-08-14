-- ─────────────────────────────────────────────────────────────────────────────
-- Employee ID Card / QR Secure-Access module
--
-- Adds:
--   • employee_qr_tokens        — one unguessable token + password hash per employee
--   • employee_portal_access_log — audit trail for every scan / login attempt
--   • the `idcard` permission set
--
-- The QR code carries ONLY the token (a random 64-hex string). No salary,
-- attendance or personal data is ever encoded into the QR image.
--
-- The portal password is derived from "first 4 letters of the name + DDMM of the
-- DOB" but is NEVER stored in plain text — only a password_hash() bcrypt digest
-- lives in this table.
--
-- Idempotent: safe to run more than once (MariaDB IF NOT EXISTS / INSERT IGNORE).
-- ─────────────────────────────────────────────────────────────────────────────

-- ─── QR tokens ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS employee_qr_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id     INT UNSIGNED NOT NULL,
    token           CHAR(64)     NOT NULL,        -- 32 random bytes, hex encoded
    password_hash   VARCHAR(255) NULL,            -- bcrypt of the derived password
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    DATETIME     NULL,            -- brute-force lockout
    last_used_at    DATETIME     NULL,
    issued_at       DATETIME     NULL,            -- when the card was last generated
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_eqt_token (token),
    UNIQUE KEY uk_eqt_employee (employee_id),
    CONSTRAINT fk_eqt_employee FOREIGN KEY (employee_id)
        REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Access audit log ─────────────────────────────────────────────────────────
-- Every token hit and every password attempt is recorded, so a leaked sticker can
-- be traced. Kept deliberately free of any salary/attendance detail.
CREATE TABLE IF NOT EXISTS employee_portal_access_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NULL,
    token_id    INT UNSIGNED NULL,
    event       VARCHAR(40)  NOT NULL,            -- scan | login_ok | login_fail | locked | view_slip | view_attendance | logout
    success     TINYINT(1)   NOT NULL DEFAULT 0,
    ip_address  VARCHAR(45)  NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    KEY idx_epal_emp (employee_id),
    KEY idx_epal_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Permissions ──────────────────────────────────────────────────────────────
INSERT IGNORE INTO permissions (module, action, label) VALUES
('idcard', 'view',     'View / Print Employee ID Card'),
('idcard', 'generate', 'Generate Employee ID Card & QR token'),
('idcard', 'revoke',   'Revoke / Regenerate Employee QR token');

-- Super Admin (role 1) — all (also covered by the code-level bypass).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions WHERE module = 'idcard';

-- HR Manager (role 2) — full ID card management.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE module = 'idcard';

-- HR Executive (role 3) — view + generate, but may not revoke an issued token.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions WHERE module = 'idcard' AND action IN ('view', 'generate');

-- Manager (role 5) — view / print only.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 5, id FROM permissions WHERE module = 'idcard' AND action = 'view';

-- Employee (role 6) — may view their OWN card only (page-level self-scoping
-- restricts them to their own employee record).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 6, id FROM permissions WHERE module = 'idcard' AND action = 'view';

-- ─── Grant by role NAME as well ───────────────────────────────────────────────
-- Installs that created their roles through the UI do not use the seed ids
-- above, so repeat the grants by name. Both passes are INSERT IGNORE, so an
-- install where the ids DO match simply gets no extra rows.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  WHERE p.module = 'idcard'
    AND LOWER(r.name) IN ('super admin', 'admin', 'hr manager');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  WHERE p.module = 'idcard' AND p.action IN ('view', 'generate')
    AND LOWER(r.name) = 'hr executive';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  WHERE p.module = 'idcard' AND p.action = 'view'
    AND LOWER(r.name) IN ('manager', 'employee');

-- ─── Blood group (printed on the back of the ID card) ─────────────────────────
-- Not part of the original employees schema. Editable via the employee
-- Add/Edit forms (Personal Information section).
ALTER TABLE `employees`
    ADD COLUMN IF NOT EXISTS `blood_group` VARCHAR(5) NULL AFTER `gender`;
