-- ============================================================
-- AUCTION HUB GROUP — bid_schema_fix.sql
-- Run with auctionhubgroup_db selected in phpMyAdmin
-- ============================================================

-- 1. Add `amount` alias column to bids (api.php expects it)
ALTER TABLE `bids`
  ADD COLUMN IF NOT EXISTS `amount` DECIMAL(12,2) DEFAULT NULL;

-- 2. Add `created_at` alias column to bids (api.php expects it)
ALTER TABLE `bids`
  ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP NULL DEFAULT NULL;

-- 3. Backfill both columns from existing data
UPDATE `bids` SET `amount` = `bid_amount` WHERE `amount` IS NULL;
UPDATE `bids` SET `created_at` = `bid_time` WHERE `created_at` IS NULL;

-- 4. Add `sort_order` to news_announcements (api.php ticker expects it)
ALTER TABLE `news_announcements`
  ADD COLUMN IF NOT EXISTS `sort_order` INT DEFAULT 0;

-- 5. Sync sort_order from existing display_order values
UPDATE `news_announcements` SET `sort_order` = `display_order` WHERE `sort_order` = 0;

-- 6. Add cloudinary_public_id to auction_gallery (api.php expects it)
ALTER TABLE `auction_gallery`
  ADD COLUMN IF NOT EXISTS `cloudinary_public_id` VARCHAR(300) DEFAULT NULL;
