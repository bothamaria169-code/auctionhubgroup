<?php
// ============================================================
// AUCTION HUB GROUP — db_schema.php
// Tables added in Oct 2026 create themselves on first use, so a git
// deploy needs no SQL step. Same definitions as ahg_admin_activity_log.sql
// and ahg_user_features.sql (kept for manual use) — change both together.
// Requires: config.php (already loaded before this file)
// ============================================================

const AHG_SCHEMA_TABLES = [
    'admin_activity_log' => "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'watchlist' => "
        CREATE TABLE IF NOT EXISTS watchlist (
            user_id     INT      NOT NULL,
            auction_id  INT      NOT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, auction_id),
            INDEX idx_watch_auction (auction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'user_notifications' => "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'user_email_prefs' => "
        CREATE TABLE IF NOT EXISTS user_email_prefs (
            user_id             INT         NOT NULL PRIMARY KEY,
            outbid_emails       TINYINT(1)  NOT NULL DEFAULT 1,
            watch_emails        TINYINT(1)  NOT NULL DEFAULT 1,
            saved_search_emails TINYINT(1)  NOT NULL DEFAULT 1,
            unsubscribe_token   CHAR(32)    NOT NULL,
            updated_at          DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_prefs_token (unsubscribe_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'saved_searches' => "
        CREATE TABLE IF NOT EXISTS saved_searches (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            user_id       INT          NOT NULL,
            label         VARCHAR(150) NOT NULL,
            filters       TEXT         NOT NULL,
            last_sent_at  DATETIME     NULL,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_saved_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

/**
 * True when every table above exists, creating any that are missing.
 * Checked once per request; returns false (and logs why) if the database
 * user may not create tables — callers then skip the feature quietly.
 */
function ahgSchemaReady(): bool {
    global $pdo;
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $names = array_keys(AHG_SCHEMA_TABLES);
        $st = $pdo->prepare("
            SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (" . implode(',', array_fill(0, count($names), '?')) . ")
        ");
        $st->execute($names);
        $missing = array_diff($names, $st->fetchAll(PDO::FETCH_COLUMN));
        foreach ($missing as $table) {
            $pdo->exec(AHG_SCHEMA_TABLES[$table]);
            error_log("[AHG] db_schema: created table $table");
        }
        $ready = true;
    } catch (PDOException $e) {
        error_log('[AHG] db_schema: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}
