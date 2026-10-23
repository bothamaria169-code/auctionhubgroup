-- ============================================================
--  AHG — Branches Migration (fixes preauction.php white screen)
--  Run in cPanel → phpMyAdmin → auctionhubgroupc_db → SQL tab
--
--  Problem: preauction.php queries the `branches` table and
--           `auctions.branch_id` column. Both exist locally but
--           were never added to any production SQL file, so the
--           live page dies with a fatal PDOException right after
--           the header → white screen.
--  Fix:     Create `branches`, seed the 5 branch rows, and add
--           `branch_id` to `auctions`. Safe to re-run (idempotent).
-- ============================================================

CREATE TABLE IF NOT EXISTS `branches` (
  `id`             TINYINT(3) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(80)  NOT NULL,
  `city`           VARCHAR(60)  NOT NULL,
  `province`       VARCHAR(60)  NOT NULL,
  `address`        VARCHAR(200) DEFAULT NULL,
  `image_file`     VARCHAR(120) DEFAULT NULL,
  `is_head_office` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `sort_order`     TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `branches`
  (`id`, `name`, `city`, `province`, `address`, `image_file`, `is_head_office`, `sort_order`)
VALUES
  (1, 'Head Office',         'Centurion',      'Gauteng',       '28 Jakaranda Street, Centurion, 0157', NULL, 1, 1),
  (2, 'Boksburg Branch',     'Boksburg',       'Gauteng',       'Boksburg Industrial Area, Boksburg',   NULL, 0, 2),
  (3, 'Durban Branch',       'Durban',         'KwaZulu-Natal', 'Durban South Industrial, Durban',      NULL, 0, 3),
  (4, 'Bloemfontein Branch', 'Bloemfontein',   'Free State',    'Bloemfontein Industrial Park',         NULL, 0, 4),
  (5, 'Eastern Cape Branch', 'Port Elizabeth', 'Eastern Cape',  'Port Elizabeth Industrial Zone',       NULL, 0, 5);

-- Add auctions.branch_id only if it doesn't exist yet
-- (works on both MySQL and MariaDB)
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'auctions'
    AND COLUMN_NAME  = 'branch_id'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `auctions` ADD COLUMN `branch_id` TINYINT(3) UNSIGNED NULL DEFAULT NULL',
  'SELECT ''branch_id already exists'' AS info');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verify:
SELECT COUNT(*) AS branches_rows FROM `branches`;
SHOW COLUMNS FROM `auctions` LIKE 'branch_id';
