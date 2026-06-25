-- ─────────────────────────────────────────────────────────────────────────────
-- Lunch batches: staggered lunch windows. Each employee can be assigned a batch;
-- payroll deducts that batch's lunch window (plus the global tea breaks) from
-- worked hours. Employees with no batch use the default lunch window in
-- Settings → Break Settings.
-- Idempotent: safe to run multiple times.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS lunch_batches (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(80) NOT NULL,
    start_time TIME NOT NULL,
    end_time   TIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS lunch_batch_id INT UNSIGNED NULL AFTER entity_id;

-- Seed two example batches (only if the table is empty).
INSERT INTO lunch_batches (name, start_time, end_time)
SELECT * FROM (SELECT 'Batch A (1:00–1:30)' AS name, '13:00:00' AS start_time, '13:30:00' AS end_time) AS x
WHERE NOT EXISTS (SELECT 1 FROM lunch_batches);

INSERT INTO lunch_batches (name, start_time, end_time)
SELECT * FROM (SELECT 'Batch B (1:30–2:00)' AS name, '13:30:00' AS start_time, '14:00:00' AS end_time) AS x
WHERE (SELECT COUNT(*) FROM lunch_batches) = 1;
