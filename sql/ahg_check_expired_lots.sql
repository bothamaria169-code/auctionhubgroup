-- ============================================================
-- AUCTION HUB GROUP — ahg_check_expired_lots.sql
-- READ-ONLY check to run in phpMyAdmin BEFORE deploying the new
-- auction-closing code or switching on the cron. Nothing here
-- changes data except the optional block at the bottom, which is
-- commented out.
--
-- Why: the old code never processed winners. Once the new files are
-- live, every lot still "active" past its end time is closed the first
-- time the cron runs (or someone opens it), and each winner whose bid
-- met the reserve is marked SOLD and EMAILED "you won".
--
-- Run each query separately (select it, then Go) and note the results.
-- ============================================================


-- 1) SUMMARY — how many lots are affected
SELECT
    COUNT(*)                                             AS expired_but_live,
    SUM(t.bid_count > 0)                                 AS with_bids,
    SUM(t.top_bid > 0 AND t.top_bid >= t.reserve_price)  AS would_be_sold_and_emailed,
    MIN(t.end_time)                                      AS oldest_end_time,
    MAX(t.end_time)                                      AS newest_end_time
FROM (
    SELECT a.id, a.end_time, COALESCE(a.reserve_price, 0) AS reserve_price,
           (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) AS bid_count,
           (SELECT MAX(COALESCE(b.bid_amount, b.amount)) FROM bids b
             WHERE b.auction_id = a.id AND b.is_winning = 1) AS top_bid
    FROM auctions a
    WHERE a.status = 'active' AND a.end_time <= NOW()
) t;


-- 2) THE LOTS THAT WOULD BE SOLD + WINNER EMAILED (same rule as the code:
--    the winning bid meets the reserve). Check every row.
SELECT a.id, a.item_name, a.end_time,
       DATEDIFF(NOW(), a.end_time)          AS days_since_end,
       a.reserve_price,
       COALESCE(b.bid_amount, b.amount)     AS winning_bid,
       b.bidder_number, u.full_name, u.email
FROM auctions a
JOIN bids b       ON b.auction_id = a.id AND b.is_winning = 1
LEFT JOIN users u ON u.id = b.user_id
WHERE a.status = 'active' AND a.end_time <= NOW()
  AND COALESCE(b.bid_amount, b.amount) >= COALESCE(a.reserve_price, 0)
ORDER BY a.end_time;


-- 3) EXPIRED LOTS WITH BIDS BELOW RESERVE (closed as "not sold"; they then
--    show under Admin → Auctions → Needs attention to sell or relist).
SELECT a.id, a.item_name, a.end_time, a.reserve_price,
       (SELECT MAX(COALESCE(b.bid_amount, b.amount)) FROM bids b WHERE b.auction_id = a.id) AS top_bid,
       (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) AS bids
FROM auctions a
WHERE a.status = 'active' AND a.end_time <= NOW()
  AND EXISTS (SELECT 1 FROM bids b WHERE b.auction_id = a.id)
  AND NOT EXISTS (SELECT 1 FROM bids b WHERE b.auction_id = a.id AND b.is_winning = 1
                  AND COALESCE(b.bid_amount, b.amount) >= COALESCE(a.reserve_price, 0))
ORDER BY a.end_time;


-- 4) EXPIRED LOTS WITH NO BIDS (just close quietly — no emails either way)
SELECT COUNT(*) AS expired_no_bids
FROM auctions a
WHERE a.status = 'active' AND a.end_time <= NOW()
  AND NOT EXISTS (SELECT 1 FROM bids b WHERE b.auction_id = a.id);


-- 5) LOTS ALREADY CLOSED AS SOLD BY THE OLD CODE BUT WITHOUT A PURCHASE
--    REQUEST (the old insert crashed, so these winners were never emailed).
--    Follow these up by hand; the new code does not re-send them.
SELECT a.id, a.item_name, a.sold_date, a.final_price, a.sold_to
FROM auctions a
WHERE a.is_sold = 1 AND a.sold_to_user_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM purchase_requests p WHERE p.auction_id = a.id)
ORDER BY a.sold_date DESC;


-- ============================================================
-- OPTIONAL — close OLD expired lots quietly (no emails, no "sold").
-- Use this for auctions that really finished long ago, so their bidders
-- are not emailed "you won" weeks later. Afterwards, sell or relist them
-- by hand from Admin → Auctions → Needs attention.
-- 1. Change 7 to the age (in days) you want to treat as "old".
-- 2. Remove the two dashes in front of UPDATE and run it on its own.
-- ============================================================
-- UPDATE auctions SET status = 'closed' WHERE status = 'active' AND end_time <= NOW() - INTERVAL 7 DAY;
