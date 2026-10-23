-- ============================================================
-- Auction Hub Group — Google Ads attribution columns
-- Run once in phpMyAdmin against auctionhubgroupc_db.
--
-- Adds the columns auth.php writes after a successful signup, so you
-- can answer "which keyword produced this bidder?" in the admin panel.
-- Safe to run on a live database: no existing data is touched.
-- ============================================================

ALTER TABLE users
  ADD COLUMN acq_source    VARCHAR(64)  NULL DEFAULT NULL COMMENT 'google-ads | organic | facebook | …'  AFTER bidder_status,
  ADD COLUMN acq_gclid     VARCHAR(255) NULL DEFAULT NULL COMMENT 'Google Ads click ID'                  AFTER acq_source,
  ADD COLUMN acq_campaign  VARCHAR(128) NULL DEFAULT NULL COMMENT 'utm_campaign'                          AFTER acq_gclid,
  ADD COLUMN acq_term      VARCHAR(128) NULL DEFAULT NULL COMMENT 'utm_term — the search keyword'        AFTER acq_campaign,
  ADD COLUMN acq_landing   VARCHAR(255) NULL DEFAULT NULL COMMENT 'first page of the visit'              AFTER acq_term,
  ADD COLUMN acq_raw       TEXT         NULL DEFAULT NULL COMMENT 'full attribution JSON'                AFTER acq_landing;

-- Reporting index: "show me every bidder Google Ads brought in"
CREATE INDEX idx_users_acq_source ON users (acq_source);
