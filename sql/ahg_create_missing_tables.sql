-- ============================================================
--  AHG — Create Missing Tables (Safe — uses IF NOT EXISTS)
--  Run in cPanel → phpMyAdmin → auctionhubgroupc_db → SQL tab
--  Safe to run multiple times — will not overwrite existing data.
-- ============================================================

-- ── auction_events ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `auction_events` (
  `id`            INT          AUTO_INCREMENT PRIMARY KEY,
  `title`         VARCHAR(255) NOT NULL,
  `description`   TEXT         DEFAULT NULL,
  `event_date`    DATE         DEFAULT NULL,
  `start_time`    DATETIME     DEFAULT NULL,
  `end_time`      DATETIME     DEFAULT NULL,
  `venue`         VARCHAR(255) DEFAULT NULL,
  `province`      VARCHAR(100) DEFAULT NULL,
  `bank_lot`      VARCHAR(100) DEFAULT NULL,
  `status`        ENUM('draft','upcoming','live','completed','cancelled') NOT NULL DEFAULT 'draft',
  `hero_image_url` VARCHAR(500) DEFAULT NULL,
  `cloudinary_public_id` VARCHAR(300) DEFAULT NULL,
  `notes`         TEXT         DEFAULT NULL,
  `created_by`    INT          DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── event_vehicles ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `event_vehicles` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `event_id`    INT NOT NULL,
  `auction_id`  INT NOT NULL,
  `lot_number`  VARCHAR(50)  DEFAULT NULL,
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `is_hot`      TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_event_auction` (`event_id`, `auction_id`),
  KEY `idx_event_id`   (`event_id`),
  KEY `idx_auction_id` (`auction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── csv_import_log ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `csv_import_log` (
  `id`            INT          AUTO_INCREMENT PRIMARY KEY,
  `filename`      VARCHAR(255) NOT NULL,
  `total_rows`    INT          NOT NULL DEFAULT 0,
  `imported`      INT          NOT NULL DEFAULT 0,
  `skipped`       INT          NOT NULL DEFAULT 0,
  `errors`        INT          NOT NULL DEFAULT 0,
  `error_detail`  TEXT         DEFAULT NULL,
  `admin_id`      INT          DEFAULT NULL,
  `imported_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── site_settings ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `site_settings` (
  `id`            INT          AUTO_INCREMENT PRIMARY KEY,
  `setting_key`   VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT         DEFAULT NULL,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default bank settings (only if table was just created / row doesn't exist)
INSERT IGNORE INTO `site_settings` (`setting_key`, `setting_value`) VALUES
  ('bank_name',         'FNB'),
  ('bank_account_name', 'Auction Hub Group'),
  ('bank_account_no',   '62 0000 00000'),
  ('bank_branch_code',  '250 555'),
  ('bank_account_type', 'Cheque/Current'),
  ('reg_fee_amount',    '5000.00'),
  ('reg_fee_label',     'R5,000');

-- ── testimonials ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `testimonials` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `author_name` VARCHAR(150) NOT NULL,
  `author_role` VARCHAR(150) DEFAULT NULL,
  `content`     TEXT         NOT NULL,
  `rating`      TINYINT(1)   NOT NULL DEFAULT 5,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── team_members ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `team_members` (
  `id`                   INT          AUTO_INCREMENT PRIMARY KEY,
  `name`                 VARCHAR(150) NOT NULL,
  `position`             VARCHAR(150) NOT NULL,
  `bio`                  TEXT         DEFAULT NULL,
  `email`                VARCHAR(200) DEFAULT NULL,
  `phone`                VARCHAR(50)  DEFAULT NULL,
  `photo_url`            VARCHAR(500) DEFAULT NULL,
  `cloudinary_public_id` VARCHAR(300) DEFAULT NULL,
  `is_active`            TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`           INT          NOT NULL DEFAULT 0,
  `created_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── refund_requests ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `refund_requests` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT          NOT NULL,
  `auction_id`  INT          DEFAULT NULL,
  `amount`      DECIMAL(12,2) DEFAULT NULL,
  `reason`      TEXT         DEFAULT NULL,
  `status`      ENUM('pending','under_review','approved','declined') NOT NULL DEFAULT 'pending',
  `admin_note`  TEXT         DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── popup_notifications ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `popup_notifications` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `title`       VARCHAR(255) NOT NULL,
  `message`     TEXT         NOT NULL,
  `type`        VARCHAR(50)  NOT NULL DEFAULT 'info',
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `start_at`    DATETIME     DEFAULT NULL,
  `end_at`      DATETIME     DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── bank_detail_requests ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bank_detail_requests` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT          NOT NULL,
  `reason`      TEXT         DEFAULT NULL,
  `status`      ENUM('pending','sent','declined') NOT NULL DEFAULT 'pending',
  `admin_note`  TEXT         DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── news_categories ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `news_categories` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `slug`        VARCHAR(100) NOT NULL UNIQUE,
  `color`       VARCHAR(20)  DEFAULT '#005BA1',
  `sort_order`  INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── news_posts ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `news_posts` (
  `id`           INT          AUTO_INCREMENT PRIMARY KEY,
  `category_id`  INT          DEFAULT NULL,
  `title`        VARCHAR(255) NOT NULL,
  `slug`         VARCHAR(255) NOT NULL UNIQUE,
  `excerpt`      TEXT         DEFAULT NULL,
  `body`         LONGTEXT     DEFAULT NULL,
  `image_url`    VARCHAR(500) DEFAULT NULL,
  `is_published` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_by`   INT          DEFAULT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── rss_feeds ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rss_feeds` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT          DEFAULT NULL,
  `name`        VARCHAR(150) NOT NULL,
  `url`         VARCHAR(500) NOT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `last_fetched` DATETIME    DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── news_articles ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `news_articles` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `title`       VARCHAR(255) NOT NULL,
  `content`     LONGTEXT     DEFAULT NULL,
  `source_url`  VARCHAR(500) DEFAULT NULL,
  `image_url`   VARCHAR(500) DEFAULT NULL,
  `is_published` TINYINT(1)  NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── newsletter_subscribers ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
  `id`            INT          AUTO_INCREMENT PRIMARY KEY,
  `email`         VARCHAR(200) NOT NULL UNIQUE,
  `full_name`     VARCHAR(150) DEFAULT NULL,
  `status`        ENUM('active','unsubscribed') NOT NULL DEFAULT 'active',
  `subscribed_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── newsletter_campaigns ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `newsletter_campaigns` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `subject`     VARCHAR(255) NOT NULL,
  `body`        LONGTEXT     DEFAULT NULL,
  `status`      ENUM('draft','sent') NOT NULL DEFAULT 'draft',
  `created_by`  INT          DEFAULT NULL,
  `sent_at`     DATETIME     DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── news_announcements (ticker) ───────────────────────────────
CREATE TABLE IF NOT EXISTS `news_announcements` (
  `id`          INT          AUTO_INCREMENT PRIMARY KEY,
  `message`     VARCHAR(500) NOT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── preauction_invoices (if not already created) ──────────────
CREATE TABLE IF NOT EXISTS `preauction_invoices` (
  `id`                    INT            AUTO_INCREMENT PRIMARY KEY,
  `invoice_number`        VARCHAR(50)    NOT NULL UNIQUE,
  `buyer_name`            VARCHAR(200)   NOT NULL,
  `buyer_email`           VARCHAR(200)   DEFAULT NULL,
  `buyer_phone`           VARCHAR(50)    DEFAULT NULL,
  `buyer_id_passport`     VARCHAR(100)   DEFAULT NULL,
  `buyer_address`         TEXT           DEFAULT NULL,
  `buyer_code`            VARCHAR(50)    DEFAULT NULL,
  `vehicle_description`   TEXT           NOT NULL,
  `vehicle_year`          YEAR           DEFAULT NULL,
  `vehicle_mileage`       VARCHAR(50)    DEFAULT NULL,
  `vehicle_transmission`  VARCHAR(50)    DEFAULT NULL,
  `vehicle_service_history` VARCHAR(10)  DEFAULT 'Yes',
  `vehicle_condition`     VARCHAR(50)    DEFAULT 'Used',
  `vehicle_body`          VARCHAR(50)    DEFAULT NULL,
  `vehicle_colour`        VARCHAR(50)    DEFAULT NULL,
  `vehicle_fuel`          VARCHAR(50)    DEFAULT NULL,
  `vehicle_features`      TEXT           DEFAULT NULL,
  `vehicle_image_url`     VARCHAR(500)   DEFAULT NULL,
  `total_price`           DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `commitment_fee_pct`    DECIMAL(5,2)   NOT NULL DEFAULT 35.00,
  `commitment_fee_amount` DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `outstanding_amount`    DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `status`                ENUM('pending','pop_uploaded','approved','declined') NOT NULL DEFAULT 'pending',
  `pop_file_path`         VARCHAR(500)   DEFAULT NULL,
  `pop_uploaded_at`       DATETIME       DEFAULT NULL,
  `upload_token`          VARCHAR(100)   DEFAULT NULL,
  `expires_at`            DATETIME       DEFAULT NULL,
  `declination_reason`    TEXT           DEFAULT NULL,
  `date_signed`           DATE           DEFAULT NULL,
  `notes`                 TEXT           DEFAULT NULL,
  `created_by`            INT            DEFAULT NULL,
  `created_at`            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Verify: show all tables ───────────────────────────────────
SHOW TABLES;
