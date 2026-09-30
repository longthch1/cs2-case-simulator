from fastapi import APIRouter, Depends, HTTPException, Request
from datetime import datetime, timezone, timedelta
from typing import Dict, Any
from app.auth import get_current_user
from app.database import get_db_connection
from app.logging_config import logger, audit_logger

router = APIRouter(prefix="/api/daily", tags=["Daily Rewards"])

DAILY_REWARDS = {
    1: {"day": 1, "reward": 10.00, "title": "Khởi Đầu", "icon": "fa-gift"},
    2: {"day": 2, "reward": 15.00, "title": "Kiên Trì", "icon": "fa-cube"},
    3: {"day": 3, "reward": 25.00, "title": "Bền Bỉ", "icon": "fa-shield-halved"},
    4: {"day": 4, "reward": 40.00, "title": "Cao Thủ", "icon": "fa-medal"},
    5: {"day": 5, "reward": 60.00, "title": "Vinh Quang", "icon": "fa-gem"},
    6: {"day": 6, "reward": 90.00, "title": "Bậc Thầy", "icon": "fa-fire"},
    7: {"day": 7, "reward": 150.00, "title": "★ ĐẠI THƯỞNG DAO VÀNG", "icon": "fa-crown"}
}

def parse_sqlite_timestamp(ts_str: Any) -> datetime:
    """Safely parse SQLite CURRENT_TIMESTAMP or ISO timestamp to UTC datetime."""
    if not ts_str:
        return None
    s = str(ts_str).strip()
    try:
        if "T" in s:
            return datetime.fromisoformat(s.replace("Z", "+00:00")).astimezone(timezone.utc)
        return datetime.strptime(s[:19], "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
    except Exception:
        return None

def check_streak_status(user: dict):
    last_claim_str = user.get("last_daily_claim")
    streak = int(user.get("daily_streak") or 0)
    now = datetime.now(timezone.utc)
    today = now.date()

    if not last_claim_str:
        return {
            "can_claim": True,
            "current_streak": 0,
            "next_day": 1,
            "hours_left": 0,
            "last_claim": None
        }

    last_claim_dt = parse_sqlite_timestamp(last_claim_str)
    if not last_claim_dt:
        return {
            "can_claim": True,
            "current_streak": 0,
            "next_day": 1,
            "hours_left": 0,
            "last_claim": None
        }

    last_claim_date = last_claim_dt.date()
    days_diff = (today - last_claim_date).days

    if days_diff == 0:
        # Already claimed today
        next_midnight = datetime.combine(today + timedelta(days=1), datetime.min.time(), tzinfo=timezone.utc)
        hours_left = max(1, int((next_midnight - now).total_seconds() // 3600))
        return {
            "can_claim": False,
            "current_streak": streak,
            "next_day": min(7, (streak % 7) + 1),
            "hours_left": hours_left,
            "last_claim": str(last_claim_str)
        }
    elif days_diff == 1:
        # Consecutive day claim
        next_day = min(7, (streak % 7) + 1)
        return {
            "can_claim": True,
            "current_streak": streak,
            "next_day": next_day,
            "hours_left": 0,
            "last_claim": str(last_claim_str)
        }
    else:
        # Missed at least one calendar day -> streak reset to Day 1
        return {
            "can_claim": True,
            "current_streak": 0,
            "next_day": 1,
            "hours_left": 0,
            "last_claim": str(last_claim_str),
            "streak_reset": True
        }

@router.get("/status")
async def get_daily_status(current_user: dict = Depends(get_current_user)):
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT id, daily_streak, last_daily_claim, balance FROM users WHERE id = ?", (current_user["id"],))
        user = dict(cursor.fetchone())

    status_info = check_streak_status(user)
    schedule = []
    
    current_streak = status_info["current_streak"]
    can_claim = status_info["can_claim"]
    next_day = status_info["next_day"]

    for d in range(1, 8):
        # Claimed logic:
        # If can_claim is False (already claimed today), any day <= current_streak is claimed
        # If can_claim is True, any day < next_day is claimed
        if not can_claim:
            claimed = d <= current_streak
        else:
            claimed = d < next_day

        is_today = (d == next_day and can_claim)

        schedule.append({
            "day": d,
            "reward": DAILY_REWARDS[d]["reward"],
            "title": DAILY_REWARDS[d]["title"],
            "icon": DAILY_REWARDS[d]["icon"],
            "claimed": claimed,
            "is_today": is_today
        })

    return {
        "can_claim": can_claim,
        "current_streak": current_streak,
        "next_day": next_day,
        "next_reward": DAILY_REWARDS[next_day]["reward"],
        "hours_left": status_info["hours_left"],
        "schedule": schedule
    }

@router.post("/claim")
async def claim_daily_reward(request: Request, current_user: dict = Depends(get_current_user)):
    ip = request.client.host if request.client else "unknown"
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT id, username, balance, daily_streak, last_daily_claim FROM users WHERE id = ?", (current_user["id"],))
        user = cursor.fetchone()
        if not user:
            raise HTTPException(status_code=404, detail="Không tìm thấy người dùng")
        user = dict(user)

        status_info = check_streak_status(user)
        if not status_info["can_claim"]:
            raise HTTPException(
                status_code=400, 
                detail=f"Bạn đã điểm danh hôm nay rồi! Vui lòng quay lại sau {status_info['hours_left']} giờ."
            )

        next_day = status_info["next_day"]
        reward_amount = DAILY_REWARDS[next_day]["reward"]
        new_balance = round(user["balance"] + reward_amount, 2)
        new_streak = next_day

        now_str = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

        cursor.execute("""
            UPDATE users 
            SET balance = ?, daily_streak = ?, last_daily_claim = ? 
            WHERE id = ?
        """, (new_balance, new_streak, now_str, user["id"]))

        cursor.execute("""
            INSERT INTO transactions (user_id, type, amount, balance_after, description)
            VALUES (?, 'daily_reward', ?, ?, ?)
        """, (user["id"], reward_amount, new_balance, f"Điểm danh nhận quà ngày {next_day}/7"))

        cursor.execute("""
            INSERT INTO audit_logs (user_id, action, ip_address, details)
            VALUES (?, 'DAILY_CLAIM', ?, ?)
        """, (user["id"], ip, f"Claimed Day {next_day} daily reward (+${reward_amount})"))

        audit_logger.info(f"User {user['username']} claimed Day {next_day} reward: +${reward_amount} (New balance: ${new_balance})")

        return {
            "success": True,
            "day": next_day,
            "amount": reward_amount,
            "new_balance": new_balance,
            "streak": new_streak,
            "message": f"Chúc mừng! Bạn đã nhận thành công +${reward_amount:.2f} quà đăng nhập Ngày {next_day}!"
        }
