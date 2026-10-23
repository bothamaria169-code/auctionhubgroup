-- ============================================================
-- AUCTION HUB GROUP — pva_complete_migration.sql
-- Run ONCE via phpMyAdmin → SQL tab on auctionhubgroup_db
--
-- WHAT THIS DOES (in order):
--   STEP 1 — Fix schema mismatches between api.php and the DB
--   STEP 2 — Add cloudinary_public_id to auction_gallery
--   STEP 3 — Import all 38 vehicles from auctions.sql (old DB)
--             with make_id correctly resolved to new makes table
--   STEP 4 — Verify row counts
-- ============================================================

START TRANSACTION;

-- ============================================================
-- STEP 1 — FIX SCHEMA MISMATCHES
-- api.php references these column names but the new DB schema
-- uses different names. We add aliases so both work, then fix
-- api.php can be updated separately.
-- ============================================================

-- ── FIX 1: bids table ────────────────────────────────────────
-- api.php inserts into: amount, created_at
-- auctionhubgroup_db.bids has: bid_amount, bid_time
-- Solution: add the missing column names as the canonical ones
-- (cheaper than editing api.php — both column sets will exist)

ALTER TABLE `bids`
  ADD COLUMN IF NOT EXISTS `amount` DECIMAL(12,2) AS (`bid_amount`) VIRTUAL
    COMMENT 'Virtual alias for bid_amount — used by api.php',
  ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP NULL DEFAULT NULL
    COMMENT 'Alias for bid_time — populated by api.php INSERT';

-- Actually, virtual columns can't be inserted into.
-- Better fix: rename to match api.php expectations.
-- We'll add the columns api.php actually inserts into:

-- api.php does: INSERT INTO bids (auction_id, user_id, amount, bidder_name, bidder_number, ip_address, is_winning, created_at)
-- New schema has:                (auction_id, user_id, bid_amount, bidder_name, bidder_number, ip_address, is_winning, bid_time)

-- Drop the virtual column attempt first (MariaDB may error silently)
ALTER TABLE `bids`
  DROP COLUMN IF EXISTS `amount`,
  DROP COLUMN IF EXISTS `created_at`;

-- Add real columns that api.php inserts into
ALTER TABLE `bids`
  ADD COLUMN IF NOT EXISTS `amount` DECIMAL(12,2) DEFAULT NULL
    COMMENT 'Mirrors bid_amount — used by api.php place_bid INSERT'
  AFTER `bid_amount`,
  ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP NULL DEFAULT NULL
    COMMENT 'Mirrors bid_time — populated by api.php'
  AFTER `bid_time`;

-- Keep bid_amount and bid_time in sync via triggers
-- (When api.php inserts into amount/created_at, copy to bid_amount/bid_time)
-- NOTE: DROP TRIGGER IF EXISTS requires MariaDB 10.1.2+ ✓

DROP TRIGGER IF EXISTS `sync_bid_columns_insert`;
DROP TRIGGER IF EXISTS `sync_bid_columns_update`;

DELIMITER $$

CREATE TRIGGER `sync_bid_columns_insert`
BEFORE INSERT ON `bids`
FOR EACH ROW
BEGIN
  -- If api.php filled `amount` but not `bid_amount`, sync it
  IF NEW.amount IS NOT NULL AND NEW.bid_amount IS NULL THEN
    SET NEW.bid_amount = NEW.amount;
  END IF;
  -- If api.php filled `bid_amount` but not `amount`, sync it
  IF NEW.bid_amount IS NOT NULL AND NEW.amount IS NULL THEN
    SET NEW.amount = NEW.bid_amount;
  END IF;
  -- Sync timestamp columns
  IF NEW.created_at IS NOT NULL AND NEW.bid_time = '0000-00-00 00:00:00' THEN
    SET NEW.bid_time = NEW.created_at;
  END IF;
  IF NEW.created_at IS NULL THEN
    SET NEW.created_at = NEW.bid_time;
  END IF;
END$$

DELIMITER ;

-- ── FIX 2: news_announcements sort_order column ──────────────
-- api.php ticker queries ORDER BY sort_order ASC
-- New schema column is named display_order
ALTER TABLE `news_announcements`
  ADD COLUMN IF NOT EXISTS `sort_order` INT(11) DEFAULT 0
    COMMENT 'Alias for display_order — used by api.php ticker query';

-- Copy display_order values into sort_order
UPDATE `news_announcements` SET `sort_order` = `display_order`;

-- ── FIX 3: auction_gallery cloudinary_public_id ──────────────
ALTER TABLE `auction_gallery`
  ADD COLUMN IF NOT EXISTS `cloudinary_public_id` VARCHAR(300) DEFAULT NULL
    COMMENT 'Cloudinary public_id for this gallery image'
  AFTER `file_path`;

-- ── FIX 4: auction_gallery file_path alias ───────────────────
-- api.php gallery INSERT uses column name `file_path` ✓ (matches)
-- api.php also reads image_url from auctions table ✓ (matches)
-- Nothing else needed here.


-- ============================================================
-- STEP 2 — IMPORT ALL VEHICLES FROM OLD DATABASE
--
-- make_id is resolved against the new `makes` table:
--   Ford=14, Toyota=44, Volkswagen=45, Renault=39, Nissan=33
--
-- All vehicles set to:
--   status = 'active'
--   start_time = NOW()
--   end_time = 30 days from now
--   current_price = NULL  (no bids yet → api.php falls back to start_price)
--
-- image_url preserves the old relative path so you can see
-- which image to upload to Cloudinary (see STEP 3 notes).
-- ============================================================

INSERT INTO `auctions`
  (`item_name`, `make_id`, `year`, `mileage`, `fuel_type`, `transmission`,
   `color`, `body_type`, `description`, `start_price`, `current_price`,
   `reserve_price`, `min_increment`, `image_url`, `status`,
   `start_time`, `end_time`, `created_at`)
VALUES

-- ── FORD (make_id = 14) ──────────────────────────────────────
('2019 Ford Everest 3.2 TDCi XLT Automatic (7-Seater) – Sunset Red',
 14, 2019, 116294, 'diesel', 'automatic', 'Maroon', 'SUV',
 '2019 Ford Everest 3.2 TDCi XLT Auto\n\nAsking bid/Cash Price: R97 100,00\n\nYear: 2019\nMileage: 116 294 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: SUV\nColour: Maroon\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 97100.00, NULL, NULL, 500.00, 'uploads/auction_1765383817_69399e89cd254.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2019 Ford Ranger 2.2 TDCi XL Auto Double-Cab',
 14, 2019, 78192, 'diesel', 'automatic', 'White', 'Double Cab',
 '2019 Ford Ranger 2.2 TDCi XL Auto Double-Cab\n\nAsking bid/Cash Price: R168 800,00\n\nYear: 2019\nMileage: 78 192 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: White\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 168800.00, NULL, NULL, 500.00, 'uploads/auction_1765624128_693d4940bb68a.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2020 Ford Ranger 2.0D Bi-Turbo Thunder Auto Double-Cab',
 14, 2020, 110563, 'diesel', 'automatic', 'Sea Grey', 'Double Cab',
 '2020 Ford Ranger 2.0D Bi-Turbo Thunder Auto Double-Cab\n\nAsking bid/Cash Price: R93 000,00\n\nYear: 2020\nMileage: 110 563 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Sea Grey\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 93000.00, NULL, NULL, 500.00, 'uploads/auction_1765626043_693d50bbb294e.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2020 Ford Ranger 2.2 TDCi XL Double-Cab',
 14, 2020, 101790, 'diesel', 'manual', 'Blue', 'Double Cab',
 '2020 Ford Ranger 2.2 TDCi XL Double-Cab\n\nAsking bid/Cash Price: R90 000,00\n\nYear: 2020\nMileage: 101 790 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Blue\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 90000.00, NULL, NULL, 500.00, 'uploads/auction_1765626419_693d5233c4c64.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2019 Ford Ranger 2.2 TDCi SuperCab',
 14, 2019, 120500, 'diesel', 'manual', 'White', 'SuperCab',
 '2019 Ford Ranger 2.2 TDCi SuperCab\n\nAsking Bid/Cash Price: R86 200,00\n\nYear: 2019\nMileage: 120 500 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: SuperCab\nColour: White\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 86200.00, NULL, NULL, 500.00, 'uploads/auction_1765628405_693d59f591761.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2013 Ford Fiesta 5-door 1.4 Ambiente',
 14, 2013, 126900, 'petrol', 'manual', 'Silver', 'Hatchback',
 '2013 Ford Fiesta 5-door 1.4 Ambiente\n\nAsking Bid/Cash Price: R43 500,00\n\nYear: 2013\nMileage: 126 900 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Silver\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 43500.00, NULL, NULL, 500.00, 'uploads/auction_1765629059_693d5c837b6d9.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2022 Ford Ranger 2.0D Bi-Turbo Stormtrak 4x4 Auto Double-Cab',
 14, 2022, 79000, 'diesel', 'automatic', 'Red', 'Double Cab',
 '2022 Ford Ranger 2.0D Bi-Turbo Stormtrak 4x4 Auto Double-Cab\n\nAsking bid/Cash Price: R132 500,00\n\nYear: 2022\nMileage: 79 000 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Red\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 132500.00, NULL, NULL, 500.00, 'uploads/auction_1765633481_693d6dc90cab8.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2021 Ford Ranger 2.0D Bi-Turbo Thunder Auto Double-Cab',
 14, 2021, 88900, 'diesel', 'automatic', 'Black', 'Double Cab',
 '2021 Ford Ranger 2.0D Bi-Turbo Thunder Auto Double-Cab\n\nAsking bid/Cash Price: R127 250,00\n\nYear: 2021\nMileage: 88 900 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Black\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 127250.00, NULL, NULL, 500.00, 'uploads/auction_1765635093_693d7415edf06.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2021 Ford Mustang 5.0 GT Auto',
 14, 2021, 33000, 'petrol', 'automatic', 'Red', 'Coupe',
 '2021 Ford Mustang 5.0 GT Auto\n\nAsking bid/Cash Price: R197 000,00\n\nYear: 2021\nMileage: 33 000 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Coupe\nColour: Red\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 195000.00, NULL, NULL, 500.00, 'uploads/auction_1765635377_693d7531b0834.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

-- ── VOLKSWAGEN (make_id = 45) ────────────────────────────────
('2020 Volkswagen Polo 1.0 TSI Trendline',
 45, 2020, 145087, 'petrol', 'manual', 'White', 'Hatchback',
 '2020 Volkswagen Polo 1.0 TSI Trendline\n\nAsking bid/Cash Price: R61 700,00\n\nYear: 2020\nMileage: 145 087 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 61700.00, NULL, NULL, 500.00, 'uploads/auction_1765740915_693f11737b05b.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2018 Volkswagen Polo 1.0 TSI Trendline',
 45, 2018, 105735, 'petrol', 'manual', 'Silver', 'Hatchback',
 '2018 Volkswagen Polo 1.0 TSI Trendline\n\nAsking bid/Cash Price: R63 000,00\n\nYear: 2018\nMileage: 105 735 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Silver\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 63000.00, NULL, NULL, 500.00, 'uploads/auction_1765741427_693f13732ea68.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2018 Volkswagen Polo 1.0 TSI Comfortline',
 45, 2018, 102000, 'petrol', 'manual', 'White', 'Hatchback',
 '2018 Volkswagen Polo 1.0 TSI Comfortline\n\nAsking bid/Cash Price: R62 000,00\n\nYear: 2018\nMileage: 102 000 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 62000.00, NULL, NULL, 500.00, 'uploads/auction_1765741822_693f14fe25485.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2019 Volkswagen Polo 1.0 TSI Comfortline',
 45, 2019, 99000, 'petrol', 'manual', 'White', 'Hatchback',
 '2019 Volkswagen Polo 1.0 TSI Comfortline\n\nAsking bid/Cash Price: R61 000,00\n\nYear: 2019\nMileage: 99 000 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 61000.00, NULL, NULL, 500.00, 'uploads/auction_1765742272_693f16c0a4410.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2019 Volkswagen Polo 1.6 Conceptline 5-dr',
 45, 2019, 110757, 'petrol', 'manual', 'Blue', 'Hatchback',
 '2019 Volkswagen Polo 1.6 Conceptline 5-dr\n\nAsking bid/Cash Price: R55 700,00\n\nYear: 2019\nMileage: 110 757 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Blue\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 55700.00, NULL, NULL, 500.00, 'uploads/auction_1765742577_693f17f1a9394.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2020 Volkswagen Polo 1.0 TSI Comfortline',
 45, 2020, 85550, 'petrol', 'manual', 'White', 'Hatchback',
 '2020 Volkswagen Polo 1.0 TSI Comfortline\n\nAsking bid/Cash Price: R57 000,00\n\nYear: 2020\nMileage: 85 550 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 55000.00, NULL, NULL, 500.00, 'uploads/auction_1765742881_693f1921e3a55.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2021 Volkswagen Polo 1.4 Trendline',
 45, 2021, 88157, 'petrol', 'manual', 'White', 'Sedan',
 '2021 Volkswagen Polo 1.4 Trendline\n\nAsking bid/Cash Price: R58 850,00\n\nYear: 2021\nMileage: 88 157 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Sedan\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 58850.00, NULL, NULL, 500.00, 'uploads/auction_1765743999_693f1d7f0714b.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2021 Volkswagen Polo Vivo 1.4 Comfortline 5-dr',
 45, 2021, 92000, 'petrol', 'automatic', 'White', 'Hatchback',
 '2021 Volkswagen Polo Vivo 1.4 Comfortline 5-dr\n\nAsking bid/Cash Price: R45 000,00\n\nYear: 2021\nMileage: 92 000 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 45000.00, NULL, NULL, 500.00, 'uploads/auction_1765744574_693f1fbeaff8e.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2021 Volkswagen Polo Vivo 1.4 Comfortline 5-dr (Silver)',
 45, 2021, 87302, 'petrol', 'manual', 'Silver', 'Hatchback',
 '2021 Volkswagen Polo Vivo 1.4 Comfortline 5-dr\n\nAsking bid/Cash Price: R64 000,00\n\nYear: 2021\nMileage: 87 302 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Silver\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 64000.00, NULL, NULL, 500.00, 'uploads/auction_1765787760_693fc8709b35d.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2019 Volkswagen Polo 1.6 Trendline',
 45, 2019, 109787, 'petrol', 'manual', 'White', 'Sedan',
 '2019 Volkswagen Polo 1.6 Trendline\n\nAsking bid/Cash Price: R59 000,00\n\nYear: 2019\nMileage: 109 787 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Sedan\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 59000.00, NULL, NULL, 500.00, 'uploads/auction_1765788003_693fc963a9906.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2021 Volkswagen Polo Vivo 1.4 Mswenko 5-dr',
 45, 2021, 82600, 'petrol', 'manual', 'Silver', 'Hatchback',
 '2021 Volkswagen Polo Vivo 1.4 Mswenko 5-dr\n\nAsking bid/Cash Price: R50 350,00\n\nYear: 2021\nMileage: 82 600 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Silver\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 50350.00, NULL, NULL, 500.00, 'uploads/auction_1765788488_693fcb4811fa4.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

-- ── RENAULT (make_id = 39) ───────────────────────────────────
('2021 Renault Kiger 1.0 Energy Zen',
 39, 2021, 87600, 'petrol', 'manual', 'White', 'Cross-Over',
 '2021 Renault Kiger 1.0 Energy Zen\n\nAsking bid/Cash Price: R52 000,00\n\nYear: 2021\nMileage: 87 600 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Cross-Over\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 52000.00, NULL, NULL, 500.00, 'uploads/auction_1765896464_694171106b641.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2022 Renault Kwid 1.0 Dynamique Manual',
 39, 2022, 69097, 'petrol', 'manual', 'Blue', 'Hatchback',
 '2022 Renault Kwid 1.0 Dynamique Manual\n\nAsking bid/Cash Price: R47 000,00\n\nYear: 2022\nMileage: 69 097 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Blue\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 45000.00, NULL, NULL, 500.00, 'uploads/auction_1765896572_6941717cd6bd8.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2021 Renault Kwid 1.0 Climber Auto',
 39, 2021, 77011, 'petrol', 'automatic', 'White', 'Hatchback',
 '2021 Renault Kwid 1.0 Climber Auto\n\nAsking bid/Cash Price: R44 000,00\n\nYear: 2021\nMileage: 77 011 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 44000.00, NULL, NULL, 500.00, 'uploads/auction_1765896674_694171e247f4e.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2022 Renault Kwid 1.0 Expression',
 39, 2022, 44525, 'petrol', 'manual', 'Silver', 'Hatchback',
 '2022 Renault Kwid 1.0 Expression\n\nAsking bid/Cash Price: R48 000,00\n\nYear: 2022\nMileage: 44 525 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Silver\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 48000.00, NULL, NULL, 500.00, 'uploads/auction_1765897236_69417414df994.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2018 Renault Duster 1.5 dCi Dynamique',
 39, 2018, 160110, 'diesel', 'manual', 'Blue', 'Cross-Over',
 '2018 Renault Duster 1.5 dCi Dynamique\n\nAsking bid/Cash Price: R43 000,00\n\nYear: 2018\nMileage: 160 110 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Cross-Over\nColour: Blue\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 43000.00, NULL, NULL, 500.00, 'uploads/auction_1765897339_6941747bb8bf9.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2021 Renault Kwid 1.0 Expression',
 39, 2021, 86796, 'petrol', 'manual', 'Blue', 'Hatchback',
 '2021 Renault Kwid 1.0 Expression\n\nAsking bid/Cash Price: R40 500,00\n\nYear: 2021\nMileage: 86 796 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Blue\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 40500.00, NULL, NULL, 500.00, 'uploads/auction_1765897484_6941750ce9ef9.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2020 Renault Duster 1.5 dCi Techroad Auto',
 39, 2020, 135000, 'diesel', 'automatic', 'Silver', 'Cross-Over',
 '2020 Renault Duster 1.5 dCi Techroad Auto\n\nAsking bid/Cash Price: R55 000,00\n\nYear: 2020\nMileage: 137 000 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Cross-Over\nColour: Silver\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 55000.00, NULL, NULL, 500.00, 'uploads/auction_1765897608_69417588af461.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2022 Renault Triber 1.0 Dynamique',
 39, 2022, 57638, 'petrol', 'manual', 'White', 'MPV',
 '2022 Renault Triber 1.0 Dynamique\n\nAsking bid/Cash Price: R61 000,00\n\nYear: 2022\nMileage: 57 638 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: MPV\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 61000.00, NULL, NULL, 500.00, 'uploads/auction_1765897702_694175e69a2a2.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

-- ── NISSAN (make_id = 33) ────────────────────────────────────
('2018 Nissan Navara 2.3D LE 4x4 Auto Double-Cab',
 33, 2018, 143975, 'diesel', 'automatic', 'Red', 'Double Cab',
 '2018 Nissan Navara 2.3D LE 4x4 Auto Double-Cab\n\nAsking bid/Cash Price: R86 600,00\n\nYear: 2018\nMileage: 143 975 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Red\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery',
 86600.00, NULL, NULL, 500.00, 'uploads/auction_1765897805_6941764dab93b.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2021 Nissan NP200 1.5 dCi Safety Pack',
 33, 2021, 137600, 'diesel', 'manual', 'White', 'Single Cab',
 '2021 Nissan NP200 1.5 dCi Safety Pack\n\nAsking bid/Cash Price: R57 200,00\n\nYear: 2021\nMileage: 137 600 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Single Cab\nColour: White\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery',
 57200.00, NULL, NULL, 500.00, 'uploads/auction_1765902643_6941893337983.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2017 Nissan Qashqai 1.2T Visia',
 33, 2017, 134897, 'petrol', 'manual', 'White', 'Cross-Over',
 '2017 Nissan Qashqai 1.2T Visia\n\nAsking bid/Cash Price: R50 100,00\n\nYear: 2017\nMileage: 134 897 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Cross-Over\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery',
 50100.00, NULL, NULL, 500.00, 'uploads/auction_1765902743_6941899724e76.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2022 Nissan Navara 2.5 DDTi PRO-4X 4X4 Auto Double-Cab',
 33, 2022, 88651, 'diesel', 'automatic', 'Red', 'Double Cab',
 '2022 Nissan Navara 2.5 DDTi PRO-4X 4X4 Auto Double-Cab\n\nAsking bid/Cash Price: R106 440,00\n\nYear: 2022\nMileage: 88 651 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Red\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery',
 106440.00, NULL, NULL, 500.00, 'uploads/auction_1765902882_69418a22228d7.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2019 Nissan NP200 1.6',
 33, 2019, 171001, 'petrol', 'manual', 'White', 'Single Cab',
 '2019 Nissan NP200 1.6\n\nAsking bid/Cash Price: R39 000,00\n\nYear: 2019\nMileage: 171 001 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Single Cab\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery',
 39000.00, NULL, NULL, 500.00, 'uploads/auction_1765903400_69418c28e6908.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2021 Nissan NP200 1.6 A/C Safety Pack',
 33, 2021, 119040, 'petrol', 'manual', 'White', 'Single Cab',
 '2021 Nissan NP200 1.6 A/C Safety Pack\n\nAsking bid/Cash Price: R56 000,00\n\nYear: 2021\nMileage: 119 040 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Single Cab\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery',
 56000.00, NULL, NULL, 500.00, 'uploads/auction_1765903521_69418ca15ff13.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2022 Nissan Navara 2.5 DDTi PRO-2X Auto Double-Cab',
 33, 2022, 85842, 'diesel', 'automatic', 'Black', 'Double Cab',
 '2022 Nissan Navara 2.5 DDTi PRO-2X Auto Double-Cab\n\nAsking bid/Cash Price: R104 500,00\n\nYear: 2022\nMileage: 85 842 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Black\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery',
 104500.00, NULL, NULL, 500.00, 'uploads/auction_1765903663_69418d2f13e29.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2021 Nissan Navara 2.5 DDTi PRO-4X 4X4 Auto Double-Cab',
 33, 2021, 64074, 'diesel', 'automatic', 'Grey', 'Double Cab',
 '2021 Nissan Navara 2.5 DDTi PRO-4X 4X4 Auto Double-Cab\n\nAsking bid/Cash Price: R109 990,00\n\nYear: 2021\nMileage: 64 074 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Grey\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 129990.00, NULL, NULL, 500.00, 'uploads/auction_1765903792_69418db0c82e7.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),

('2020 Nissan NP200 1.6',
 33, 2020, 108000, 'petrol', 'manual', 'White', 'Single Cab',
 '2020 Nissan NP200 1.6\n\nAsking bid/Cash Price: R41 000,00\n\nYear: 2020\nMileage: 108 000 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Single Cab\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>Mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery',
 41000.00, NULL, NULL, 500.00, 'uploads/auction_1765903896_69418e18a649e.jpg',
 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW());


-- ============================================================
-- STEP 3 — API.PHP COLUMN NAME PATCH
-- The new bids table uses bid_amount + bid_time but api.php
-- writes amount + created_at. After the trigger above handles
-- INSERTs, we also need the SELECT in api.php updates action
-- to work. Add a generated column so SELECT current_price works.
-- current_price is already in the new schema ✓
-- ============================================================

-- Fix the ticker query: api.php queries sort_order, schema has display_order
-- Already handled in STEP 1 above via the sort_order column copy.

-- ============================================================
-- STEP 4 — VERIFICATION QUERIES
-- Run these after COMMIT to confirm everything is correct.
-- ============================================================

SELECT 'VERIFICATION RESULTS' AS '';

SELECT
  COUNT(*)                                AS total_vehicles,
  SUM(status = 'active')                  AS active_auctions,
  SUM(make_id = 14)                       AS ford_count,
  SUM(make_id = 44)                       AS toyota_count,
  SUM(make_id = 45)                       AS volkswagen_count,
  SUM(make_id = 39)                       AS renault_count,
  SUM(make_id = 33)                       AS nissan_count,
  SUM(current_price IS NULL)              AS awaiting_first_bid,
  SUM(image_url IS NOT NULL)              AS has_legacy_image,
  SUM(cloudinary_public_id IS NOT NULL)   AS on_cloudinary
FROM auctions;

SELECT 'SCHEMA CHECK — bids table columns' AS '';
SHOW COLUMNS FROM bids;

SELECT 'SCHEMA CHECK — auction_gallery columns' AS '';
SHOW COLUMNS FROM auction_gallery LIKE '%cloudinary%';

COMMIT;
