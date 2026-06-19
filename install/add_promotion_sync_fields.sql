-- ─────────────────────────────────────────────────────────────────────────────
-- Promotion ⇄ Letter synchronisation fields.
--
-- Links each promotion-history row (employee_promotions) to the promotion LETTER
-- that produced it and carries the extra fields the unified workflow keeps in
-- sync (promotion date, salary revision, status, letter reference, created_by).
--
-- Idempotent: safe to run multiple times (MariaDB 10.x "IF NOT EXISTS").
-- Run:  C:/xampp8.2/php/php.exe -r "require 'config/database.php'; db()->exec(file_get_contents('install/add_promotion_sync_fields.sql'));"
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE employee_promotions
    ADD COLUMN IF NOT EXISTS letter_id        INT            NULL AFTER id,
    ADD COLUMN IF NOT EXISTS promotion_date   DATE           NULL AFTER effective_date,
    ADD COLUMN IF NOT EXISTS salary_revision  DECIMAL(12,2)  NULL,
    ADD COLUMN IF NOT EXISTS status           VARCHAR(20)    NULL DEFAULT 'Active',
    ADD COLUMN IF NOT EXISTS letter_reference VARCHAR(60)    NULL,
    ADD COLUMN IF NOT EXISTS created_by       INT            NULL;

ALTER TABLE employee_promotions
    ADD INDEX IF NOT EXISTS idx_promo_letter (letter_id);
