import os
from pathlib import Path

# Base paths
BASE_DIR = Path(__file__).resolve().parent.parent
DATA_DIR = BASE_DIR / "data"
LOGS_DIR = BASE_DIR / "logs"
STATIC_DIR = BASE_DIR / "static"

DATA_DIR.mkdir(exist_ok=True)
LOGS_DIR.mkdir(exist_ok=True)

class Settings:
    PROJECT_NAME: str = "CS2 Case Simulator Platform"
    VERSION: str = "1.0.0"
    ENVIRONMENT: str = os.getenv("ENVIRONMENT", "production")
    
    BASE_DIR: Path = BASE_DIR
    DATA_DIR: Path = DATA_DIR
    LOGS_DIR: Path = LOGS_DIR
    STATIC_DIR: Path = STATIC_DIR
    
    # Server configuration
    HOST: str = os.getenv("HOST", "0.0.0.0")
    PORT: int = int(os.getenv("PORT", 8000))
    DEBUG: bool = os.getenv("DEBUG", "false").lower() == "true"
    
    # Database
    DATABASE_PATH: Path = DATA_DIR / "cs2_simulator.db"
    
    # Authentication & Security
    SECRET_KEY: str = os.getenv("SECRET_KEY", "cs2-ultra-secure-secret-key-change-in-prod-7f9e2b1c4a8d")
    ALGORITHM: str = "HS256"
    ACCESS_TOKEN_EXPIRE_MINUTES: int = 60 * 24 * 7  # 7 days
    
    # Provably Fair Master Server Seed
    SERVER_SEED_SECRET: str = os.getenv("SERVER_SEED_SECRET", "cs2-provably-fair-master-seed-99a8b7c6d5e4f3")
    
    # Rate Limiting
    AUTH_RATE_LIMIT: int = 20  # requests per minute
    API_RATE_LIMIT: int = 120   # requests per minute
    
    # Default Admin Credentials
    ADMIN_USERNAME: str = os.getenv("ADMIN_USERNAME", "admin")
    ADMIN_EMAIL: str = os.getenv("ADMIN_EMAIL", "admin@cs2cases.local")
    ADMIN_PASSWORD: str = os.getenv("ADMIN_PASSWORD", "admin")
    ADMIN_STARTING_BALANCE: float = 10000.00
    USER_STARTING_BALANCE: float = 0.00


settings = Settings()

