-- ============================================================
-- INNO's STATIONERY - COMPLETE DATABASE (single file)
-- Import directly in phpMyAdmin: select your database -> Import.
-- Contains all tables (schema + every migration already merged),
-- default settings, starter categories and ONE starter admin login.
-- No CREATE DATABASE / USE line, so it works on InfinityFree.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Admins & workers (created manually via install.php / Add Admin)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(60) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin','admin','worker') NOT NULL DEFAULT 'worker',
    security_question_1 VARCHAR(255) NOT NULL,
    security_answer_1 VARCHAR(255) NOT NULL,
    security_question_2 VARCHAR(255) NOT NULL,
    security_answer_2 VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Categories
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    icon VARCHAR(50) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Products (Stock)
-- buy_price = what you paid per unit (cost) — used for profit calc.
-- price     = your selling price per unit.
-- variant   = the product "type" (e.g. Blue, A4). Each type is its own row;
--             rows of one product share the same group_id.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED DEFAULT NULL,
    group_id INT UNSIGNED DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    variant VARCHAR(120) NOT NULL DEFAULT '',
    description TEXT,
    buy_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    stock INT NOT NULL DEFAULT 0,
    low_stock_threshold INT NOT NULL DEFAULT 5,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    minimum_price DECIMAL(12,2) DEFAULT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Services (non-stock offerings — printing, binding, design work, etc.)
-- No buying cost is tracked for services. Each type (variant) is its own row;
-- rows of one service share the same group_id.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED DEFAULT NULL,
    category_id INT UNSIGNED DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    variant VARCHAR(120) NOT NULL DEFAULT '',
    code VARCHAR(40) DEFAULT NULL UNIQUE,
    description TEXT,
    selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    minimum_price DECIMAL(12,2) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Sales (recorded directly by admin/worker — no customer accounts)
-- item_type tells you whether product_id or service_id is populated.
-- list_price keeps the item's default price at the time of sale, while
-- unit_price is the actual price charged — so a discount never destroys
-- the original price. unit_cost is nullable: NULL = cost not known
-- (only possible for services with no buying_cost set), 0.00 = a real
-- zero cost. Never treat NULL as 0 in profit calculations.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_type ENUM('product','service') NOT NULL DEFAULT 'product',
    product_id INT UNSIGNED DEFAULT NULL,
    service_id INT UNSIGNED DEFAULT NULL,
    product_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL,
    list_price DECIMAL(12,2) DEFAULT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    unit_cost DECIMAL(12,2) DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL,
    sale_type ENUM('cash','credit') NOT NULL DEFAULT 'cash',
    payment_method ENUM('cash_in_hand','mpesa','airtel_money','halopesa','mixx_by_yas','credit') DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    sale_date DATE NOT NULL,
    receipt_no VARCHAR(24) DEFAULT NULL,
    customer_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sales_receipt (receipt_no),
    INDEX idx_sales_date (sale_date),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Debts (credit sales) — one row per "Sale on Credit".
-- balance is kept in sync with (original_amount - amount_paid)
-- every time a payment is recorded against it.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS debts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id INT UNSIGNED DEFAULT NULL,
    customer_name VARCHAR(150) NOT NULL,
    customer_phone VARCHAR(40) NOT NULL,
    original_amount DECIMAL(12,2) NOT NULL,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(12,2) NOT NULL,
    payment_deadline DATE NOT NULL,
    status ENUM('pending','partial','paid','overdue') NOT NULL DEFAULT 'pending',
    notes VARCHAR(255) DEFAULT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    sale_date DATE NOT NULL,
    customer_id INT UNSIGNED DEFAULT NULL,
    receipt_no VARCHAR(24) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Debt payments — full payment history per debt/credit record.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS debt_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    debt_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('cash','mpesa','airtel_money','halopesa','mixx_by_yas') NOT NULL DEFAULT 'cash',
    reference_no VARCHAR(80) DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (debt_id) REFERENCES debts(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Expenses
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    category VARCHAR(80) DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    expense_date DATE NOT NULL,
    paid_from ENUM('cash','online','other') NOT NULL DEFAULT 'cash',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Damages / expired stock (reduces stock, tracked as a loss).
-- is_stock_item = 0 records an asset that was never in the products
-- table (equipment, tools, office items, etc.) — it never touches
-- product stock. category is a free-text type/category, mainly used
-- for non-stock items (e.g. "Equipment", "Electronics").
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS damages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    is_stock_item TINYINT(1) NOT NULL DEFAULT 1,
    product_id INT UNSIGNED DEFAULT NULL,
    product_name VARCHAR(150) NOT NULL,
    category VARCHAR(80) DEFAULT NULL,
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

-- ------------------------------------------------------------
-- Purchases (stock received from a vendor) + line items.
-- Supports vendor pricing given per pack (e.g. a dozen) which
-- gets converted down to a per-unit buy price automatically.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_name VARCHAR(150) DEFAULT NULL,
    reference_no VARCHAR(80) DEFAULT NULL,
    source ENUM('manual','excel') NOT NULL DEFAULT 'manual',
    total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes VARCHAR(255) DEFAULT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    purchase_date DATE NOT NULL,
    paid_from ENUM('cash','online','other') NOT NULL DEFAULT 'other',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED DEFAULT NULL,
    product_name VARCHAR(150) NOT NULL,
    pack_size INT NOT NULL DEFAULT 1,          -- e.g. 12 for "a dozen"
    pack_price DECIMAL(12,2) NOT NULL,          -- price vendor charged PER PACK
    packs_received DECIMAL(12,2) NOT NULL,      -- how many packs received
    unit_buy_price DECIMAL(12,2) NOT NULL,      -- computed: pack_price / pack_size
    quantity_units INT NOT NULL,                -- computed: pack_size * packs_received
    new_sell_price DECIMAL(12,2) DEFAULT NULL,  -- optional: update selling price too
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Site settings
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Password reset attempts log (basic brute-force throttling)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_reset_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- Default settings
-- ------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
    ('site_name', "INNO's Stationery — Internal System"),
    ('currency_symbol', 'TSh'),
    ('default_low_stock_threshold', '5')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ------------------------------------------------------------
-- Starter categories
-- ------------------------------------------------------------
INSERT INTO categories (name, slug, icon) VALUES
    ('Books & Notebooks', 'books-notebooks', 'book'),
    ('Pens & Pencils', 'pens-pencils', 'pen'),
    ('Files & Folders', 'files-folders', 'folder'),
    ('Art & Craft', 'art-craft', 'palette'),
    ('Office Supplies', 'office-supplies', 'briefcase'),
    ('School Supplies', 'school-supplies', 'graduation-cap')
ON DUPLICATE KEY UPDATE slug = slug;

-- ============================================================
-- v3 additions (edesk features) — included so a fresh install is complete
-- ============================================================

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

-- ---------- minimum selling price ----------

-- ---------- where the money for an expense / purchase came from ----------

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

-- ------------------------------------------------------------
-- Starter super admin (needed because install.php is locked)
-- Login:  username  admin   |  password  Inno#2026Start
-- CHANGE THE PASSWORD right after your first login (Settings).
-- Security questions/answers (for password recovery):
--   Q1 "Name of the shop?"  -> inno
--   Q2 "Type of business?"  -> stationery
-- ------------------------------------------------------------
INSERT INTO admins (full_name, username, email, password_hash, role, security_question_1, security_answer_1, security_question_2, security_answer_2)
SELECT 'System Admin', 'admin', 'admin@inno-stationery.local', '$2y$10$NEoewazlXNs3ihRMjsEzI.871s6F//1YWfOg9GZB6IEQMH/cMw2MG', 'super_admin',
       'Name of the shop?', '$2y$10$/2/6R8E0YtAWUcJmTsPh7uUrZeVBBD69JP9ggA1Utmrt/dAXCipPC', 'Type of business?', '$2y$10$U2hf6q/sw6sB/rSx8CHB7ezci2Rsgcv7tgsksKNFBfQb9Y5UBjeKK'
WHERE NOT EXISTS (SELECT 1 FROM admins WHERE username = 'admin');
