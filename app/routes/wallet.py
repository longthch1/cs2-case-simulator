import random
from datetime import datetime, timezone, timedelta
from typing import Optional
from fastapi import APIRouter, HTTPException, Depends, Request
from app.models import DepositRequest, RedeemCodeRequest
from app.database import get_db_connection
from app.auth import get_current_user, get_optional_user
from app.logging_config import audit_logger, logger

router = APIRouter(prefix="/api/wallet", tags=["Wallet & Banking"])

def parse_utc_timestamp(ts_str: str) -> Optional[datetime]:
    if not ts_str:
        return None
    s = str(ts_str).strip()
    try:
        if "T" in s:
            return datetime.fromisoformat(s.replace("Z", "+00:00")).astimezone(timezone.utc)
        return datetime.strptime(s[:19], "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
    except Exception:
        return None

def get_or_create_active_hourly_code(conn) -> dict:
    """
    Retrieves the currently valid hourly 6-digit giftcode.
    If no active code exists or the latest has expired, a new 6-digit random code is generated
    with a 1-hour expiration timestamp.
    """
    cursor = conn.cursor()
    now = datetime.now(timezone.utc)
    now_str = now.strftime("%Y-%m-%d %H:%M:%S")

    cursor.execute("""
        SELECT id, code, reward_amount, created_at, expires_at, is_active
        FROM hourly_codes
        WHERE expires_at > ? AND is_active = 1
        ORDER BY id DESC
        LIMIT 1
    """, (now_str,))
    row = cursor.fetchone()

    if row:
        exp_dt = parse_utc_timestamp(row['expires_at'])
        seconds_left = max(0, int((exp_dt - now).total_seconds())) if exp_dt else 0
        return {
            "id": row['id'],
            "code": row['code'],
            "reward_amount": row['reward_amount'],
            "expires_at": row['expires_at'],
            "seconds_left": seconds_left
        }

    # Generate new random 6-digit code (e.g. 582914)
    new_code = f"{random.randint(100000, 999999)}"
    expires_at = now + timedelta(hours=1)
    exp_str = expires_at.strftime("%Y-%m-%d %H:%M:%S")

    cursor.execute("""
        INSERT INTO hourly_codes (code, reward_amount, created_at, expires_at, is_active)
        VALUES (?, 100.0, ?, ?, 1)
    """, (new_code, now_str, exp_str))
    conn.commit()

    logger.info(f"Generated new hourly 6-digit code: {new_code}, valid until {exp_str}")

    return {
        "id": cursor.lastrowid,
        "code": new_code,
        "reward_amount": 100.0,
        "expires_at": exp_str,
        "seconds_left": 3600
    }

@router.get("/active-code")
async def get_active_code(current_user: Optional[dict] = Depends(get_optional_user)):
    """
    Retrieves the current 6-digit hourly giftcode, remaining time, and whether the user has redeemed it.
    """
    with get_db_connection() as conn:
        active_code = get_or_create_active_hourly_code(conn)
        has_redeemed = False

        if current_user:
            cursor = conn.cursor()
            cursor.execute("""
                SELECT id FROM code_redemptions
                WHERE user_id = ? AND code = ?
            """, (current_user['id'], active_code['code']))
            if cursor.fetchone():
                has_redeemed = True

        return {
            "code": active_code['code'],
            "reward_amount": active_code['reward_amount'],
            "seconds_left": active_code['seconds_left'],
            "expires_at": active_code['expires_at'],
            "has_redeemed": has_redeemed
        }

@router.post("/redeem-code")
async def redeem_code(req: RedeemCodeRequest, request: Request, current_user: dict = Depends(get_current_user)):
    """
    Redeems an active 6-digit hourly code for $100.00.
    Enforces 1 redemption per account per code.
    """
    ip = request.client.host if request.client else "unknown"
    submitted_code = req.code.strip()
    user_id = current_user['id']

    if len(submitted_code) != 6 or not submitted_code.isdigit():
        raise HTTPException(status_code=400, detail="Mã code phải gồm đúng 6 chữ số (ví dụ: 123456)!")

    with get_db_connection() as conn:
        active_code = get_or_create_active_hourly_code(conn)

        if submitted_code != active_code['code']:
            raise HTTPException(
                status_code=400, 
                detail="Mã code không chính xác hoặc đã hết hiệu lực. Mỗi mã chỉ có hiệu lực trong 1 tiếng!"
            )

        cursor = conn.cursor()
        cursor.execute("SELECT id FROM code_redemptions WHERE user_id = ? AND code = ?", (user_id, active_code['code']))
        if cursor.fetchone():
            raise HTTPException(
                status_code=400, 
                detail="Bạn đã sử dụng mã code này rồi! Mỗi tài khoản chỉ được nhập 1 lần cho mỗi mã sau 1 tiếng."
            )

        reward = active_code['reward_amount']
        cursor.execute("UPDATE users SET balance = balance + ? WHERE id = ?", (reward, user_id))
        cursor.execute("SELECT balance FROM users WHERE id = ?", (user_id,))
        new_balance = round(cursor.fetchone()['balance'], 2)

        # Record redemption
        cursor.execute("""
            INSERT INTO code_redemptions (user_id, code, amount)
            VALUES (?, ?, ?)
        """, (user_id, active_code['code'], reward))

        # Ledger transaction
        cursor.execute("""
            INSERT INTO transactions (user_id, type, amount, balance_after, description)
            VALUES (?, 'deposit', ?, ?, ?)
        """, (user_id, reward, new_balance, f"Nạp tiền qua Giftcode 1 tiếng: {active_code['code']}"))

        # Audit log
        cursor.execute("""
            INSERT INTO audit_logs (user_id, action, ip_address, details)
            VALUES (?, 'CODE_REDEEM', ?, ?)
        """, (user_id, ip, f"Redeemed code {active_code['code']} for +${reward:.2f}"))

        audit_logger.info(f"User {current_user['username']} redeemed code {active_code['code']}: +${reward:.2f}. New balance: ${new_balance:.2f}")

        return {
            "success": True,
            "amount": reward,
            "new_balance": new_balance,
            "message": f"Chúc mừng! Bạn đã nạp thành công +${reward:.2f} vào tài khoản từ mã code {active_code['code']}!"
        }

@router.post("/deposit")
async def deposit_funds(req: DepositRequest, current_user: dict = Depends(get_current_user)):
    """Deposits funds into the user's wallet."""
    user_id = current_user['id']
    amount = round(req.amount, 2)

    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("UPDATE users SET balance = balance + ? WHERE id = ?", (amount, user_id))
        cursor.execute("SELECT balance FROM users WHERE id = ?", (user_id,))
        new_balance = round(cursor.fetchone()['balance'], 2)

        cursor.execute("""
            INSERT INTO transactions (user_id, type, amount, balance_after, description)
            VALUES (?, 'deposit', ?, ?, ?)
        """, (user_id, amount, new_balance, f"Nạp +${amount:.2f}"))

        audit_logger.info(f"User {current_user['username']} deposited ${amount:.2f}. New balance: ${new_balance:.2f}")

        return {
            "success": True,
            "deposited": amount,
            "new_balance": new_balance
        }

@router.get("/transactions")
async def get_transactions(limit: int = 50, current_user: dict = Depends(get_current_user)):
    """Retrieves user financial transaction history."""
    user_id = current_user['id']
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("""
            SELECT id, type, amount, balance_after, description, created_at
            FROM transactions
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        """, (user_id, limit))
        txs = [dict(r) for r in cursor.fetchall()]
        return {"transactions": txs}
