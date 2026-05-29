-- Migration: add missing columns to training_courses
-- Run once on any database created from the original schema.sql baseline.
-- Safe to re-run: each statement uses IF NOT EXISTS / ADD COLUMN only when absent.

ALTER TABLE training_courses
    ADD COLUMN IF NOT EXISTS training_type VARCHAR(80)  NULL          AFTER category,
    ADD COLUMN IF NOT EXISTS trainer_name  VARCHAR(120) NULL          AFTER training_type,
    ADD COLUMN IF NOT EXISTS status        ENUM('Draft','Active','Completed','Archived')
                                           NOT NULL DEFAULT 'Active'  AFTER is_mandatory;
