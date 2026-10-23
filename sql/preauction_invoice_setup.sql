-- ============================================================
-- AUCTION HUB GROUP — Pre-Auction Invoice Migration
-- Run once: mysql -u root auctionhubgroup_db < preauction_invoice_setup.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS preauction_invoices (
    id                      INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    invoice_number          VARCHAR(50)     NOT NULL UNIQUE,

    -- Buyer
    buyer_name              VARCHAR(200)    NOT NULL,
    buyer_id_passport       VARCHAR(50)     DEFAULT NULL,
    buyer_address           TEXT            DEFAULT NULL,
    buyer_phone             VARCHAR(50)     DEFAULT NULL,
    buyer_email             VARCHAR(200)    DEFAULT NULL,

    -- Vehicle
    vehicle_description     VARCHAR(500)    NOT NULL,
    vehicle_year            SMALLINT        DEFAULT NULL,
    vehicle_mileage         VARCHAR(50)     DEFAULT NULL,
    vehicle_transmission    VARCHAR(50)     DEFAULT NULL,
    vehicle_service_history VARCHAR(10)     DEFAULT 'Yes',
    vehicle_condition       VARCHAR(50)     DEFAULT 'Used',
    vehicle_body            VARCHAR(50)     DEFAULT NULL,
    vehicle_colour          VARCHAR(100)    DEFAULT NULL,
    vehicle_fuel            VARCHAR(50)     DEFAULT NULL,
    vehicle_features        TEXT            DEFAULT NULL,
    vehicle_image_url       TEXT            DEFAULT NULL,

    -- Pricing
    total_price             DECIMAL(12,2)   NOT NULL,
    commitment_fee_pct      DECIMAL(5,2)    NOT NULL DEFAULT 35.00,
    commitment_fee_amount   DECIMAL(12,2)   NOT NULL,
    outstanding_amount      DECIMAL(12,2)   NOT NULL,

    -- Workflow
    status                  ENUM('pending','pop_uploaded','approved','declined')
                                            NOT NULL DEFAULT 'pending',
    buyer_code              VARCHAR(50)     DEFAULT NULL,
    declination_reason      TEXT            DEFAULT NULL,

    -- Proof of Payment
    pop_file_path           VARCHAR(500)    DEFAULT NULL,
    pop_uploaded_at         DATETIME        DEFAULT NULL,

    -- Meta
    created_by              INT UNSIGNED    DEFAULT NULL,
    upload_token            VARCHAR(64)     DEFAULT NULL,
    notes                   TEXT            DEFAULT NULL,
    auction_id              INT UNSIGNED    DEFAULT NULL,

    -- Timestamps
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at              DATETIME        NOT NULL,
    date_signed             DATE            DEFAULT NULL,

    INDEX idx_status        (status),
    INDEX idx_buyer_email   (buyer_email(191)),
    INDEX idx_invoice_num   (invoice_number),
    INDEX idx_upload_token  (upload_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
