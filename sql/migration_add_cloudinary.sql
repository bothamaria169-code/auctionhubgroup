-- ============================================================
-- PVA Migration: Add cloudinary_public_id columns
-- Run this once in phpMyAdmin → SQL tab
-- ============================================================

-- Add to auctions table (stores the main vehicle image public_id)
ALTER TABLE `auctions`
    ADD COLUMN `cloudinary_public_id` VARCHAR(255) NULL DEFAULT NULL
    AFTER `image_url`;

-- Add to auction_gallery table (stores each gallery image public_id)
ALTER TABLE `auction_gallery`
    ADD COLUMN `cloudinary_public_id` VARCHAR(255) NULL DEFAULT NULL
    AFTER `file_path`;

-- Verify
SELECT 'auctions columns:' AS info;
SHOW COLUMNS FROM `auctions` LIKE '%cloudinary%';
SHOW COLUMNS FROM `auctions` LIKE 'image_url';

SELECT 'auction_gallery columns:' AS info;
SHOW COLUMNS FROM `auction_gallery` LIKE '%cloudinary%';
SHOW COLUMNS FROM `auction_gallery` LIKE 'file_path';
