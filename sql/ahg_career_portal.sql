-- ============================================================
-- AHG Career Portal — Database Schema
-- Run once: phpMyAdmin → SQL tab → paste and execute
-- ============================================================

CREATE TABLE IF NOT EXISTS career_applications (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(150) NOT NULL,
    email           VARCHAR(150) NOT NULL,
    phone           VARCHAR(30)  NOT NULL,
    profile_photo   VARCHAR(255) DEFAULT NULL,
    strengths       TEXT,
    weaknesses      TEXT,
    ref_companies   TEXT,
    cv_path         VARCHAR(255) DEFAULT NULL,
    id_doc_path     VARCHAR(255) DEFAULT NULL,
    cert_path       VARCHAR(255) DEFAULT NULL,
    cover_letter    TEXT,
    video_path      VARCHAR(255) DEFAULT NULL,
    status              ENUM('pending','reviewing','approved','rejected') DEFAULT 'pending',
    admin_notes         TEXT,
    contract_token      VARCHAR(64)  DEFAULT NULL,
    induction_sent_at   DATETIME     DEFAULT NULL,
    contract_signed_at  DATETIME     DEFAULT NULL,
    submitted_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    application_id  INT DEFAULT NULL,
    full_name       VARCHAR(150) NOT NULL,
    email           VARCHAR(150) DEFAULT NULL,
    phone           VARCHAR(30)  NOT NULL,
    pin_hash        VARCHAR(255) NOT NULL,
    worker_code     VARCHAR(20)  DEFAULT NULL,
    status          ENUM('active','inactive','suspended') DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login      TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_worker_phone (phone),
    FOREIGN KEY (application_id) REFERENCES career_applications(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS worker_daily_logs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    worker_id       INT          NOT NULL,
    log_date        DATE         NOT NULL,
    platforms       VARCHAR(255) DEFAULT NULL,
    posts_count     INT          DEFAULT 0,
    post_details    TEXT,
    proof_path      VARCHAR(255) DEFAULT NULL,
    proof_paths     TEXT         DEFAULT NULL,
    leads_count     INT          DEFAULT 0,
    leads_details   TEXT,
    status          ENUM('submitted','verified','rejected') DEFAULT 'submitted',
    admin_notes     TEXT,
    submitted_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_worker_date (worker_id, log_date),
    FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS worker_leads (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    log_id       INT          NOT NULL,
    worker_id    INT          NOT NULL,
    lead_name    VARCHAR(150) NOT NULL,
    lead_phone   VARCHAR(30)  NOT NULL,
    submitted_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (log_id)    REFERENCES worker_daily_logs(id) ON DELETE CASCADE,
    FOREIGN KEY (worker_id) REFERENCES workers(id)           ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
