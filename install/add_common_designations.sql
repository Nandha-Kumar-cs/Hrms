-- ─────────────────────────────────────────────────────────────────────────────
-- Designations that are common to EVERY department.
--
-- Designations are normally scoped to one department and the employee form's
-- Department → Designation cascade only offers that department's rows. Some
-- roles — "Intern" above all — exist across every department, and previously
-- needed one duplicate row per department.
--
-- A new all_departments flag marks a designation as common: the cascade then
-- offers it whatever department is selected.
--
-- WHY A FLAG AND NOT "department_id IS NULL":
--   deleting a department already sets its designations' department_id to NULL
--   (modules/settings/tabs/departments.php — "detach designations"). Treating
--   NULL as "common to all" would silently publish every ORPHANED designation
--   into every department. The flag keeps "orphaned" and "common" distinct.
--
-- Idempotent — safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE designations
    ADD COLUMN IF NOT EXISTS all_departments TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = offered under every department, ignoring department_id'
    AFTER department_id;

-- Interns exist in every department — make any existing "Intern" designation
-- common. department_id is cleared because it is meaningless once the flag is
-- set. Matches the whole-word rule used by designation_is_intern() in
-- includes/helpers.php ("Intern", "Software Intern" — never "Internal Auditor").
UPDATE designations
   SET all_departments = 1,
       department_id   = NULL
 WHERE all_departments = 0
   AND name REGEXP '[[:<:]]intern[[:>:]]';
