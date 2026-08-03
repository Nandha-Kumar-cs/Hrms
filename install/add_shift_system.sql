-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: Shift system — Phase 0 (schema + seed)
-- Run once: mysql -u root -h 127.0.0.1 -P 3307 magdyn_hrms < install/add_shift_system.sql
--
-- Introduces per-shift timing (office hours, grace, OT, breaks, lunch). The
-- General shift is seeded from the app's CURRENT effective values, so behaviour
-- is IDENTICAL until an employee is explicitly moved to another shift.
--
-- Morning (06:00-14:00) and Evening (14:00-22:00) are "straight" 8-hour shifts:
-- no lunch, no tea breaks, no OT (ot_enabled = 0).
-- Idempotent: safe to run multiple times.
-- ─────────────────────────────────────────────────────────────────────────────

-- ── Parent: one row per shift ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `shifts` (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(80)  NOT NULL,
    status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
    start_time        TIME NOT NULL,
    end_time          TIME NOT NULL,
    daily_grace_mins  INT  NOT NULL DEFAULT 15,
    monthly_grace_mins INT NOT NULL DEFAULT 90,
    ot_enabled        TINYINT(1) NOT NULL DEFAULT 1,   -- 0 = this shift earns no OT
    ot_trigger_time   TIME NULL,                       -- used only when ot_enabled = 1
    ot_baseline_time  TIME NULL,
    half_day_cutoff   TIME NULL,                       -- check-in after this = Half Day
    lunch_start       TIME NULL,                       -- default lunch window; NULL = no lunch
    lunch_end         TIME NULL,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shift_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Child: tea / general breaks per shift (lunch stays in lunch_batches) ──────
CREATE TABLE IF NOT EXISTS `shift_breaks` (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shift_id    INT UNSIGNED NOT NULL,
    kind        ENUM('tea','break') NOT NULL DEFAULT 'tea',
    name        VARCHAR(80) NOT NULL,
    start_time  TIME NOT NULL,
    end_time    TIME NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_shift_breaks_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Assignment columns on existing tables ────────────────────────────────────
ALTER TABLE `employees`
    ADD COLUMN IF NOT EXISTS `shift_id` INT UNSIGNED NULL AFTER `lunch_batch_id`;

ALTER TABLE `lunch_batches`
    ADD COLUMN IF NOT EXISTS `shift_id` INT UNSIGNED NULL AFTER `id`;

-- Phase 2: stamp the shift that applied on each marked day, so payslips for a
-- past month keep using the shift the employee ACTUALLY worked, even if they
-- are later moved to a different shift. NULL = legacy row (pre-shift-system);
-- consumers fall back to the employee's current shift.
ALTER TABLE `attendance`
    ADD COLUMN IF NOT EXISTS `shift_id` INT UNSIGNED NULL AFTER `employee_id`;

-- ── Seed the three shifts (idempotent by name) ───────────────────────────────
-- General mirrors the app's current defaults (config/app.php + Break Settings):
--   office 09:00-18:00, grace 15/90, OT 20:30/18:15, half-day = start+2h = 11:00,
--   default lunch 13:00-13:30.
-- Each value prefers this install's OWN app_settings row (so a site that had
-- customised its office hours keeps them) and only falls back to the shipped
-- default when that setting was never saved. half_day_cutoff mirrors the legacy
-- "office start + 2h" rule.
INSERT INTO `shifts`
    (name, status, start_time, end_time, daily_grace_mins, monthly_grace_mins,
     ot_enabled, ot_trigger_time, ot_baseline_time, half_day_cutoff, lunch_start, lunch_end)
SELECT 'General','active',
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key='office_start_time'), '09:00:00'),
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key='office_end_time'),   '18:00:00'),
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key='daily_grace_minutes'),   15),
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key='monthly_grace_minutes'), 90),
       1,
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key='ot_trigger_time'),  '20:30:00'),
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key='ot_baseline_time'), '18:15:00'),
       ADDTIME(COALESCE((SELECT setting_value FROM app_settings WHERE setting_key='office_start_time'), '09:00:00'), '02:00:00'),
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key='lunch_start'), '13:00:00'),
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key='lunch_end'),   '13:30:00')
WHERE NOT EXISTS (SELECT 1 FROM `shifts` WHERE name='General');

-- Morning: straight 8h, no OT, no lunch (half-day = start+2h = 08:00).
INSERT INTO `shifts`
    (name, status, start_time, end_time, daily_grace_mins, monthly_grace_mins,
     ot_enabled, ot_trigger_time, ot_baseline_time, half_day_cutoff, lunch_start, lunch_end)
SELECT 'Morning (6-2)','active','06:00:00','14:00:00',15,90,0,NULL,NULL,'08:00:00',NULL,NULL
WHERE NOT EXISTS (SELECT 1 FROM `shifts` WHERE name='Morning (6-2)');

-- Evening: straight 8h, no OT, no lunch (half-day = start+2h = 16:00).
INSERT INTO `shifts`
    (name, status, start_time, end_time, daily_grace_mins, monthly_grace_mins,
     ot_enabled, ot_trigger_time, ot_baseline_time, half_day_cutoff, lunch_start, lunch_end)
SELECT 'Evening (2-10)','active','14:00:00','22:00:00',15,90,0,NULL,NULL,'16:00:00',NULL,NULL
WHERE NOT EXISTS (SELECT 1 FROM `shifts` WHERE name='Evening (2-10)');

-- ── Seed General's two tea breaks (idempotent) ───────────────────────────────
INSERT INTO `shift_breaks` (shift_id, kind, name, start_time, end_time)
SELECT s.id,'tea','Tea Break 1',
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key='tea1_start'), '11:00:00'),
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key='tea1_end'),   '11:15:00')
  FROM `shifts` s
 WHERE s.name='General'
   AND NOT EXISTS (SELECT 1 FROM `shift_breaks` b WHERE b.shift_id=s.id AND b.name='Tea Break 1');

INSERT INTO `shift_breaks` (shift_id, kind, name, start_time, end_time)
SELECT s.id,'tea','Tea Break 2',
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key='tea2_start'), '16:00:00'),
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key='tea2_end'),   '16:15:00')
  FROM `shifts` s
 WHERE s.name='General'
   AND NOT EXISTS (SELECT 1 FROM `shift_breaks` b WHERE b.shift_id=s.id AND b.name='Tea Break 2');

-- ── Backfill: everyone + every lunch batch → General ─────────────────────────
UPDATE `employees`
   SET shift_id = (SELECT id FROM `shifts` WHERE name='General' LIMIT 1)
 WHERE shift_id IS NULL;

UPDATE `lunch_batches`
   SET shift_id = (SELECT id FROM `shifts` WHERE name='General' LIMIT 1)
 WHERE shift_id IS NULL;
