-- ============================================================
-- AHG — Sold Vehicles Migration (MySQL 5.7 compatible)
-- Generated: 2026-06-07
--
-- Run this on your cPanel MySQL.
-- Uses INFORMATION_SCHEMA checks instead of IF NOT EXISTS
-- so it works on MySQL 5.7 and older cPanel hosts.
-- ============================================================

-- 1. Add `is_sold` column if it doesn't already exist
SET @dbname = DATABASE();

DROP PROCEDURE IF EXISTS ahg_add_col;
DELIMITER //
CREATE PROCEDURE ahg_add_col()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME  = 'auctions'
          AND COLUMN_NAME = 'is_sold'
    ) THEN
        ALTER TABLE `auctions`
            ADD COLUMN `is_sold` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME  = 'auctions'
          AND COLUMN_NAME = 'sold_to'
    ) THEN
        ALTER TABLE `auctions`
            ADD COLUMN `sold_to` VARCHAR(255) DEFAULT NULL COMMENT 'Winning bidder name' AFTER `is_sold`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME  = 'auctions'
          AND COLUMN_NAME = 'sold_to_user_id'
    ) THEN
        ALTER TABLE `auctions`
            ADD COLUMN `sold_to_user_id` INT(11) DEFAULT NULL AFTER `sold_to`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME  = 'auctions'
          AND COLUMN_NAME = 'sold_date'
    ) THEN
        ALTER TABLE `auctions`
            ADD COLUMN `sold_date` DATETIME DEFAULT NULL AFTER `sold_to_user_id`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME  = 'auctions'
          AND COLUMN_NAME = 'final_price'
    ) THEN
        ALTER TABLE `auctions`
            ADD COLUMN `final_price` DECIMAL(12,2) DEFAULT NULL AFTER `sold_date`;
    END IF;

    -- Add index on is_sold if missing
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'auctions'
          AND INDEX_NAME   = 'idx_is_sold'
    ) THEN
        ALTER TABLE `auctions` ADD INDEX `idx_is_sold` (`is_sold`);
    END IF;

    -- Add index on sold_date if missing
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'auctions'
          AND INDEX_NAME   = 'idx_sold_date'
    ) THEN
        ALTER TABLE `auctions` ADD INDEX `idx_sold_date` (`sold_date`);
    END IF;
END //
DELIMITER ;

CALL ahg_add_col();
DROP PROCEDURE IF EXISTS ahg_add_col;

-- 2. Back-fill is_sold for closed auctions that have a final_price
--    but were never explicitly flagged (data consistency fix).
UPDATE `auctions`
SET
    `is_sold`   = 1,
    `sold_date` = COALESCE(`sold_date`, `updated_at`)
WHERE `status`      = 'closed'
  AND `final_price` IS NOT NULL
  AND `final_price` > 0
  AND `is_sold`     = 0;

-- Done. Verify with:
-- SELECT COUNT(*) FROM auctions WHERE is_sold = 1;
