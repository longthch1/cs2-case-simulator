import hmac
import hashlib
import time
import secrets
from typing import Dict, Tuple
from fastapi import Request, HTTPException, status
from starlette.middleware.base import BaseHTTPMiddleware
from app.config import settings
from app.logging_config import logger, audit_logger

class ProvablyFairRNG:
    """
    Cryptographically secure, provably fair unboxing engine for CS2.
    Formula: HMAC_SHA256(ServerSeed, ClientSeed + ":" + Nonce + ":" + SubIndex)
    """
    
    @staticmethod
    def generate_server_seed() -> str:
        return secrets.token_hex(32)

    @staticmethod
    def hash_server_seed(server_seed: str) -> str:
        return hashlib.sha256(server_seed.encode('utf-8')).hexdigest()

    @staticmethod
    def calculate_roll(server_seed: str, client_seed: str, nonce: int, sub_index: int = 0) -> float:
        """Generates a uniform float in [0, 1) using HMAC-SHA256."""
        message = f"{client_seed}:{nonce}:{sub_index}".encode('utf-8')
        hmac_obj = hmac.new(server_seed.encode('utf-8'), message, hashlib.sha256)
        digest = hmac_obj.hexdigest()
        # Take first 8 hex characters (32 bits)
        val = int(digest[:8], 16)
        return val / 0x100000000  # Normalize to [0, 1)

    @staticmethod
    def calculate_stattrak_and_float(server_seed: str, client_seed: str, nonce: int, sub_index: int = 0) -> Tuple[bool, float]:
        """Calculates StatTrak chance and wear float value from distinct portions of the HMAC digest."""
        message = f"{client_seed}:{nonce}:{sub_index}:attributes".encode('utf-8')
        hmac_obj = hmac.new(server_seed.encode('utf-8'), message, hashlib.sha256)
        digest = hmac_obj.hexdigest()
        
        # StatTrak: 10% chance
        st_val = int(digest[8:16], 16) / 0x100000000
        is_stattrak = st_val < 0.10
        
        # Float Value: between 0.000000 and 1.000000
        float_raw = int(digest[16:24], 16) / 0x100000000
        return is_stattrak, float_raw

    @staticmethod
    def get_rarity_tier_from_roll(roll: float) -> int:
        """
        Exact CS:GO / CS2 case unbox probabilities:
        - Mil-Spec:     79.92% (0.00000 - 0.79920) -> Tier 1
        - Restricted:   15.98% (0.79920 - 0.95900) -> Tier 2
        - Classified:    3.20% (0.95900 - 0.99100) -> Tier 3
        - Covert:        0.64% (0.99100 - 0.99740) -> Tier 4
        - Special Rare:  0.26% (0.99740 - 1.00000) -> Tier 5 (Knives/Gloves)
        """
        if roll < 0.79920:
            return 1
        elif roll < 0.95900:
            return 2
        elif roll < 0.99100:
            return 3
        elif roll < 0.99740:
            return 4
        else:
            return 5

    @staticmethod
    def get_wear_condition(float_val: float) -> Tuple[str, float]:
        """Returns wear name and price multiplier based on float value."""
        if float_val < 0.07:
            return "Factory New", 1.6
        elif float_val < 0.15:
            return "Minimal Wear", 1.2
        elif float_val < 0.38:
            return "Field-Tested", 1.0
        elif float_val < 0.45:
            return "Well-Worn", 0.85
        else:
            return "Battle-Scarred", 0.70

class InMemoryRateLimiter:
    """Sliding window rate limiter to protect endpoints from abuse."""
    def __init__(self):
        # key -> list of timestamps
        self.requests: Dict[str, list[float]] = {}
        
    def is_allowed(self, key: str, max_requests: int, window_seconds: int = 60) -> bool:
        now = time.time()
        timestamps = self.requests.get(key, [])
        # Prune expired timestamps
        cutoff = now - window_seconds
        valid_timestamps = [t for t in timestamps if t > cutoff]
        
        if len(valid_timestamps) >= max_requests:
            self.requests[key] = valid_timestamps
            return False
            
        valid_timestamps.append(now)
        self.requests[key] = valid_timestamps
        return True

rate_limiter = InMemoryRateLimiter()

class SecurityHeadersMiddleware(BaseHTTPMiddleware):
    """Adds essential OWASP security headers to all HTTP responses."""
    async def dispatch(self, request: Request, call_next):
        response = await call_next(request)
        response.headers["X-Frame-Options"] = "DENY"
        response.headers["X-Content-Type-Options"] = "nosniff"
        response.headers["X-XSS-Protection"] = "1; mode=block"
        response.headers["Referrer-Policy"] = "strict-origin-when-cross-origin"
        response.headers["Server"] = "CS2-Engine/1.0"
        return response
