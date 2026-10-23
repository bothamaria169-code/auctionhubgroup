-- ============================================================
-- Auction Hub Group — registration deposit R5,000 → R10,000
-- Run once in phpMyAdmin against auctionhubgroupc_db.
--
-- site_settings OVERRIDES the REG_FEE_* constants in config.php, so
-- uploading the PHP files alone is not enough — the site would keep
-- showing R5,000 until these two rows are updated.
-- ============================================================

UPDATE site_settings SET setting_value = '10000'   WHERE setting_key = 'reg_fee_amount';
UPDATE site_settings SET setting_value = 'R10,000' WHERE setting_key = 'reg_fee_label';

-- Verify — both rows should come back with the new values.
SELECT setting_key, setting_value
FROM   site_settings
WHERE  setting_key IN ('reg_fee_amount', 'reg_fee_label');
