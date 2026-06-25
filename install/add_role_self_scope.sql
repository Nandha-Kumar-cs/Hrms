-- ─────────────────────────────────────────────────────────────────────────────
-- Add a per-role "self scope" flag.
--
-- When self_scope = 1, users with that role may only see their OWN employee data
-- across every list/report/profile (enforced by includes/scope.php). When 0, the
-- role keeps full cross-employee visibility (subject to its normal permissions).
--
-- Idempotent: safe to run multiple times.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE roles
    ADD COLUMN IF NOT EXISTS self_scope TINYINT(1) NOT NULL DEFAULT 0 AFTER description;

-- Seed: preserve the previous hard-coded behaviour where any NON-privileged role
-- (i.e. the "Employee" role) was self-scoped. Privileged roles stay unrestricted.
-- Super Admin (id 1) is never self-scoped.
UPDATE roles
   SET self_scope = 1
 WHERE id <> 1
   AND LOWER(name) NOT IN ('super admin','admin','hr manager','hr executive','manager','finance')
   AND self_scope = 0;
