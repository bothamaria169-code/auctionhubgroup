-- ============================================================
-- AUCTION HUB GROUP — cloudinary_migration.sql
-- Adds cloudinary_public_id columns to auctions + auction_gallery
--
-- Implements: Developer Guide § 3 (DB Schema Changes for Cloud Images)
--
-- Run ONCE via phpMyAdmin → SQL tab, or cPanel terminal:
--   mysql -u master_admin -p park_village_auctions < cloudinary_migration.sql
--
-- Safe to run multiple times — IF NOT EXISTS guards prevent errors.
-- ============================================================

-- ── 1. Add cloudinary_public_id to auctions (main image) ────

ALTER TABLE `auctions`
  ADD COLUMN IF NOT EXISTS `cloudinary_public_id` VARCHAR(300) DEFAULT NULL
    COMMENT 'Cloudinary public_id for main image — derive all sized URLs from this'
  AFTER `image_url`;


-- ── 2. Add cloudinary_public_id to auction_gallery ──────────

ALTER TABLE `auction_gallery`
  ADD COLUMN IF NOT EXISTS `cloudinary_public_id` VARCHAR(300) DEFAULT NULL
    COMMENT 'Cloudinary public_id for this gallery image'
  AFTER `image_url`;


-- ── 3. Index for fast lookups by public_id ───────────────────
--      (useful when re-generating URLs or cleaning up orphans)

ALTER TABLE `auctions`
  ADD INDEX IF NOT EXISTS `idx_cloudinary_public_id` (`cloudinary_public_id`(100));

ALTER TABLE `auction_gallery`
  ADD INDEX IF NOT EXISTS `idx_gallery_cloudinary_public_id` (`cloudinary_public_id`(100));


-- ── 4. Verify the changes ────────────────────────────────────
--      Run these SELECTs after the ALTER statements to confirm.

-- Should show image_url + cloudinary_public_id columns:
SHOW COLUMNS FROM `auctions`       LIKE '%image%';
SHOW COLUMNS FROM `auction_gallery` LIKE '%image%';
SHOW COLUMNS FROM `auctions`       LIKE '%cloudinary%';
SHOW COLUMNS FROM `auction_gallery` LIKE '%cloudinary%';
