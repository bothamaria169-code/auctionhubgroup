-- ============================================================
-- AUCTION HUB GROUP — In-house finance & pre-auction applications (Nov 2026)
-- The site creates this table by itself on first use (db_schema.php), so a
-- git deploy needs no SQL step. Kept here for manual use in phpMyAdmin —
-- change both together.
-- ============================================================

CREATE TABLE IF NOT EXISTS client_applications (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    kind          VARCHAR(12)   NOT NULL,                 -- finance | preauction
    token         CHAR(32)      NOT NULL,                 -- the client's private link
    status        VARCHAR(24)   NOT NULL DEFAULT 'new',
    name          VARCHAR(150)  NOT NULL,
    email         VARCHAR(190)  NOT NULL,
    phone         VARCHAR(30)   NOT NULL,
    user_id       INT           NULL,
    auction_id    INT           NULL,
    vehicle       VARCHAR(255)  NULL,
    price         DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount        DECIMAL(12,2) NOT NULL DEFAULT 0,       -- finance deposit / pre-auction commitment
    percent       DECIMAL(5,2)  NULL,                     -- pre-auction commitment %
    months        INT           NULL,                     -- finance plan chosen
    buyer_code    VARCHAR(10)   NULL,                     -- 6-digit Buyer's Code (gate pass)
    details       MEDIUMTEXT    NULL,                     -- JSON: quotation, documents, letters, viewing, history
    ip            VARCHAR(45)   NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ca_token (token),
    UNIQUE KEY uq_ca_code (buyer_code),
    INDEX idx_ca_kind_status (kind, status),
    INDEX idx_ca_email (email),
    INDEX idx_ca_auction (auction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
