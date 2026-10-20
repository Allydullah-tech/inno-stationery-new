-- ============================================================
-- Migration: product/service "types" (variants), service categories,
-- remove product images and the service buying cost.
-- ============================================================
-- Run this ONCE against your live database (back it up first).
-- Existing products/services keep working: each becomes a product/service
-- with a single, unnamed type.
-- ============================================================

-- 1. Products: types + grouping, drop images
ALTER TABLE products
    ADD COLUMN group_id INT UNSIGNED DEFAULT NULL AFTER id,
    ADD COLUMN variant VARCHAR(120) NOT NULL DEFAULT '' AFTER name,
    DROP COLUMN image;
UPDATE products SET group_id = id WHERE group_id IS NULL;

-- 2. Services: category + types + grouping, drop buying cost
ALTER TABLE services
    ADD COLUMN group_id INT UNSIGNED DEFAULT NULL AFTER id,
    ADD COLUMN category_id INT UNSIGNED DEFAULT NULL AFTER group_id,
    ADD COLUMN variant VARCHAR(120) NOT NULL DEFAULT '' AFTER name,
    ADD CONSTRAINT fk_services_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    DROP COLUMN buying_cost;
UPDATE services SET group_id = id WHERE group_id IS NULL;
