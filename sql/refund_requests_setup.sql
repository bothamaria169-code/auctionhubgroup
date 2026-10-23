-- ============================================================
-- AUCTION HUB GROUP — Refund Requests Migration
-- Run once: mysql -u root auctionhubgroup_db < refund_requests_setup.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS refund_requests (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED    NOT NULL,

    -- What the refund is for
    reference_type      ENUM('registration','preauction_invoice','other')
                                        NOT NULL DEFAULT 'other',
    reference_number    VARCHAR(100)    DEFAULT NULL,
    amount_paid         DECIMAL(12,2)   NOT NULL,
    refund_amount       DECIMAL(12,2)   NOT NULL,
    reason              TEXT            NOT NULL,

    -- Banking details for the EFT refund
    bank_name           VARCHAR(100)    NOT NULL,
    account_holder      VARCHAR(200)    NOT NULL,
    account_number      VARCHAR(50)     NOT NULL,
    branch_code         VARCHAR(20)     NOT NULL,
    account_type        ENUM('cheque','savings','transmission','credit')
                                        NOT NULL DEFAULT 'cheque',

    -- Proof of original payment
    pop_file_path       VARCHAR(500)    DEFAULT NULL,

    -- Workflow
    status              ENUM('pending','under_review','processed','rejected')
                                        NOT NULL DEFAULT 'pending',
    admin_notes         TEXT            DEFAULT NULL,
    rejection_reason    TEXT            DEFAULT NULL,
    processed_by        INT UNSIGNED    DEFAULT NULL,
    processed_at        DATETIME        DEFAULT NULL,

    -- Timestamps
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_user_id   (user_id),
    INDEX idx_status    (status),
    INDEX idx_created   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
