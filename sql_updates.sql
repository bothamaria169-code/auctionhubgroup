-- ============================================================
-- AUCTION HUB GROUP — Database Updates
-- Run these SQL statements in phpMyAdmin or MySQL CLI
-- against the auctionhubgroup_db database
-- ============================================================

-- ── 1. TESTIMONIALS TABLE ────────────────────────────────────
CREATE TABLE IF NOT EXISTS testimonials (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    headline    VARCHAR(255) NOT NULL,
    image_url   VARCHAR(500) NOT NULL,
    cloudinary_public_id VARCHAR(255) DEFAULT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. POPUP NOTIFICATIONS TABLE ─────────────────────────────
-- Admin creates notification messages that display as toast popups
-- on the public-facing site. Can be auto-generated from bids/wins
-- or created manually by admin.
CREATE TABLE IF NOT EXISTS popup_notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message     VARCHAR(500) NOT NULL,
    type        ENUM('bid','win','leading','custom') NOT NULL DEFAULT 'custom',
    icon        VARCHAR(50) DEFAULT 'fa-bell',
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. SEED SOME SAMPLE NOTIFICATIONS (optional) ─────────────
INSERT INTO popup_notifications (message, type, icon, is_active) VALUES
('Bidder AHG-2026-047 just placed a bid of R85,000 on 2019 Toyota Hilux', 'bid', 'fa-gavel', 1),
('Bidder PVA-2026-012 is now leading on 2020 VW Polo Vivo', 'leading', 'fa-trophy', 1),
('2018 Ford Ranger SOLD to Bidder PVA-2026-031 for R142,500!', 'win', 'fa-check-circle', 1),
('New auction starting soon — 2021 Hyundai Tucson from Standard Bank', 'custom', 'fa-bell', 1);

-- ── 4. ADD BANNER IMAGE TO EVENTS ────────────────────────────
ALTER TABLE auction_events ADD COLUMN banner_image VARCHAR(500) DEFAULT NULL AFTER description;

-- ── 5. ADD FREQUENCY TO POPUP NOTIFICATIONS ──────────────────
ALTER TABLE popup_notifications ADD COLUMN frequency INT NOT NULL DEFAULT 10 AFTER is_active;
-- frequency = seconds between showing this notification (minimum interval)

-- ── 6. ADD BANK DETAILS APPROVAL WORKFLOW ────────────────────
-- Tracks banking detail requests that need admin approval
CREATE TABLE IF NOT EXISTS bank_detail_requests (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    processed_by INT UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 8. ADD HEAVY VEHICLE / EQUIPMENT MAKES ───────────────────
-- These are inserted only if they don't already exist (safe to run multiple times)
INSERT IGNORE INTO makes (name) VALUES
('Trucks - Isuzu'), ('Trucks - Hino'), ('Trucks - UD Trucks'), ('Trucks - MAN'),
('Trucks - Scania'), ('Trucks - Volvo Trucks'), ('Trucks - Mercedes-Benz Trucks'),
('Trucks - DAF'), ('Trucks - FUSO'), ('Trucks - FAW'), ('Trucks - Tata'),
('Heavy Vehicles - General'),
('Excavators - CAT'), ('Excavators - Komatsu'), ('Excavators - Hitachi'),
('Excavators - Volvo'), ('Excavators - JCB'), ('Excavators - Doosan'),
('ADTs - Bell'), ('ADTs - CAT'), ('ADTs - Volvo'), ('ADTs - Komatsu'),
('Crushing & Screening - Metso'), ('Crushing & Screening - Sandvik'),
('Crushing & Screening - Terex'), ('Crushing & Screening - General'),
('Mining Equipment - CAT'), ('Mining Equipment - Komatsu'),
('Mining Equipment - Liebherr'), ('Mining Equipment - General'),
('Farming Equipment - John Deere'), ('Farming Equipment - Massey Ferguson'),
('Farming Equipment - New Holland'), ('Farming Equipment - Case IH'),
('Farming Equipment - Kubota'), ('Farming Equipment - General');

-- ── 9. BULK AUCTION MANAGEMENT — Useful SQL Commands ─────────

-- Set ALL upcoming auctions to start at a specific time:
-- UPDATE auctions SET start_time = '2026-04-20 09:00:00' WHERE status = 'upcoming';

-- Set ALL upcoming auctions to end at a specific time:
-- UPDATE auctions SET end_time = '2026-04-27 18:00:00' WHERE status = 'upcoming';

-- Set start + end for ALL upcoming auctions:
-- UPDATE auctions SET start_time = '2026-04-20 09:00:00', end_time = '2026-04-27 18:00:00' WHERE status = 'upcoming';

-- Activate ALL upcoming auctions at once:
-- UPDATE auctions SET status = 'active' WHERE status = 'upcoming';

-- Close ALL active auctions:
-- UPDATE auctions SET status = 'closed' WHERE status = 'active';

-- Set times for specific auction IDs:
-- UPDATE auctions SET start_time = '2026-04-20 09:00:00', end_time = '2026-04-27 18:00:00' WHERE id IN (1, 2, 3, 4, 5);

-- Change status for specific auction IDs:
-- UPDATE auctions SET status = 'active' WHERE id IN (1, 2, 3, 4, 5);

-- View all auctions with their current times:
-- SELECT id, item_name, status, start_time, end_time, current_price FROM auctions ORDER BY status, end_time;
