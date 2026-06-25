-- ─────────────────────────────────────────────────────────────────────────────
-- Payment mode for employee benefits: Cash or Cashless.
-- Idempotent: safe to run multiple times.
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE employee_benefits
    ADD COLUMN IF NOT EXISTS payment_mode ENUM('cash','cashless') NOT NULL DEFAULT 'cash' AFTER amount;
