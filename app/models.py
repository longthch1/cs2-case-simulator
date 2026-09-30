from pydantic import BaseModel, Field
from typing import Optional, List, Dict, Any

class RegisterRequest(BaseModel):
    username: str = Field(..., min_length=3, max_length=32)
    email: str = Field(..., min_length=5, max_length=120)
    password: str = Field(..., min_length=6, max_length=64)

class ForgotPasswordRequest(BaseModel):
    username: str = Field(..., min_length=3, max_length=32)
    email: str = Field(..., min_length=5, max_length=120)
    new_password: str = Field(..., min_length=6, max_length=64)

class LoginRequest(BaseModel):
    username_or_email: str
    password: str


class TokenResponse(BaseModel):
    access_token: str
    token_type: str = "Bearer"
    user: Dict[str, Any]

class UserProfileResponse(BaseModel):
    id: int
    username: str
    email: str
    role: str
    balance: float
    avatar_url: str
    created_at: str

class CaseOpenRequest(BaseModel):
    count: int = Field(default=1, ge=1, le=10)
    client_seed: Optional[str] = Field(default=None, max_length=64)

class DropItemDetail(BaseModel):
    inventory_id: int
    skin_id: str
    weapon: str
    skin_name: str
    rarity: str
    rarity_name: str
    rarity_color: str
    rarity_tier: int
    image: str
    float_value: float
    wear_name: str
    is_stattrak: bool
    value: float
    # Provably Fair Roll Verification Details
    server_seed_hash: str
    server_seed_revealed: str
    client_seed: str
    nonce: int
    roll_number: float

class CaseOpenResponse(BaseModel):
    success: bool
    drops: List[DropItemDetail]
    spent: float
    remaining_balance: float
    server_seed_hash: str

class TradeUpRequest(BaseModel):
    inventory_ids: List[int] = Field(..., min_length=10, max_length=10)

class DepositRequest(BaseModel):
    amount: float = Field(..., gt=0, le=10000)

class VerifyRollRequest(BaseModel):
    server_seed: str
    client_seed: str
    nonce: int
    sub_index: Optional[int] = 0

class RedeemCodeRequest(BaseModel):
    code: str = Field(..., min_length=6, max_length=6)

