-- ============================================================
-- AUCTION HUB GROUP — ahg_user_features.sql
-- Watchlist, in-site alerts, email preferences, saved searches.
-- Safe to run more than once. Run in phpMyAdmin on production
-- BEFORE uploading the new PHP files.
-- ============================================================

-- Lots a user is watching (♥ Watch)
CREATE TABLE IF NOT EXISTS watchlist (
    user_id     INT      NOT NULL,
    auction_id  INT      NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, auction_id),
    INDEX idx_watch_auction (auction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bell / Alerts list. dedupe_key stops one-off alerts (watched lot went
-- live, ends in 1 hour, won, sold) from being sent twice; outbid alerts
-- leave it NULL so every outbid shows.
CREATE TABLE IF NOT EXISTS user_notifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    type        VARCHAR(30)  NOT NULL,
    auction_id  INT          NULL,
    title       VARCHAR(200) NOT NULL,
    message     VARCHAR(500) NULL,
    link        VARCHAR(255) NULL,
    is_read     TINYINT(1)   NOT NULL DEFAULT 0,
    dedupe_key  VARCHAR(100) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notif_dedupe (user_id, dedupe_key),
    INDEX idx_notif_user (user_id, is_read, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email switches (a missing row means everything is on).
-- unsubscribe_token powers the one-click links in emails.
CREATE TABLE IF NOT EXISTS user_email_prefs (
    user_id             INT         NOT NULL PRIMARY KEY,
    outbid_emails       TINYINT(1)  NOT NULL DEFAULT 1,
    watch_emails        TINYINT(1)  NOT NULL DEFAULT 1,
    saved_search_emails TINYINT(1)  NOT NULL DEFAULT 1,
    unsubscribe_token   CHAR(32)    NOT NULL,
    updated_at          DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prefs_token (unsubscribe_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Alert me" searches from the auctions page; one daily digest each
CREATE TABLE IF NOT EXISTS saved_searches (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT          NOT NULL,
    label         VARCHAR(150) NOT NULL,
    filters       TEXT         NOT NULL,
    last_sent_at  DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_saved_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
