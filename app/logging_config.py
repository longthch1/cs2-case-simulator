import logging
import sys
from logging.handlers import RotatingFileHandler
from app.config import LOGS_DIR

def setup_logging():
    LOGS_DIR.mkdir(exist_ok=True)
    
    # Root logger
    root_logger = logging.getLogger()
    root_logger.setLevel(logging.INFO)
    
    # Clear existing handlers
    if root_logger.hasHandlers():
        root_logger.handlers.clear()
        
    formatter = logging.Formatter(
        "[%(asctime)s] [%(levelname)s] [%(name)s:%(lineno)d] %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S"
    )
    
    # Console Handler
    console_handler = logging.StreamHandler(sys.stdout)
    console_handler.setLevel(logging.INFO)
    console_handler.setFormatter(formatter)
    root_logger.addHandler(console_handler)
    
    # Main App Rotating File Handler (10MB per file, max 5 backups)
    app_file_handler = RotatingFileHandler(
        LOGS_DIR / "app.log",
        maxBytes=10 * 1024 * 1024,
        backupCount=5,
        encoding="utf-8"
    )
    app_file_handler.setLevel(logging.INFO)
    app_file_handler.setFormatter(formatter)
    root_logger.addHandler(app_file_handler)
    
    # Audit Logger specifically for sensitive security and financial events
    audit_logger = logging.getLogger("audit")
    audit_logger.setLevel(logging.INFO)
    audit_logger.propagate = False  # Keep separate or set to True if also want in app.log
    
    audit_file_handler = RotatingFileHandler(
        LOGS_DIR / "audit.log",
        maxBytes=10 * 1024 * 1024,
        backupCount=10,
        encoding="utf-8"
    )
    audit_formatter = logging.Formatter(
        "[%(asctime)s] [AUDIT] %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S"
    )
    audit_file_handler.setFormatter(audit_formatter)
    audit_logger.addHandler(audit_file_handler)
    audit_logger.addHandler(console_handler)
    
    # Silence overly verbose external loggers
    logging.getLogger("uvicorn.access").setLevel(logging.WARNING)

setup_logging()
logger = logging.getLogger("cs2_simulator")
audit_logger = logging.getLogger("audit")
