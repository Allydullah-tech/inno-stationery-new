-- ============================================================
-- Migration: add Cash/Credit sales workflow + Debts/Credit
-- management (debts + debt_payments tables).
-- ============================================================
-- Safe to run on an existing live database. Back it up first
-- anyway (phpMyAdmin → Export) as good practice.
--
-- Run this ONCE against your live database.
-- ============================================================

-- 1. Sales: record how the sale was paid for.
ALTER TABLE sales
    ADD COLUMN sale_type ENUM('cash','credit') NOT NULL DEFAULT 'cash' AFTER total_amount,
    ADD COLUMN payment_method ENUM('cash_in_hand','mpesa','airtel_money','halopesa','mixx_by_yas','credit') DEFAULT NULL AFTER sale_type;

-- Best-guess backfill: every historic sale was a straightforward
-- cash-in-hand sale before this feature existed.
UPDATE sales SET sale_type = 'cash', payment_method = 'cash_in_hand' WHERE payment_method IS NULL;

-- 2. Debts (credit sales / customer accounts receivable)
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Debt payments (payment history per debt)
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
