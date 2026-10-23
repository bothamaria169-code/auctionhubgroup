-- ============================================================
-- AUCTION HUB GROUP — ahg_admin_activity_log.sql
-- Change log for admin actions (mark sold, undo sold, price
-- changes, relist, delete). Safe to run more than once.
-- Run in phpMyAdmin on production BEFORE uploading the new
-- api.php / admin_dashboard.php. Without it, the dashboard still
-- works, but nothing is logged and "Relist" is refused (relist
-- stores the cleared bids here first).
-- ============================================================

CREATE TABLE IF NOT EXISTS admin_activity_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT          NULL,
    admin_name  VARCHAR(150) NULL,
    action      VARCHAR(50)  NOT NULL,
    auction_id  INT          NULL,
    item_name   VARCHAR(255) NULL,
    details     TEXT         NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_aal_created (created_at),
    INDEX idx_aal_auction (auction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
