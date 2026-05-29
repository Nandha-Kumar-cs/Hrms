-- ─────────────────────────────────────────────────────────────────────────────
-- Attendance Report Migration
-- Run once to add OT hours tracking and working-holiday support.
-- ─────────────────────────────────────────────────────────────────────────────

-- OT hours per attendance record (decimal hours, e.g. 1.50 = 1h 30m)
ALTER TABLE `attendance`
    ADD COLUMN IF NOT EXISTS `ot_hours` DECIMAL(5,2) NULL DEFAULT NULL
    AFTER `out_time`;

-- Mark a holiday as a "working day" (comp-off earner / working holiday).
-- When is_working_day = 1 the day is treated as a regular working day in the
-- attendance report, and the column header shows a briefcase icon.
ALTER TABLE `holidays`
    ADD COLUMN IF NOT EXISTS `is_working_day` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `type`;
