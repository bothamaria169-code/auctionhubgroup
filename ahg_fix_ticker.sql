-- ============================================================
--  AHG — Fix Announcement Ticker
--  Run in cPanel → phpMyAdmin → auctionhubgroupc_db → SQL tab
--
--  Problem: news_announcements table had garbled rows
--           (emoji stored as ??, · stored as ù) that
--           overrode the clean PHP fallback messages.
--  Fix:     Clear the table. PHP fallback messages take over.
--           Re-add via Admin Panel → News → Announcements
--           to get the ticker working with proper text.
-- ============================================================

TRUNCATE TABLE news_announcements;

-- Verify it's empty:
SELECT COUNT(*) AS rows_remaining FROM news_announcements;

-- Expected result: rows_remaining = 0
-- The PHP fallback messages in header.php will now be displayed.
