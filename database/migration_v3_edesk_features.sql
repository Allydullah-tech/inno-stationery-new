-- ============================================================
-- INNO's STATIONERY — v3: features ported from the edesk system
--   customers, multi-item receipts, customer change, service materials
--   (service purchases), cash float, cash audit, bank deposits,
--   activity (audit) log, minimum prices, "paid from" tracking.
-- Safe to run more than once (MariaDB / XAMPP / InfinityFree).
-- ============================================================
SET NAMES utf8mb4;

-- ---------- customers ----------
CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(40) NOT NULL UNIQUE,
    status ENUM('active','restricted') NOT NULL DEFAULT 'active',
    restricted_at DATETIME DEFAULT NULL,
    restriction_reason VARCHAR(255) DEFAULT NULL,
    lifted_by INT UNSIGNED DEFAULT NULL,
    lifted_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- sales: receipts, customer link ----------
ALTER TABLE sales ADD COLUMN IF NOT EXISTS receipt_no VARCHAR(24) DEFAULT NULL;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS customer_id INT UNSIGNED DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_sales_receipt ON sales (receipt_no);
CREATE INDEX IF NOT EXISTS idx_sales_date ON sales (sale_date);
ALTER TABLE debts ADD COLUMN IF NOT EXISTS customer_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE debts ADD COLUMN IF NOT EXISTS receipt_no VARCHAR(24) DEFAULT NULL;

-- ---------- minimum selling price ----------
ALTER TABLE products ADD COLUMN IF NOT EXISTS minimum_price DECIMAL(12,2) DEFAULT NULL;
ALTER TABLE services ADD COLUMN IF NOT EXISTS minimum_price DECIMAL(12,2) DEFAULT NULL;

-- ---------- where the money for an expense / purchase came from ----------
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS paid_from ENUM('cash','online','other') NOT NULL DEFAULT 'cash';
ALTER TABLE purchases ADD COLUMN IF NOT EXISTS paid_from ENUM('cash','online','other') NOT NULL DEFAULT 'other';

-- ---------- customer change left with the shop ----------
CREATE TABLE IF NOT EXISTS change_holds (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_no VARCHAR(24) DEFAULT NULL,
    customer_name VARCHAR(150) DEFAULT NULL,
    customer_phone VARCHAR(40) DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL,
    hold_date DATE NOT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hold_date (hold_date),
    INDEX idx_hold_receipt (receipt_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS change_collections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hold_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    collected_date DATE NOT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_col_date (collected_date),
    FOREIGN KEY (hold_id) REFERENCES change_holds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- service materials (paper, toner, ink, lamination film ...) ----------
CREATE TABLE IF NOT EXISTS service_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(190) NOT NULL DEFAULT '',
    unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
    stock_quantity INT NOT NULL DEFAULT 0,
    buying_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_service_item (name, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_purchases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_item_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    buying_price DECIMAL(12,2) NOT NULL,
    total_cost DECIMAL(12,2) NOT NULL,
    purchase_date DATE NOT NULL,
    paid_from ENUM('cash','online','other') NOT NULL DEFAULT 'other',
    note VARCHAR(255) DEFAULT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sp_date (purchase_date),
    FOREIGN KEY (service_item_id) REFERENCES service_items(id) ON DELETE RESTRICT,
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- materials a service sale consumed (stock is deducted on sale, restored on delete)
CREATE TABLE IF NOT EXISTS sale_materials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id INT UNSIGNED NOT NULL,
    service_item_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (service_item_id) REFERENCES service_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- cash float (opening cash / top-ups / cash taken out) ----------
CREATE TABLE IF NOT EXISTS cash_floats (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    float_date DATE NOT NULL,
    entry_type ENUM('opening','add','reduce') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_float_date (float_date),
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- cash audits (a saved end-of-day count) ----------
CREATE TABLE IF NOT EXISTS cash_audits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audit_date DATE NOT NULL,
    opening_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
    cash_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
    online_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
    credit_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
    debt_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
    debt_online DECIMAL(14,2) NOT NULL DEFAULT 0,
    expenses_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
    expenses_online DECIMAL(14,2) NOT NULL DEFAULT 0,
    change_held DECIMAL(14,2) NOT NULL DEFAULT 0,
    change_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
    expected_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
    expected_online DECIMAL(14,2) NOT NULL DEFAULT 0,
    counted_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
    counted_online DECIMAL(14,2) NOT NULL DEFAULT 0,
    cash_variance DECIMAL(14,2) NOT NULL DEFAULT 0,
    online_variance DECIMAL(14,2) NOT NULL DEFAULT 0,
    variance DECIMAL(14,2) NOT NULL DEFAULT 0,
    denominations TEXT,
    bank_deposit DECIMAL(14,2) NOT NULL DEFAULT 0,
    remaining_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_audit_date (audit_date),
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- bank deposits (cash banked after an audit) ----------
CREATE TABLE IF NOT EXISTS bank_deposits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deposit_date DATE NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    bank_name VARCHAR(100) DEFAULT NULL,
    reference_no VARCHAR(80) DEFAULT NULL,
    receipt_file VARCHAR(120) DEFAULT NULL,
    receipt_mime VARCHAR(60) DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dep_date (deposit_date),
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- activity (audit) log ----------
CREATE TABLE IF NOT EXISTS activity_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED DEFAULT NULL,
    admin_name VARCHAR(120) DEFAULT NULL,
    action VARCHAR(40) NOT NULL,
    entity VARCHAR(40) NOT NULL,
    entity_id VARCHAR(40) DEFAULT NULL,
    description VARCHAR(500) DEFAULT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_created (created_at),
    INDEX idx_log_entity (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (setting_key, setting_value) VALUES
    ('restrict_after_overdue', '3')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
