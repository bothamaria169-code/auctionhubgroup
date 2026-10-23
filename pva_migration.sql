-- ============================================================
-- AUCTION HUB GROUP — pva_migration.sql
-- Run this ONCE via phpMyAdmin → SQL tab on your NEW database
-- (auctionhubgroup_db)
--
-- What this script does:
--   PART 1 — Fix the bidding system (adds missing columns)
--   PART 2 — Bulk import all vehicles from the old database
--   PART 3 — Reset all auction times to sensible future dates
-- ============================================================

-- ── SAFETY: Run inside a transaction so you can roll back ────
START TRANSACTION;

-- ============================================================
-- PART 1 — FIX BIDDING SYSTEM
-- The old schema was missing current_price, reserve_price,
-- body_type, engine_size, vin_number, condition_grade,
-- service_history columns that your new api.php expects.
-- ============================================================

-- Add current_price (tracks live bid price)
ALTER TABLE `auctions`
  ADD COLUMN IF NOT EXISTS `current_price` DECIMAL(12,2) DEFAULT NULL
    COMMENT 'Live current highest bid — NULL means no bids yet'
  AFTER `start_price`;

-- Add reserve_price
ALTER TABLE `auctions`
  ADD COLUMN IF NOT EXISTS `reserve_price` DECIMAL(12,2) DEFAULT NULL
    COMMENT 'Reserve price — bid must meet this to sell'
  AFTER `current_price`;

-- Add body_type
ALTER TABLE `auctions`
  ADD COLUMN IF NOT EXISTS `body_type` VARCHAR(50) DEFAULT NULL
  AFTER `color`;

-- Add engine_size
ALTER TABLE `auctions`
  ADD COLUMN IF NOT EXISTS `engine_size` VARCHAR(20) DEFAULT NULL
  AFTER `body_type`;

-- Add vin_number
ALTER TABLE `auctions`
  ADD COLUMN IF NOT EXISTS `vin_number` VARCHAR(50) DEFAULT NULL
  AFTER `engine_size`;

-- Add condition_grade
ALTER TABLE `auctions`
  ADD COLUMN IF NOT EXISTS `condition_grade` VARCHAR(10) DEFAULT NULL
  AFTER `vin_number`;

-- Add service_history flag
ALTER TABLE `auctions`
  ADD COLUMN IF NOT EXISTS `service_history` TINYINT(1) DEFAULT 1
  AFTER `condition_grade`;

-- Add cloudinary_public_id (from cloudinary_migration.sql)
ALTER TABLE `auctions`
  ADD COLUMN IF NOT EXISTS `cloudinary_public_id` VARCHAR(300) DEFAULT NULL
    COMMENT 'Cloudinary public_id for main image'
  AFTER `image_url`;

-- Add index on cloudinary_public_id
ALTER TABLE `auctions`
  ADD INDEX IF NOT EXISTS `idx_cloudinary_public_id` (`cloudinary_public_id`(100));

-- ── FIX: Seed current_price from start_price for all existing rows ──
-- (Rows with no bids should have current_price = NULL so api.php
--  falls back to start_price correctly)
UPDATE `auctions`
SET `current_price` = NULL
WHERE `current_price` IS NULL;

-- ============================================================
-- PART 2 — BULK IMPORT VEHICLES FROM OLD DATABASE
--
-- Source: auctions.sql (momentum_auction database)
-- All IDs are re-mapped (AUTO_INCREMENT) so no PK conflicts.
-- Old image_url paths are kept as-is — you can Cloudinary-migrate
-- them later using cloudinary_helper.php.
--
-- Columns mapped:
--   old.item_name        → new.item_name
--   old.make_id          → new.make_id (NULL — makes table differs)
--   old.description      → new.description
--   old.start_price      → new.start_price, current_price=NULL
--   old.min_increment    → new.min_increment
--   old.image_url        → new.image_url
--   old.year             → new.year
--   old.mileage          → new.mileage
--   old.fuel_type        → new.fuel_type
--   old.transmission     → new.transmission
--   old.color            → new.color
--   old.auction_type     → new.auction_type
--   old.status           → set to 'active'
--   old.start_time       → reset to NOW()
--   old.end_time         → reset to 30 days from now
-- ============================================================

INSERT INTO `auctions`
    (`item_name`, `make_id`, `description`, `start_price`, `current_price`,
     `min_increment`, `image_url`, `year`, `mileage`, `fuel_type`,
     `transmission`, `color`, `auction_type`, `status`,
     `start_time`, `end_time`, `created_at`)
VALUES
-- Ford vehicles (IDs 7-15 from old DB)
('2019 Ford Everest 3.2 TDCi XLT Auto', NULL, '2019 Ford Everest 3.2 TDCi XLT Auto\n\nAsking bid/Cash Price: R97 100,00\n\nYear: 2019\nMileage: 116 294 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: SUV\nColour: Maroon\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 97100.00, NULL, 500.00, 'uploads/auction_1765383817_69399e89cd254.jpg', 2019, 116294, 'diesel', 'automatic', 'Maroon', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2019 Ford Ranger 2.2 TDCi XL Auto Double-Cab', NULL, '2019 Ford Ranger 2.2 TDCi XL Auto Double-Cab\n\nAsking bid/Cash Price: R168 800,00\n\nYear: 2019\nMileage: 78 192 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: White\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 168800.00, NULL, 500.00, 'uploads/auction_1765624128_693d4940bb68a.jpg', 2019, 78192, 'diesel', 'automatic', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2020 Ford Ranger 2.0D Bi-Turbo Thunder Auto Double-Cab', NULL, '2020 Ford Ranger 2.0D Bi-Turbo Thunder Auto Double-Cab\n\nAsking bid/Cash Price: R93 000,00\n\nYear: 2020\nMileage: 110 563 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Sea Grey\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 93000.00, NULL, 500.00, 'uploads/auction_1765626043_693d50bbb294e.jpg', 2020, 110563, 'diesel', 'automatic', 'Sea Grey', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2020 Ford Ranger 2.2 TDCi XL Double-Cab', NULL, '2020 Ford Ranger 2.2 TDCi XL Double-Cab\n\nAsking bid/Cash Price: R90 000,00\n\nYear: 2020\nMileage: 101 790 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Blue\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 90000.00, NULL, 500.00, 'uploads/auction_1765626419_693d5233c4c64.jpg', 2020, 101790, 'diesel', 'manual', 'Blue', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2019 Ford Ranger 2.2 TDCi SuperCab', NULL, '2019 Ford Ranger 2.2 TDCi SuperCab\n\nAsking Bid/Cash Price: R86 200,00\n\nYear: 2019\nMileage: 120 500 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: SuperCab\nColour: White\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 86200.00, NULL, 500.00, 'uploads/auction_1765628405_693d59f591761.jpg', 2019, 120500, 'diesel', 'manual', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2013 Ford Fiesta 5-door 1.4 Ambiente', NULL, '2013 Ford Fiesta 5-door 1.4 Ambiente\n\nAsking Bid/Cash Price: R43 500,00\n\nYear: 2013\nMileage: 126 900 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Silver\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 43500.00, NULL, 500.00, 'uploads/auction_1765629059_693d5c837b6d9.jpg', 2013, 126900, 'petrol', 'manual', 'Silver', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2022 Ford Ranger 2.0D Bi-Turbo Stormtrak 4x4 Auto Double-Cab', NULL, '2022 Ford Ranger 2.0D Bi-Turbo Stormtrak 4x4 Auto Double-Cab\n\nAsking bid/Cash Price: R132 500,00\n\nYear: 2022\nMileage: 79 000 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Red\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 132500.00, NULL, 500.00, 'uploads/auction_1765633481_693d6dc90cab8.jpg', 2022, 79000, 'diesel', 'automatic', 'Red', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2021 Ford Ranger 2.0D Bi-Turbo Thunder Auto Double-Cab', NULL, '2021 Ford Ranger 2.0D Bi-Turbo Thunder Auto Double-Cab\n\nAsking bid/Cash Price: R127 250,00\n\nYear: 2021\nMileage: 88 900 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Black\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 127250.00, NULL, 500.00, 'uploads/auction_1765635093_693d7415edf06.jpg', 2021, 88900, 'diesel', 'automatic', 'Black', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2021 Ford Mustang 5.0 GT Auto', NULL, '2021 Ford Mustang 5.0 GT Auto\n\nAsking bid/Cash Price: R197 000,00\n\nYear: 2021\nMileage: 33 000 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Coupe\nColour: Red\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 195000.00, NULL, 500.00, 'uploads/auction_1765635377_693d7531b0834.jpg', 2021, 33000, 'petrol', 'automatic', 'Red', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
-- Toyota vehicles
('2021 Toyota Hilux 2.4 GD-6 RB SRX Double-Cab', NULL, '2021 Toyota Hilux 2.4 GD-6 RB SRX Double-Cab\n\nAsking bid/Cash Price: R155 000,00\n\nYear: 2021\nMileage: 65 000 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: White\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 155000.00, NULL, 500.00, NULL, 2021, 65000, 'diesel', 'manual', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
-- VW Polo batch
('2020 Volkswagen Polo 1.0 TSI Trendline', NULL, '2020 Volkswagen Polo 1.0 TSI Trendline\n\nAsking bid/Cash Price: R61 700,00\n\nYear: 2020\nMileage: 145 087 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 61700.00, NULL, 500.00, 'uploads/auction_1765740915_693f11737b05b.jpg', 2020, 145087, 'petrol', 'manual', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2018 Volkswagen Polo 1.0 TSI Trendline', NULL, '2018 Volkswagen Polo 1.0 TSI Trendline\n\nAsking bid/Cash Price: R63 000,00\n\nYear: 2018\nMileage: 105 735 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Silver\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 63000.00, NULL, 500.00, 'uploads/auction_1765741427_693f13732ea68.jpg', 2018, 105735, 'petrol', 'manual', 'Silver', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2018 Volkswagen Polo 1.0 TSI Comfortline', NULL, '2018 Volkswagen Polo 1.0 TSI Comfortline\n\nAsking bid/Cash Price: R62 000,00\n\nYear: 2018\nMileage: 102 000 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 62000.00, NULL, 500.00, 'uploads/auction_1765741822_693f14fe25485.jpg', 2018, 102000, 'petrol', 'manual', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2019 Volkswagen Polo 1.0 TSI Comfortline', NULL, '2019 Volkswagen Polo 1.0 TSI Comfortline\n\nAsking bid/Cash Price: R61 000,00\n\nYear: 2019\nMileage: 99 000 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 61000.00, NULL, 500.00, 'uploads/auction_1765742272_693f16c0a4410.jpg', 2019, 99000, 'petrol', 'manual', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2019 Volkswagen Polo 1.6 Conceptline 5-dr', NULL, '2019 Volkswagen Polo 1.6 Conceptline 5-dr\n\nAsking bid/Cash Price: R55 700,00\n\nYear: 2019\nMileage: 110 757 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Blue\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 55700.00, NULL, 500.00, 'uploads/auction_1765742577_693f17f1a9394.jpg', 2019, 110757, 'petrol', 'manual', 'Blue', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2020 Volkswagen Polo 1.0 TSI Comfortline', NULL, '2020 Volkswagen Polo 1.0 TSI Comfortline\n\nAsking bid/Cash Price: R57 000,00\n\nYear: 2020\nMileage: 85 550 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 55000.00, NULL, 500.00, 'uploads/auction_1765742881_693f1921e3a55.jpg', 2020, 85550, 'petrol', 'manual', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2021 Volkswagen Polo 1.4 Trendline', NULL, '2021 Volkswagen Polo 1.4 Trendline\n\nAsking bid/Cash Price: R58 850,00\n\nYear: 2021\nMileage: 88 157 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Sedan\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 58850.00, NULL, 500.00, 'uploads/auction_1765743999_693f1d7f0714b.jpg', 2021, 88157, 'petrol', 'manual', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2021 Volkswagen Polo Vivo 1.4 Comfortline 5-dr', NULL, '2021 Volkswagen Polo Vivo 1.4 Comfortline 5-dr\n\nAsking bid/Cash Price: R45 000,00\n\nYear: 2021\nMileage: 92 000 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 45000.00, NULL, 500.00, 'uploads/auction_1765744574_693f1fbeaff8e.jpg', 2021, 92000, 'petrol', 'automatic', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2021 Volkswagen Polo Vivo 1.4 Comfortline 5-dr (Silver)', NULL, '2021 Volkswagen Polo Vivo 1.4 Comfortline 5-dr\n\nAsking bid/Cash Price: R64 000,00\n\nYear: 2021\nMileage: 87 302 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Silver\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 64000.00, NULL, 500.00, 'uploads/auction_1765787760_693fc8709b35d.jpg', 2021, 87302, 'petrol', 'manual', 'Silver', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2019 Volkswagen Polo 1.6 Trendline', NULL, '2019 Volkswagen Polo 1.6 Trendline\n\nAsking bid/Cash Price: R59 000,00\n\nYear: 2019\nMileage: 109 787 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Sedan\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 59000.00, NULL, 500.00, 'uploads/auction_1765788003_693fc963a9906.jpg', 2019, 109787, 'petrol', 'manual', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2021 Volkswagen Polo Vivo 1.4 Mswenko 5-dr', NULL, '2021 Volkswagen Polo Vivo 1.4 Mswenko 5-dr\n\nAsking bid/Cash Price: R50 350,00\n\nYear: 2021\nMileage: 82 600 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Silver\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 50350.00, NULL, 500.00, 'uploads/auction_1765788488_693fcb4811fa4.jpg', 2021, 82600, 'petrol', 'manual', 'Silver', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
-- Renault batch
('2021 Renault Kiger 1.0 Energy Zen', NULL, '2021 Renault Kiger 1.0 Energy Zen\n\nAsking bid/Cash Price: R52 000,00\n\nYear: 2021\nMileage: 87 600 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Cross-Over\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 52000.00, NULL, 500.00, 'uploads/auction_1765896464_694171106b641.jpg', 2021, 87600, 'petrol', 'manual', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2022 Renault Kwid 1.0 Dynamique Manual', NULL, '2022 Renault Kwid 1.0 Dynamique Manual\n\nAsking bid/Cash Price: R47 000,00\n\nYear: 2022\nMileage: 69 097 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Blue\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 45000.00, NULL, 500.00, 'uploads/auction_1765896572_6941717cd6bd8.jpg', 2022, 69097, 'petrol', 'manual', 'Blue', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2021 Renault Kwid 1.0 Climber Auto', NULL, '2021 Renault Kwid 1.0 Climber Auto\n\nAsking bid/Cash Price: R44 000,00\n\nYear: 2021\nMileage: 77 011 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 44000.00, NULL, 500.00, 'uploads/auction_1765896674_694171e247f4e.jpg', 2021, 77011, 'petrol', 'automatic', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2022 Renault Kwid 1.0 Expression', NULL, '2022 Renault Kwid 1.0 Expression\n\nAsking bid/Cash Price: R48 000,00\n\nYear: 2022\nMileage: 44 525 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Silver\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 48000.00, NULL, 500.00, 'uploads/auction_1765897236_69417414df994.jpg', 2022, 44525, 'petrol', 'manual', 'Silver', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2018 Renault Duster 1.5 dCi Dynamique', NULL, '2018 Renault Duster 1.5 dCi Dynamique\n\nAsking bid/Cash Price: R43 000,00\n\nYear: 2018\nMileage: 160 110 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Cross-Over\nColour: Blue\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 43000.00, NULL, 500.00, 'uploads/auction_1765897339_6941747bb8bf9.jpg', 2018, 160110, 'diesel', 'manual', 'Blue', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2021 Renault Kwid 1.0 Expression', NULL, '2021 Renault Kwid 1.0 Expression\n\nAsking bid/Cash Price: R40 500,00\n\nYear: 2021\nMileage: 86 796 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Hatchback\nColour: Blue\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 40500.00, NULL, 500.00, 'uploads/auction_1765897484_6941750ce9ef9.jpg', 2021, 86796, 'petrol', 'manual', 'Blue', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2020 Renault Duster 1.5 dCi Techroad Auto', NULL, '2020 Renault Duster 1.5 dCi Techroad Auto\n\nAsking bid/Cash Price: R55 000,00\n\nYear: 2020\nMileage: 137 000 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Cross-Over\nColour: Silver\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 55000.00, NULL, 500.00, 'uploads/auction_1765897608_69417588af461.jpg', 2020, 135000, 'diesel', 'automatic', 'Silver', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2022 Renault Triber 1.0 Dynamique', NULL, '2022 Renault Triber 1.0 Dynamique\n\nAsking bid/Cash Price: R61 000,00\n\nYear: 2022\nMileage: 57 638 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: MVP\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 61000.00, NULL, 500.00, 'uploads/auction_1765897702_694175e69a2a2.jpg', 2022, 57638, 'petrol', 'manual', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
-- Nissan batch
('2018 Nissan Navara 2.3D LE 4x4 Auto Double-Cab', NULL, '2018 Nissan Navara 2.3D LE 4x4 Auto Double-Cab\n\nAsking bid/Cash Price: R86 600,00\n\nYear: 2018\nMileage: 143 975 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Red\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery', 86600.00, NULL, 500.00, 'uploads/auction_1765897805_6941764dab93b.jpg', 2018, 143975, 'diesel', 'automatic', 'Red', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2021 Nissan NP200 1.5 dCi Safety Pack', NULL, '2021 Nissan NP200 1.5 dCi Safety Pack\n\nAsking bid/Cash Price: R57 200,00\n\nYear: 2021\nMileage: 137 600 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Single Cab\nColour: White\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery', 57200.00, NULL, 500.00, 'uploads/auction_1765902643_6941893337983.jpg', 2021, 137600, 'diesel', 'manual', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2017 Nissan Qashqai 1.2T Visia', NULL, '2017 Nissan Qashqai 1.2T Visia\n\nAsking bid/Cash Price: R50 100,00\n\nYear: 2017\nMileage: 134 897 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Cross-Over\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery', 50100.00, NULL, 500.00, 'uploads/auction_1765902743_6941899724e76.jpg', 2017, 134897, 'petrol', 'manual', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2022 Nissan Navara 2.5 DDTi PRO-4X 4X4 Auto Double-Cab', NULL, '2022 Nissan Navara 2.5 DDTi PRO-4X 4X4 Auto Double-Cab\n\nAsking bid/Cash Price: R106 440\n\nYear: 2022\nMileage: 88 651 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Red\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery', 106440.00, NULL, 500.00, 'uploads/auction_1765902882_69418a22228d7.jpg', 2022, 88651, 'diesel', 'automatic', 'Red', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2019 Nissan NP200 1.6', NULL, '2019 Nissan NP200 1.6\n\nAsking bid/Cash Price: R39 000,00\n\nYear: 2019\nMileage: 171 001 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Single Cab\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery', 39000.00, NULL, 500.00, 'uploads/auction_1765903400_69418c28e6908.jpg', 2019, 171001, 'petrol', 'manual', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2021 Nissan NP200 1.6 A/C Safety Pack', NULL, '2021 Nissan NP200 1.6 A/C Safety Pack\n\nAsking bid/Cash Price: R56 000,00\n\nYear: 2021\nMileage: 119 040 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Single Cab\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery', 56000.00, NULL, 500.00, 'uploads/auction_1765903521_69418ca15ff13.jpg', 2021, 119040, 'petrol', 'manual', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2022 Nissan Navara 2.5 DDTi PRO-2X Auto Double-Cab', NULL, '2022 Nissan Navara 2.5 DDTi PRO-2X Auto Double-Cab\n\nAsking bid/Cash Price: R104 500,00\n\nYear: 2022\nMileage: 85 842 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Black\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery', 104500.00, NULL, 500.00, 'uploads/auction_1765903663_69418d2f13e29.jpg', 2022, 85842, 'diesel', 'automatic', 'Black', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2021 Nissan Navara 2.5 DDTi PRO-4X 4X4 Auto Double-Cab', NULL, '2021 Nissan Navara 2.5 DDTi PRO-4X 4X4 Auto Double-Cab\n\nAsking bid/Cash Price: R109 990,00\n\nYear: 2021\nMileage: 64 074 km\nTransmission: Automatic\nService History: Yes\nCondition: Used\nBody: Double Cab\nColour: Grey\nFuel: Diesel\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 129990.00, NULL, 500.00, 'uploads/auction_1765903792_69418db0c82e7.jpg', 2021, 64074, 'diesel', 'automatic', 'Grey', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()),
('2020 Nissan NP200 1.6', NULL, '2020 Nissan NP200 1.6\n\nAsking bid/Cash Price: R41 000,00\n\nYear: 2020\nMileage: 108 000 km\nTransmission: Manual\nService History: Yes\nCondition: Used\nBody: Single Cab\nColour: White\nFuel: Petrol\n\nAA Tested & Approved\n>Accident Free\n>One previous owner\n>mechanical & electronic warranty\n>Nation wide delivery\n>SADC - delivery', 41000.00, NULL, 500.00, 'uploads/auction_1765903896_69418e18a649e.jpg', 2020, 108000, 'petrol', 'manual', 'White', 'both', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW());

-- ============================================================
-- PART 3 — FIX EXISTING ROWS (if you already had some data)
-- Ensures ALL rows in the table have valid bidding columns.
-- ============================================================

-- Any row with no bids should have current_price = NULL
-- (api.php place_bid handles NULL correctly — falls back to start_price)
UPDATE `auctions`
SET `current_price` = NULL
WHERE `current_price` = 0 OR `current_price` = `start_price`;

-- Push all end_times that have already expired to 30 days from now
-- so the auctions show as "live"
UPDATE `auctions`
SET
  `start_time` = NOW(),
  `end_time`   = DATE_ADD(NOW(), INTERVAL 30 DAY)
WHERE `end_time` < NOW()
  AND `status` = 'active';

-- ============================================================
-- PART 4 — VERIFY
-- ============================================================
SELECT
  COUNT(*)                        AS total_auctions,
  SUM(status = 'active')          AS active,
  SUM(current_price IS NULL)      AS no_bids_yet,
  SUM(current_price IS NOT NULL)  AS has_bids
FROM auctions;

COMMIT;

-- ============================================================
-- DONE.
-- If the SELECT above shows your vehicle count, run:
--   DELETE test_login.php from your server!
-- ============================================================
