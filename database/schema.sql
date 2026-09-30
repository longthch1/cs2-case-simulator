CREATE DATABASE IF NOT EXISTS cs2_case_simulator CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE cs2_case_simulator;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(32) NOT NULL,
    email VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user','admin') NOT NULL DEFAULT 'user',
    balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    daily_streak INT NOT NULL DEFAULT 0,
    last_daily_claim DATETIME NULL,
    avatar_url VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cases (
    id VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    price DECIMAL(12,2) NOT NULL,
    key_price DECIMAL(12,2) NOT NULL DEFAULT 2.49,
    image TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cases_active_price (is_active, price)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS skins (
    id VARCHAR(120) NOT NULL,
    case_id VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    weapon VARCHAR(120) NOT NULL,
    skin_name VARCHAR(255) NOT NULL,
    rarity VARCHAR(64) NOT NULL,
    rarity_name VARCHAR(100) NOT NULL,
    rarity_color VARCHAR(20) NOT NULL,
    rarity_tier TINYINT UNSIGNED NOT NULL,
    image TEXT NOT NULL,
    base_price DECIMAL(12,2) NOT NULL,
    min_float DECIMAL(10,6) NOT NULL DEFAULT 0.000000,
    max_float DECIMAL(10,6) NOT NULL DEFAULT 1.000000,
    can_be_stattrak TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_skins_case_tier (case_id, rarity_tier),
    CONSTRAINT fk_skins_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    skin_id VARCHAR(120) NOT NULL,
    float_value DECIMAL(10,6) NOT NULL,
    wear_name VARCHAR(50) NOT NULL,
    is_stattrak TINYINT(1) NOT NULL DEFAULT 0,
    value DECIMAL(12,2) NOT NULL,
    is_sold TINYINT(1) NOT NULL DEFAULT 0,
    acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_inventory_user_sold (user_id, is_sold),
    KEY idx_inventory_skin (skin_id),
    CONSTRAINT fk_inventory_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_skin FOREIGN KEY (skin_id) REFERENCES skins(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS open_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    case_id VARCHAR(100) NOT NULL,
    inventory_id BIGINT UNSIGNED NOT NULL,
    cost DECIMAL(12,2) NOT NULL,
    payout DECIMAL(12,2) NOT NULL,
    server_seed CHAR(64) NOT NULL,
    server_seed_hash CHAR(64) NOT NULL,
    client_seed VARCHAR(64) NOT NULL,
    nonce BIGINT UNSIGNED NOT NULL,
    roll_number DECIMAL(10,8) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_history_user_nonce (user_id, nonce),
    KEY idx_history_case (case_id),
    KEY idx_history_created (created_at),
    CONSTRAINT fk_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_history_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
    CONSTRAINT fk_history_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tradeup_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    input_inventory_ids TEXT NOT NULL,
    output_inventory_id BIGINT UNSIGNED NOT NULL,
    input_tier TINYINT UNSIGNED NOT NULL,
    output_tier TINYINT UNSIGNED NOT NULL,
    output_float DECIMAL(10,6) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tradeup_user (user_id, created_at),
    CONSTRAINT fk_tradeup_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tradeup_output FOREIGN KEY (output_inventory_id) REFERENCES inventory(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(40) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    description VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_transactions_user_time (user_id, created_at),
    CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    user_agent VARCHAR(1000) NOT NULL DEFAULT '',
    details TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_time (created_at),
    KEY idx_audit_action (action),
    KEY idx_audit_user (user_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hourly_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code CHAR(6) NOT NULL,
    reward_amount DECIMAL(12,2) NOT NULL DEFAULT 100.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hourly_code (code),
    KEY idx_hourly_active_expiry (is_active, expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS code_redemptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    code CHAR(6) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_code_redemption (user_id, code),
    KEY idx_redemption_code (code),
    CONSTRAINT fk_redemption_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rate_limits (
    rate_key VARCHAR(255) NOT NULL,
    window_start DATETIME NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (rate_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS request_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    method VARCHAR(10) NOT NULL,
    path VARCHAR(255) NOT NULL,
    status_code SMALLINT UNSIGNED NOT NULL,
    duration_ms DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_metrics_created (created_at),
    KEY idx_metrics_status (status_code)
) ENGINE=InnoDB;
