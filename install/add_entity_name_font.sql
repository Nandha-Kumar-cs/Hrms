-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: per-entity font style for the ENTITY NAME
-- Run once: mysql -u root -h 127.0.0.1 -P 3307 magdyn_hrms < install/add_entity_name_font.sql
--
-- Lets each entity print its company name in its own typeface on payslips,
-- letters and circulars. Only the NAME is affected — addresses, tables and body
-- text keep the document's own styling.
--
-- Stores a KEY (e.g. 'georgia'), never raw CSS: entity_font_css() in
-- includes/helpers.php maps the key to a font stack, so a value from the
-- database can never inject styling of its own.
--
-- NULL / '' = the document's default font, i.e. exactly today's appearance.
-- Idempotent: safe to run multiple times.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `entities`
    ADD COLUMN IF NOT EXISTS `name_font` VARCHAR(32) NULL AFTER `name`;
