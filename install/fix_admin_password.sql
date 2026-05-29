-- ============================================================
-- MagDyn HRMS — Fix Admin Password
-- Run this if you already imported schema.sql and login fails.
--
-- This resets admin@hrms.local password to: Admin@1234
-- Hash is bcrypt cost=12, compatible with PHP password_verify()
-- ============================================================

UPDATE users
SET password_hash = '$2y$12$WBStR16B4orQUcl2xlMtee1cR1MxiS.lH6ZV9Wb.LWQLyBIY2tMm2',
    is_active     = 1
WHERE email = 'admin@hrms.local';

-- Verify
SELECT id, email, name, is_active,
       LEFT(password_hash, 20) AS hash_preview
FROM users
WHERE email = 'admin@hrms.local';
