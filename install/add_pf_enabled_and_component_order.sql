-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: per-employee PF toggle + salary component display order
-- Run once: mysql -u root -p magdyn_hrms < install/add_pf_enabled_and_component_order.sql
-- ─────────────────────────────────────────────────────────────────────────────

-- Per-employee PF flag. PF is only deducted when this is 1.
-- DEFAULT 1 so every EXISTING employee keeps the PF deduction they get today —
-- before this column, PF was applied unconditionally to everyone. Rolling the
-- default to 0 would silently stop PF for the whole company on the next payroll.
ALTER TABLE `employees`
    ADD COLUMN IF NOT EXISTS `pf_enabled` TINYINT(1) NOT NULL DEFAULT 1
    AFTER `ot_enabled`;

-- Display order for salary components — drives the row order on the salary slip
-- and the offer letter breakup.
ALTER TABLE `salary_components`
    ADD COLUMN IF NOT EXISTS `sort_order` INT NOT NULL DEFAULT 0
    AFTER `value`;

-- Seed the order from the existing ids so the current offer-letter order
-- (previously "ORDER BY id") is preserved on day one. Only touches rows that
-- have never been ordered, so re-running this file is safe.
UPDATE `salary_components` SET `sort_order` = `id` WHERE `sort_order` = 0;
