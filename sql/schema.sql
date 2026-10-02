-- ============================================================
-- CS2 Case Opening Simulator - MySQL 8.0 Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS cs2_simulator CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cs2_simulator;

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50)  UNIQUE NOT NULL,
    email           VARCHAR(100) UNIQUE NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('user','admin') DEFAULT 'user',
    balance         DECIMAL(12,2) DEFAULT 0.00,
    daily_streak    INT DEFAULT 0,
    last_daily_claim DATETIME NULL,
    avatar_url      VARCHAR(500) DEFAULT '',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: cases
-- ============================================================
CREATE TABLE IF NOT EXISTS cases (
    id          VARCHAR(100) PRIMARY KEY,
    name        VARCHAR(200),
    description TEXT,
    price       DECIMAL(8,2),
    key_price   DECIMAL(8,2) DEFAULT 0.00,
    image       TEXT,
    is_active   TINYINT DEFAULT 1,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: skins
-- ============================================================
CREATE TABLE IF NOT EXISTS skins (
    id              VARCHAR(200) PRIMARY KEY,
    case_id         VARCHAR(100),
    name            VARCHAR(300),
    weapon          VARCHAR(100),
    skin_name       VARCHAR(200),
    rarity          VARCHAR(50),
    rarity_name     VARCHAR(100),
    rarity_color    VARCHAR(20),
    rarity_tier     TINYINT,
    image           TEXT,
    base_price      DECIMAL(10,2),
    min_float       FLOAT DEFAULT 0.0,
    max_float       FLOAT DEFAULT 1.0,
    can_be_stattrak TINYINT DEFAULT 1,
    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: inventory
-- ============================================================
CREATE TABLE IF NOT EXISTS inventory (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT,
    skin_id     VARCHAR(200),
    float_value FLOAT,
    wear_name   VARCHAR(50),
    is_stattrak TINYINT DEFAULT 0,
    value       DECIMAL(10,2),
    is_sold     TINYINT DEFAULT 0,
    acquired_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (skin_id) REFERENCES skins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: open_history
-- ============================================================
CREATE TABLE IF NOT EXISTS open_history (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT,
    case_id          VARCHAR(100),
    inventory_id     INT,
    cost             DECIMAL(10,2),
    payout           DECIMAL(10,2),
    server_seed      VARCHAR(200),
    server_seed_hash VARCHAR(200),
    client_seed      VARCHAR(200),
    nonce            INT,
    roll_number      FLOAT,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: tradeup_history
-- ============================================================
CREATE TABLE IF NOT EXISTS tradeup_history (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT,
    input_inventory_ids TEXT,
    output_inventory_id INT,
    input_tier          TINYINT,
    output_tier         TINYINT,
    output_float        FLOAT,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS transactions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT,
    type         VARCHAR(50),
    amount       DECIMAL(10,2),
    balance_after DECIMAL(10,2),
    description  VARCHAR(500),
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: audit_logs
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NULL,
    action     VARCHAR(100),
    ip_address VARCHAR(50)  DEFAULT '',
    user_agent VARCHAR(500) DEFAULT '',
    details    TEXT         DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: hourly_codes
-- ============================================================
CREATE TABLE IF NOT EXISTS hourly_codes (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    code          VARCHAR(6) UNIQUE NOT NULL,
    reward_amount DECIMAL(10,2) DEFAULT 100.00,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at    DATETIME NOT NULL,
    is_active     TINYINT DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: code_redemptions
-- ============================================================
CREATE TABLE IF NOT EXISTS code_redemptions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT,
    code        VARCHAR(6),
    amount      DECIMAL(10,2),
    redeemed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_redemption (user_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- INDEXES
-- ============================================================

-- users
CREATE INDEX IF NOT EXISTS idx_users_email          ON users (email);
CREATE INDEX IF NOT EXISTS idx_users_role           ON users (role);
CREATE INDEX IF NOT EXISTS idx_users_created_at     ON users (created_at);

-- skins
CREATE INDEX IF NOT EXISTS idx_skins_case_id        ON skins (case_id);
CREATE INDEX IF NOT EXISTS idx_skins_rarity         ON skins (rarity);
CREATE INDEX IF NOT EXISTS idx_skins_rarity_tier    ON skins (rarity_tier);
CREATE INDEX IF NOT EXISTS idx_skins_weapon         ON skins (weapon);

-- inventory
CREATE INDEX IF NOT EXISTS idx_inventory_user_id    ON inventory (user_id);
CREATE INDEX IF NOT EXISTS idx_inventory_skin_id    ON inventory (skin_id);
CREATE INDEX IF NOT EXISTS idx_inventory_is_sold    ON inventory (is_sold);
CREATE INDEX IF NOT EXISTS idx_inventory_acquired   ON inventory (acquired_at);

-- open_history
CREATE INDEX IF NOT EXISTS idx_open_history_user    ON open_history (user_id);
CREATE INDEX IF NOT EXISTS idx_open_history_case    ON open_history (case_id);
CREATE INDEX IF NOT EXISTS idx_open_history_created ON open_history (created_at);

-- tradeup_history
CREATE INDEX IF NOT EXISTS idx_tradeup_user         ON tradeup_history (user_id);
CREATE INDEX IF NOT EXISTS idx_tradeup_created      ON tradeup_history (created_at);

-- transactions
CREATE INDEX IF NOT EXISTS idx_transactions_user    ON transactions (user_id);
CREATE INDEX IF NOT EXISTS idx_transactions_type    ON transactions (type);
CREATE INDEX IF NOT EXISTS idx_transactions_created ON transactions (created_at);

-- audit_logs
CREATE INDEX IF NOT EXISTS idx_audit_user           ON audit_logs (user_id);
CREATE INDEX IF NOT EXISTS idx_audit_action         ON audit_logs (action);
CREATE INDEX IF NOT EXISTS idx_audit_created        ON audit_logs (created_at);

-- hourly_codes
CREATE INDEX IF NOT EXISTS idx_codes_expires        ON hourly_codes (expires_at);
CREATE INDEX IF NOT EXISTS idx_codes_active         ON hourly_codes (is_active);

-- code_redemptions
CREATE INDEX IF NOT EXISTS idx_redemptions_user     ON code_redemptions (user_id);
CREATE INDEX IF NOT EXISTS idx_redemptions_code     ON code_redemptions (code);

-- ============================================================
-- SEED: Default admin user
-- password_hash is bcrypt of 'admin' (cost=12)
-- $2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uPi2YOkiO
-- ============================================================
INSERT INTO users (username, email, password_hash, role, balance)
VALUES (
    'admin',
    'admin@cs2simulator.local',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uPi2YOkiO',
    'admin',
    999999.00
)
ON DUPLICATE KEY UPDATE role = 'admin', balance = 999999.00;
