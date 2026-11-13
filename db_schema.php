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
    // Admin → Stock Import: drafts from Facebook exports / branch uploads
    'stock_drafts' => "
        CREATE TABLE IF NOT EXISTS stock_drafts (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            source       VARCHAR(20)   NOT NULL,
            source_ref   VARCHAR(64)   NOT NULL,
            branch_id    INT           NULL,
            title        VARCHAR(255)  NOT NULL DEFAULT '',
            description  TEXT          NULL,
            year         INT           NULL,
            make_id      INT           NULL,
            mileage      INT           NULL,
            price        DECIMAL(12,2) NULL,
            photos       TEXT          NULL,
            posted_at    DATETIME      NULL,
            status       VARCHAR(12)   NOT NULL DEFAULT 'draft',
            auction_id   INT           NULL,
            created_by   INT           NULL,
            created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_draft_source (source, source_ref),
            INDEX idx_draft_status (status, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    // Website enquiry forms (Contact page, vehicle pages) — leads_helper.php
    'enquiries' => "
        CREATE TABLE IF NOT EXISTS enquiries (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            type         VARCHAR(20)   NOT NULL DEFAULT 'general',
            name         VARCHAR(150)  NOT NULL,
            email        VARCHAR(190)  NULL,
            phone        VARCHAR(30)   NULL,
            auction_id   INT           NULL,
            message      TEXT          NULL,
            source_page  VARCHAR(255)  NULL,
            attribution  TEXT          NULL,
            status       VARCHAR(12)   NOT NULL DEFAULT 'new',
            ip           VARCHAR(45)   NULL,
            created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_enq_created (created_at),
            INDEX idx_enq_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    // Admin → Leads: where each person is (one row per person, keyed e:email or p:last 9 digits)
    'lead_status' => "
        CREATE TABLE IF NOT EXISTS lead_status (
            lead_key     VARCHAR(200)  NOT NULL PRIMARY KEY,
            stage        VARCHAR(12)   NOT NULL DEFAULT 'new',
            outcome      VARCHAR(30)   NULL,
            note         TEXT          NULL,
            call_back_at DATE          NULL,
            emailed_at   DATETIME      NULL,
            called_at    DATETIME      NULL,
            calls        INT           NOT NULL DEFAULT 0,
            updated_by   VARCHAR(150)  NULL,
            updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_lead_stage (stage)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    // Every email / WhatsApp sent to a lead from the Leads page
    'lead_messages' => "
        CREATE TABLE IF NOT EXISTS lead_messages (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            lead_key     VARCHAR(200)  NOT NULL,
            channel      VARCHAR(10)   NOT NULL,
            template     VARCHAR(40)   NULL,
            recipient    VARCHAR(190)  NULL,
            subject      VARCHAR(255)  NULL,
            ok           TINYINT(1)    NOT NULL DEFAULT 1,
            sent_by      VARCHAR(150)  NULL,
            created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lm_lead (lead_key, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    // In-house finance applications and pre-auction (Buy Now) requests — finance_helper.php.
    // One row per application; the steps, quotation, documents and letters live in `details` (JSON).
    'client_applications' => "
        CREATE TABLE IF NOT EXISTS client_applications (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            kind          VARCHAR(12)   NOT NULL,
            token         CHAR(32)      NOT NULL,
            status        VARCHAR(24)   NOT NULL DEFAULT 'new',
            name          VARCHAR(150)  NOT NULL,
            email         VARCHAR(190)  NOT NULL,
            phone         VARCHAR(30)   NOT NULL,
            user_id       INT           NULL,
            auction_id    INT           NULL,
            vehicle       VARCHAR(255)  NULL,
            price         DECIMAL(12,2) NOT NULL DEFAULT 0,
            amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
            percent       DECIMAL(5,2)  NULL,
            months        INT           NULL,
            buyer_code    VARCHAR(30)   NULL,
            details       MEDIUMTEXT    NULL,
            ip            VARCHAR(45)   NULL,
            created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ca_token (token),
            UNIQUE KEY uq_ca_code (buyer_code),
            INDEX idx_ca_kind_status (kind, status),
            INDEX idx_ca_email (email),
            INDEX idx_ca_auction (auction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

// Columns added to existing tables (table => [column => ALTER statement])
const AHG_SCHEMA_COLUMNS = [
    'auctions' => [
        // How a sale happened: online / live / floor / pre_auction / private
        'sale_channel' => "ALTER TABLE auctions ADD COLUMN sale_channel VARCHAR(20) NULL AFTER sold_date",
    ],
    'users' => [
        // Bidders registered by staff for floor/live auctions: no login, no emails
        'is_floor_bidder' => "ALTER TABLE users ADD COLUMN is_floor_bidder TINYINT(1) NOT NULL DEFAULT 0",
    ],
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
        $cols = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        foreach (AHG_SCHEMA_COLUMNS as $table => $columns) {
            foreach ($columns as $column => $alter) {
                $cols->execute([$table, $column]);
                if (!$cols->fetchColumn()) {
                    $pdo->exec($alter);
                    error_log("[AHG] db_schema: added column $table.$column");
                }
            }
        }
        $ready = true;
    } catch (PDOException $e) {
        error_log('[AHG] db_schema: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}
