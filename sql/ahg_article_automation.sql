-- ============================================================
-- AUCTION HUB GROUP — Article automation schema
-- Tags, post<->tag links, and the topic queue that feeds the
-- AI article generator (cron_generate_article.php).
--
-- Run once in phpMyAdmin (production DB: auctionhubgroupc_db).
-- The cron script ALSO auto-creates these tables if missing, so
-- running this by hand is optional — it just lets you seed topics.
-- ============================================================

-- ── Tags ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `news_tags` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(80)  NOT NULL,
  `slug`       VARCHAR(80)  NOT NULL UNIQUE,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Many-to-many: post <-> tag ───────────────────────────────
CREATE TABLE IF NOT EXISTS `news_post_tags` (
  `post_id` INT NOT NULL,
  `tag_id`  INT NOT NULL,
  PRIMARY KEY (`post_id`, `tag_id`),
  CONSTRAINT `fk_npt_post` FOREIGN KEY (`post_id`) REFERENCES `news_posts`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_npt_tag`  FOREIGN KEY (`tag_id`)  REFERENCES `news_tags`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Topic queue (feeds the generator) ────────────────────────
CREATE TABLE IF NOT EXISTS `article_topics` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `topic`       VARCHAR(255) NOT NULL,
  `primary_kw`  VARCHAR(160) DEFAULT NULL,
  `category_id` INT          DEFAULT NULL,
  `source`      VARCHAR(40)  NOT NULL DEFAULT 'manual',   -- manual | trends | inventory
  `trend_score` INT          NOT NULL DEFAULT 0,          -- higher = written sooner
  `status`      ENUM('queued','drafted','published','skipped') NOT NULL DEFAULT 'queued',
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `used_at`     TIMESTAMP    NULL DEFAULT NULL,
  INDEX `idx_status_score` (`status`, `trend_score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Starter topic queue ──────────────────────────────────────
-- category_id is resolved by slug at insert time where possible.
INSERT INTO `article_topics` (`topic`, `primary_kw`, `category_id`, `source`, `trend_score`)
SELECT t.topic, t.kw,
       (SELECT id FROM news_categories WHERE slug = t.cat_slug LIMIT 1),
       'manual', t.score
FROM (
  SELECT 'How to read a DEKRA condition report before you bid' AS topic,
         'DEKRA condition report' AS kw, 'buying-guides' AS cat_slug, 50 AS score
  UNION ALL SELECT 'Repossessed bakkies in South Africa: Hilux vs Ranger vs D-Max',
         'repossessed bakkies South Africa', 'buying-guides', 60
  UNION ALL SELECT 'What "voetstoots" really means at a car auction',
         'voetstoots meaning car auction', 'buying-guides', 40
  UNION ALL SELECT 'Auction vs dealership: the total cost compared',
         'car auction vs dealership cost', 'buying-guides', 45
  UNION ALL SELECT 'First-time bidder checklist for online car auctions in SA',
         'online car auction checklist South Africa', 'buying-guides', 55
  UNION ALL SELECT 'Cheapest reliable family cars at auction under R150k',
         'cheap family cars auction South Africa', 'buying-guides', 50
  UNION ALL SELECT 'How car finance works when buying at auction',
         'car finance auction South Africa', 'finance-tips', 45
  UNION ALL SELECT 'Province-by-province: where to collect your auction vehicle',
         'collect auction vehicle South Africa', 'buying-guides', 30
) AS t
WHERE NOT EXISTS (SELECT 1 FROM article_topics a WHERE a.topic = t.topic);
