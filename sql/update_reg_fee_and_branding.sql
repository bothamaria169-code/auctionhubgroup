-- ============================================================
-- AUCTION HUB GROUP — Server Deployment SQL
-- Run this file on the NEW cPanel server after uploading the
-- site files to update the registration fee and all branding
-- references stored in the database.
--
-- Usage (cPanel phpMyAdmin):
--   1. Select your database
--   2. Click the "SQL" tab
--   3. Paste this entire file and click "Go"
--
-- Usage (SSH / terminal):
--   mysql -u DB_USER -p DB_NAME < update_reg_fee_and_branding.sql
-- ============================================================

-- ── 1. Registration fee: R7,000 → R5,000 ────────────────────
--    Updates the site_settings table that drives live fee values
UPDATE site_settings SET setting_value = '5000'    WHERE setting_key = 'reg_fee_amount';
UPDATE site_settings SET setting_value = 'R5,000'  WHERE setting_key = 'reg_fee_label';

-- ── 2. Bank account name (if stored in site_settings) ────────
UPDATE site_settings SET setting_value = 'Auction Hub Group'
    WHERE setting_key = 'bank_account_name'
      AND setting_value IN ('Park Village Auctions', 'Auction Hub');

-- ── 3. Site name / company name (if stored in site_settings) ─
UPDATE site_settings SET setting_value = 'Auction Hub Group'
    WHERE setting_key IN ('site_name', 'company_name')
      AND setting_value LIKE '%Park Village%';

-- ── 4. Email addresses in site_settings ──────────────────────
UPDATE site_settings
    SET setting_value = REPLACE(setting_value, 'park-villageauctions.co.za', 'auctionhubgroup.co.za')
    WHERE setting_value LIKE '%park-villageauctions.co.za%';

UPDATE site_settings
    SET setting_value = REPLACE(setting_value, 'parkvillageauctions.co.za', 'auctionhubgroup.co.za')
    WHERE setting_value LIKE '%parkvillageauctions.co.za%';

-- ── 5. News / announcement ticker (if seeded in DB) ──────────
UPDATE news_announcements
    SET message = REPLACE(message, 'Park Village Auctions', 'Auction Hub Group')
    WHERE message LIKE '%Park Village Auctions%';

UPDATE news_announcements
    SET message = REPLACE(message, 'R7,000', 'R5,000')
    WHERE message LIKE '%R7,000%';

UPDATE news_announcements
    SET message = REPLACE(message, 'R7 000', 'R5 000')
    WHERE message LIKE '%R7 000%';

-- ── 5b. Ticker: AA-tested → Dekra Certified ──────────────────
UPDATE news_announcements
    SET message = '🏆 All Vehicles are Dekra Certified'
    WHERE message LIKE '%AA tested%'
       OR message LIKE '%AA-tested%';

-- ── 6. Auction event descriptions ────────────────────────────
UPDATE auction_events
    SET description = REPLACE(description, 'Park Village Auctions', 'Auction Hub Group')
    WHERE description LIKE '%Park Village Auctions%';

UPDATE auction_events
    SET description = REPLACE(description, 'R7,000', 'R5,000')
    WHERE description LIKE '%R7,000%';

-- ── 7. Pre-auction invoices (commitment fee context) ─────────
--    Do NOT touch vehicle prices — only update text/labels
UPDATE preauction_invoices
    SET notes = REPLACE(notes, 'Park Village Auctions', 'Auction Hub Group')
    WHERE notes LIKE '%Park Village Auctions%';

-- ── 8. Verify the changes were applied ───────────────────────
SELECT setting_key, setting_value
FROM   site_settings
WHERE  setting_key IN ('reg_fee_amount', 'reg_fee_label', 'bank_account_name', 'site_name', 'company_name')
ORDER  BY setting_key;
