-- ============================================================
--  AHG — Fix RSS Feeds (replace dead feeds with working ones)
--  Run in cPanel → phpMyAdmin → auctionhubgroupc_db → SQL tab
--
--  Dead feeds (verified June 2026):
--   • Wheels24  — behind Cloudflare bot protection (403), merged into News24
--   • AutoTrader news-and-advice — HTML page, site has no RSS feed
--   • EIN Automotive — RSS requires a paid subscription (403)
--  Replacements (all verified working, all items have images):
--   • CAR Magazine SA, The Citizen Motoring, The South African Motoring
--
--  NOTE: also upload the updated api.php — it fixes the importer
--  (browser User-Agent fetch + a success-response bug that made
--  every import report failure even when it worked).
-- ============================================================

UPDATE rss_feeds SET name='CAR Magazine SA',
       feed_url='https://www.carmag.co.za/feed/', last_error=NULL
WHERE feed_url LIKE '%wheels24%';

UPDATE rss_feeds SET name='The Citizen Motoring',
       feed_url='https://www.citizen.co.za/category/motoring/feed/', last_error=NULL
WHERE feed_url LIKE '%autotrader%';

UPDATE rss_feeds SET name='The South African Motoring',
       feed_url='https://www.thesouthafrican.com/motoring/feed/', last_error=NULL
WHERE feed_url LIKE '%einnews%';

-- Insert any of the three that didn't exist to update
INSERT INTO rss_feeds (name, feed_url, category_id, fetch_interval_hours, is_active)
SELECT t.name, t.url,
       (SELECT id FROM news_categories WHERE name LIKE 'Industry%' LIMIT 1), 6, 1
FROM (
    SELECT 'CAR Magazine SA' AS name, 'https://www.carmag.co.za/feed/' AS url
    UNION ALL SELECT 'The Citizen Motoring', 'https://www.citizen.co.za/category/motoring/feed/'
    UNION ALL SELECT 'The South African Motoring', 'https://www.thesouthafrican.com/motoring/feed/'
    UNION ALL SELECT 'Autocar UK', 'https://www.autocar.co.uk/rss'
) t
WHERE NOT EXISTS (SELECT 1 FROM rss_feeds r WHERE r.feed_url = t.url);

-- Verify:
SELECT id, name, feed_url, is_active FROM rss_feeds;
