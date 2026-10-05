-- ============================================================
-- Migration: Purchases page simplified to focus only on adding
-- products to stock — vendor name / reference number are no
-- longer required.
-- ============================================================
-- Run this ONCE against your live database (back it up first).
-- ============================================================

ALTER TABLE purchases MODIFY COLUMN vendor_name VARCHAR(150) DEFAULT NULL;
