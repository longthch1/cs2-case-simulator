import sqlite3
import hashlib
import os
import json
from pathlib import Path
from contextlib import contextmanager
from app.config import settings
from app.logging_config import logger

def get_db_path() -> Path:
    return settings.DATABASE_PATH

@contextmanager
def get_db_connection():
    """Provides a transactional database connection with row factory."""
    conn = sqlite3.connect(str(get_db_path()), timeout=20.0)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON;")
    conn.execute("PRAGMA journal_mode = WAL;")  # High concurrency Write-Ahead Logging
    try:
        yield conn
        conn.commit()
    except Exception as e:
        conn.rollback()
        logger.error(f"Database transaction rolled back due to error: {e}")
        raise
    finally:
        conn.close()

def hash_password(password: str, salt: bytes = None) -> tuple[str, str]:
    """Hashes a password using PBKDF2-HMAC-SHA256 with 100,000 iterations."""
    if salt is None:
        salt = os.urandom(32)
    pwd_hash = hashlib.pbkdf2_hmac(
        'sha256',
        password.encode('utf-8'),
        salt,
        100000
    )
    return pwd_hash.hex(), salt.hex()

def verify_password(password: str, password_hash: str, salt_hex: str) -> bool:
    """Verifies a password against hash and salt."""
    salt = bytes.fromhex(salt_hex)
    check_hash, _ = hash_password(password, salt)
    return check_hash == password_hash

def init_db():
    """Initializes tables and seeds initial data."""
    logger.info("Initializing database schema...")
    with get_db_connection() as conn:
        cursor = conn.cursor()
        
        # 1. Users table
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            salt TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user',
            balance REAL NOT NULL DEFAULT 0.0,
            daily_streak INTEGER DEFAULT 0,
            last_daily_claim TIMESTAMP,
            avatar_url TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        """)
        
        # 2. Cases table
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS cases (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            description TEXT NOT NULL,
            price REAL NOT NULL,
            key_price REAL NOT NULL DEFAULT 2.49,
            image TEXT NOT NULL,
            is_active INTEGER DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        """)
        
        # 3. Skins table
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS skins (
            id TEXT PRIMARY KEY,
            case_id TEXT NOT NULL,
            name TEXT NOT NULL,
            weapon TEXT NOT NULL,
            skin_name TEXT NOT NULL,
            rarity TEXT NOT NULL,
            rarity_name TEXT NOT NULL,
            rarity_color TEXT NOT NULL,
            rarity_tier INTEGER NOT NULL,
            image TEXT NOT NULL,
            base_price REAL NOT NULL,
            min_float REAL NOT NULL DEFAULT 0.0,
            max_float REAL NOT NULL DEFAULT 1.0,
            can_be_stattrak INTEGER DEFAULT 1,
            FOREIGN KEY (case_id) REFERENCES cases (id) ON DELETE CASCADE
        );
        """)
        
        # 4. Inventory table
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS inventory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            skin_id TEXT NOT NULL,
            float_value REAL NOT NULL,
            wear_name TEXT NOT NULL,
            is_stattrak INTEGER NOT NULL DEFAULT 0,
            value REAL NOT NULL,
            is_sold INTEGER NOT NULL DEFAULT 0,
            acquired_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (skin_id) REFERENCES skins (id) ON DELETE CASCADE
        );
        """)
        
        # 5. Open History table (with provably fair seeds)
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS open_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            case_id TEXT NOT NULL,
            inventory_id INTEGER NOT NULL,
            cost REAL NOT NULL,
            payout REAL NOT NULL,
            server_seed TEXT NOT NULL,
            server_seed_hash TEXT NOT NULL,
            client_seed TEXT NOT NULL,
            nonce INTEGER NOT NULL,
            roll_number REAL NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (case_id) REFERENCES cases (id) ON DELETE CASCADE,
            FOREIGN KEY (inventory_id) REFERENCES inventory (id) ON DELETE CASCADE
        );
        """)
        
        # 6. Tradeup History table
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS tradeup_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            input_inventory_ids TEXT NOT NULL,
            output_inventory_id INTEGER NOT NULL,
            input_tier INTEGER NOT NULL,
            output_tier INTEGER NOT NULL,
            output_float REAL NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            FOREIGN KEY (output_inventory_id) REFERENCES inventory (id) ON DELETE CASCADE
        );
        """)
        
        # 7. Financial Transactions Ledger
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL, -- 'deposit', 'case_open', 'skin_sell', 'tradeup'
            amount REAL NOT NULL,
            balance_after REAL NOT NULL,
            description TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        );
        """)
        
        # 8. Security Audit Logs
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            ip_address TEXT DEFAULT '',
            user_agent TEXT DEFAULT '',
            details TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        """)

        # 9. Hourly Promo Codes & Redemptions
        cursor.execute("""
        CREATE TABLE IF NOT EXISTS hourly_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL,
            reward_amount REAL NOT NULL DEFAULT 100.0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            is_active INTEGER DEFAULT 1
        );
        """)

        cursor.execute("""
        CREATE TABLE IF NOT EXISTS code_redemptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            code TEXT NOT NULL,
            amount REAL NOT NULL,
            redeemed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            UNIQUE (user_id, code)
        );
        """)
        
        # Indexes for query speed
        cursor.execute("CREATE INDEX IF NOT EXISTS idx_inventory_user ON inventory (user_id, is_sold);")
        cursor.execute("CREATE INDEX IF NOT EXISTS idx_skins_case ON skins (case_id, rarity_tier);")
        cursor.execute("CREATE INDEX IF NOT EXISTS idx_history_user ON open_history (user_id);")
        cursor.execute("CREATE INDEX IF NOT EXISTS idx_trans_user ON transactions (user_id);")
        cursor.execute("CREATE INDEX IF NOT EXISTS idx_audit_time ON audit_logs (created_at);")

        # Automatic migrations for existing DB
        try:
            cursor.execute("ALTER TABLE users ADD COLUMN daily_streak INTEGER DEFAULT 0;")
        except Exception:
            pass
        try:
            cursor.execute("ALTER TABLE users ADD COLUMN last_daily_claim TIMESTAMP;")
        except Exception:
            pass

        # Create or Update Default Admin User
        pwd_hash, salt = hash_password(settings.ADMIN_PASSWORD)
        cursor.execute("SELECT id FROM users WHERE username = ?", (settings.ADMIN_USERNAME,))
        admin = cursor.fetchone()
        if not admin:
            cursor.execute("""
                INSERT INTO users (username, email, password_hash, salt, role, balance)
                VALUES (?, ?, ?, ?, 'admin', ?)
            """, (settings.ADMIN_USERNAME, settings.ADMIN_EMAIL, pwd_hash, salt, settings.ADMIN_STARTING_BALANCE))
            logger.info(f"Default admin user created: {settings.ADMIN_USERNAME}")
        else:
            cursor.execute("""
                UPDATE users SET password_hash = ?, salt = ?, role = 'admin' WHERE id = ?
            """, (pwd_hash, salt, admin['id']))
            logger.info(f"Admin user credentials verified for: {settings.ADMIN_USERNAME}")

    logger.info("Database schema initialized successfully.")


