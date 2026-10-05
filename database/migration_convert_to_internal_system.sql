-- ============================================================
-- Migration: convert to internal stock/sales system
-- ============================================================
-- ⚠️ BACK UP YOUR DATABASE FIRST (phpMyAdmin → Export) before
-- running this. It permanently deletes your orders and print
-- request history — there is no undo once it runs.
--
-- Run this ONCE against your live database.
-- ============================================================

-- 1. Allow the new "worker" role
ALTER TABLE admins
    MODIFY COLUMN role ENUM('super_admin','admin','worker') NOT NULL DEFAULT 'worker';

-- 2. Add cost price + low-stock threshold to products (stock)
ALTER TABLE products
    ADD COLUMN buy_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER description,
    ADD COLUMN low_stock_threshold INT NOT NULL DEFAULT 5 AFTER stock,
    DROP COLUMN is_featured;

-- 3. Remove customer-facing tables entirely
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS print_requests;

-- 4. New tables: sales, expenses, damages, purchases
CREATE TABLE IF NOT EXISTS sales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED DEFAULT NULL,
    product_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    sale_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    category VARCHAR(80) DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    expense_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS damages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED DEFAULT NULL,
    product_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL,
    reason ENUM('expired','damaged','other') NOT NULL DEFAULT 'damaged',
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    loss_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes VARCHAR(255) DEFAULT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    damage_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_name VARCHAR(150) NOT NULL,
    reference_no VARCHAR(80) DEFAULT NULL,
    total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes VARCHAR(255) DEFAULT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    purchase_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED DEFAULT NULL,
    product_name VARCHAR(150) NOT NULL,
    pack_size INT NOT NULL DEFAULT 1,
    pack_price DECIMAL(12,2) NOT NULL,
    packs_received DECIMAL(12,2) NOT NULL,
    unit_buy_price DECIMAL(12,2) NOT NULL,
    quantity_units INT NOT NULL,
    new_sell_price DECIMAL(12,2) DEFAULT NULL,
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Clean out old customer/payment settings, add new ones
DELETE FROM settings WHERE setting_key IN
    ('payment_phone','payment_name','payment_method_label','delivery_fee','site_brand','site_tagline');

INSERT INTO settings (setting_key, setting_value) VALUES
    ('site_name', "INNO's Stationery — Internal System"),
    ('currency_symbol', 'TSh'),
    ('default_low_stock_threshold', '5')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
