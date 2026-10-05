-- ============================================================
-- Migration: Services catalogue, Sales edit + product/service
-- selling, non-stock Damages, Purchase source tracking.
-- ============================================================
-- Run this ONCE against your live database (back it up first).
-- ============================================================

-- 1. Services catalogue
CREATE TABLE IF NOT EXISTS services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(40) DEFAULT NULL UNIQUE,
    description TEXT,
    buying_cost DECIMAL(12,2) DEFAULT NULL,
    selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Sales: product-or-service selling, edit support, discount-safe pricing.
ALTER TABLE sales
    ADD COLUMN item_type ENUM('product','service') NOT NULL DEFAULT 'product' AFTER id,
    ADD COLUMN service_id INT UNSIGNED DEFAULT NULL AFTER product_id,
    ADD COLUMN list_price DECIMAL(12,2) DEFAULT NULL AFTER quantity,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Backfill: every historic sale was a product sale at its recorded price.
UPDATE sales SET item_type = 'product' WHERE item_type IS NULL;
UPDATE sales SET list_price = unit_price WHERE list_price IS NULL;

-- unit_cost must allow NULL to distinguish "cost unknown" (services with
-- no buying_cost) from a genuine zero cost. Existing rows already have a
-- real (possibly 0.00) cost, so this is safe.
ALTER TABLE sales MODIFY COLUMN unit_cost DECIMAL(12,2) DEFAULT 0.00;

ALTER TABLE sales
    ADD CONSTRAINT fk_sales_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL;

-- 3. Damages: support non-stock items (equipment, tools, assets, etc.)
ALTER TABLE damages
    ADD COLUMN is_stock_item TINYINT(1) NOT NULL DEFAULT 1 AFTER id,
    ADD COLUMN category VARCHAR(80) DEFAULT NULL AFTER product_name;

-- 4. Purchases: track how each purchase was entered.
ALTER TABLE purchases
    ADD COLUMN source ENUM('manual','excel') NOT NULL DEFAULT 'manual' AFTER reference_no;
