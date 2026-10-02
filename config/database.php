<?php
/**
 * CS2 Case Opening Simulator — MySQL Database Layer
 * Uses PDO with prepared statements for all queries.
 */
require_once __DIR__ . '/config.php';

// ── Singleton PDO Connection ──────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone='+00:00'",
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            writeLog('ERROR', 'Database connection failed: ' . $e->getMessage());
            http_response_code(503);
            echo json_encode(['error' => 'Database connection unavailable. Please check MySQL server.']);
            exit;
        }
    }
    return $pdo;
}

// ── Password Hashing (bcrypt cost=12) ─────────────────────────────────────────
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

// ── Audit Log Helper ──────────────────────────────────────────────────────────
function logAudit(?int $userId, string $action, string $ip = '', string $details = ''): void {
    try {
        $db  = getDB();
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt = $db->prepare(
            "INSERT INTO audit_logs (user_id, action, ip_address, user_agent, details) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $action, $ip, substr($ua, 0, 500), $details]);
        writeLog('AUDIT', "[$action] user_id=$userId ip=$ip $details");
    } catch (Throwable $e) {
        writeLog('ERROR', 'logAudit failed: ' . $e->getMessage());
    }
}

// ── Initialize Database (create tables + seed admin) ─────────────────────────
function initDB(): void {
    $db = getDB();

    // Users
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        username        VARCHAR(50) UNIQUE NOT NULL,
        email           VARCHAR(100) UNIQUE NOT NULL,
        password_hash   VARCHAR(255) NOT NULL,
        role            ENUM('user','admin') DEFAULT 'user',
        balance         DECIMAL(12,2) DEFAULT 0.00,
        daily_streak    INT DEFAULT 0,
        last_daily_claim DATETIME NULL,
        avatar_url      VARCHAR(500) DEFAULT '',
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_login      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Cases
    $db->exec("CREATE TABLE IF NOT EXISTS cases (
        id          VARCHAR(100) PRIMARY KEY,
        name        VARCHAR(200) NOT NULL,
        description TEXT NOT NULL,
        price       DECIMAL(8,2) NOT NULL,
        key_price   DECIMAL(8,2) DEFAULT 0.00,
        image       TEXT NOT NULL,
        is_active   TINYINT DEFAULT 1,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Skins
    $db->exec("CREATE TABLE IF NOT EXISTS skins (
        id              VARCHAR(200) PRIMARY KEY,
        case_id         VARCHAR(100) NOT NULL,
        name            VARCHAR(300) NOT NULL,
        weapon          VARCHAR(100) NOT NULL,
        skin_name       VARCHAR(200) NOT NULL,
        rarity          VARCHAR(50) NOT NULL,
        rarity_name     VARCHAR(100) NOT NULL,
        rarity_color    VARCHAR(20) NOT NULL,
        rarity_tier     TINYINT NOT NULL,
        image           TEXT NOT NULL,
        base_price      DECIMAL(10,2) NOT NULL,
        min_float       FLOAT DEFAULT 0.0,
        max_float       FLOAT DEFAULT 1.0,
        can_be_stattrak TINYINT DEFAULT 1,
        FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Inventory
    $db->exec("CREATE TABLE IF NOT EXISTS inventory (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        skin_id     VARCHAR(200) NOT NULL,
        float_value FLOAT NOT NULL,
        wear_name   VARCHAR(50) NOT NULL,
        is_stattrak TINYINT DEFAULT 0,
        value       DECIMAL(10,2) NOT NULL,
        is_sold     TINYINT DEFAULT 0,
        acquired_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (skin_id) REFERENCES skins(id) ON DELETE CASCADE,
        INDEX idx_inv_user (user_id, is_sold)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Open History
    $db->exec("CREATE TABLE IF NOT EXISTS open_history (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL,
        case_id          VARCHAR(100) NOT NULL,
        inventory_id     INT NOT NULL,
        cost             DECIMAL(10,2) NOT NULL,
        payout           DECIMAL(10,2) NOT NULL,
        server_seed      VARCHAR(200) NOT NULL,
        server_seed_hash VARCHAR(200) NOT NULL,
        client_seed      VARCHAR(200) NOT NULL,
        nonce            INT NOT NULL,
        roll_number      FLOAT NOT NULL,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_hist_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Tradeup History
    $db->exec("CREATE TABLE IF NOT EXISTS tradeup_history (
        id                   INT AUTO_INCREMENT PRIMARY KEY,
        user_id              INT NOT NULL,
        input_inventory_ids  TEXT NOT NULL,
        output_inventory_id  INT NOT NULL,
        input_tier           TINYINT NOT NULL,
        output_tier          TINYINT NOT NULL,
        output_float         FLOAT NOT NULL,
        created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Transactions
    $db->exec("CREATE TABLE IF NOT EXISTS transactions (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        user_id       INT NOT NULL,
        type          VARCHAR(50) NOT NULL,
        amount        DECIMAL(10,2) NOT NULL,
        balance_after DECIMAL(10,2) NOT NULL,
        description   VARCHAR(500) NOT NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_trans_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Audit Logs
    $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NULL,
        action      VARCHAR(100) NOT NULL,
        ip_address  VARCHAR(50) DEFAULT '',
        user_agent  VARCHAR(500) DEFAULT '',
        details     TEXT DEFAULT '',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Hourly Codes
    $db->exec("CREATE TABLE IF NOT EXISTS hourly_codes (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        code          VARCHAR(6) UNIQUE NOT NULL,
        reward_amount DECIMAL(10,2) DEFAULT 100.00,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at    DATETIME NOT NULL,
        is_active     TINYINT DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Code Redemptions
    $db->exec("CREATE TABLE IF NOT EXISTS code_redemptions (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        code        VARCHAR(6) NOT NULL,
        amount      DECIMAL(10,2) NOT NULL,
        redeemed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_redemption (user_id, code),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Seed admin user
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([ADMIN_USERNAME]);
    if (!$stmt->fetch()) {
        $hash = hashPassword(ADMIN_PASSWORD);
        $db->prepare(
            "INSERT INTO users (username, email, password_hash, role, balance) VALUES (?, ?, ?, 'admin', ?)"
        )->execute([ADMIN_USERNAME, ADMIN_EMAIL, $hash, ADMIN_STARTING_BALANCE]);
        writeLog('INFO', 'Admin user created: ' . ADMIN_USERNAME);
    }
}

