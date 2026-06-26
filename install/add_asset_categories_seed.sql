-- ─────────────────────────────────────────────────────────────────────────────
-- Asset Categories — default seed
-- The asset_categories table (created in schema.sql) drives the "Category"
-- dropdown on the Add/Edit Asset pages and the new Settings → Asset Categories
-- screen. On databases that were created without the seed (or had it cleared),
-- the dropdown is empty. This backfills the standard set.
-- Idempotent: each row inserts only if a category of that name is absent.
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO asset_categories (name)
SELECT v.name FROM (
    SELECT 'Laptop' AS name UNION ALL SELECT 'Desktop' UNION ALL SELECT 'Mobile'
    UNION ALL SELECT 'Monitor' UNION ALL SELECT 'Keyboard' UNION ALL SELECT 'Mouse'
    UNION ALL SELECT 'Headset' UNION ALL SELECT 'ID Card' UNION ALL SELECT 'Access Card'
    UNION ALL SELECT 'Vehicle' UNION ALL SELECT 'Other'
) AS v
WHERE NOT EXISTS (
    SELECT 1 FROM asset_categories c WHERE c.name = v.name
);
