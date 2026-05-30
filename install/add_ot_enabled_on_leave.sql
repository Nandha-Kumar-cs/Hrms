-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: ot_enabled on employees + On Leave status in attendance
-- Run once: mysql -u root -p magdyn_hrms < install/add_ot_enabled_on_leave.sql
-- ─────────────────────────────────────────────────────────────────────────────

-- Auto-OT flag per employee (matches old Payroll app behaviour)
ALTER TABLE `employees`
    ADD COLUMN IF NOT EXISTS `ot_enabled` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `designation_id`;

-- Add 'On Leave' as an attendance status (mirrors old app's on_leave)
ALTER TABLE `attendance`
    MODIFY COLUMN `status`
        ENUM('On Time','Late','Absent','OD','Comp Off','Half Day','Holiday','On Leave')
        NOT NULL;
