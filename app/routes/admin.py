import random
from datetime import datetime, timezone, timedelta
from typing import Optional
from pydantic import BaseModel, Field
from fastapi import APIRouter, HTTPException, Depends, Query
from app.database import get_db_connection
from app.auth import require_admin
from app.monitoring import metrics
from app.config import LOGS_DIR
from app.logging_config import audit_logger, logger
from app.routes.wallet import get_or_create_active_hourly_code

router = APIRouter(prefix="/api/admin", tags=["Admin & Management"])

class AdjustBalanceModel(BaseModel):
    amount: float
    mode: str = Field(default="set", description="'set' to overwrite, 'add' to increment")

class UpdateRoleModel(BaseModel):
    role: str = Field(..., pattern="^(admin|user)$")

class UpdateCaseModel(BaseModel):
    price: float = Field(..., ge=1.0, le=100.0)
    is_active: Optional[int] = 1

class SetRewardModel(BaseModel):
    reward_amount: float = Field(..., ge=1.0, le=1000.0)

@router.get("/overview")
async def get_admin_overview(admin_user: dict = Depends(require_admin)):
    """Provides platform metrics, economic totals, system health, and giftcode telemetry."""
    with get_db_connection() as conn:
        cursor = conn.cursor()
        
        cursor.execute("SELECT COUNT(*) as user_count, COALESCE(SUM(balance), 0) as total_balances FROM users")
        u_stat = cursor.fetchone()

        cursor.execute("SELECT COUNT(*) as total_cases, COALESCE(SUM(cost), 0) as total_spent, COALESCE(SUM(payout), 0) as total_payout FROM open_history")
        o_stat = cursor.fetchone()

        cursor.execute("SELECT COUNT(*) as total_skins FROM inventory WHERE is_sold = 0")
        inv_stat = cursor.fetchone()

        cursor.execute("SELECT COUNT(*) as total_sold FROM inventory WHERE is_sold = 1")
        sold_stat = cursor.fetchone()

        cursor.execute("SELECT COUNT(*) as count FROM tradeup_history")
        tu_stat = cursor.fetchone()

        # Giftcode stats
        cursor.execute("SELECT COUNT(*) as total_redeems, COALESCE(SUM(amount), 0) as total_gift_amount FROM code_redemptions")
        code_stat = cursor.fetchone()

        active_code = get_or_create_active_hourly_code(conn)

        total_spent = o_stat['total_spent']
        total_payout = o_stat['total_payout']
        house_profit = round(total_spent - total_payout, 2)
        rtp_rate = round((total_payout / total_spent * 100), 2) if total_spent > 0 else 0.0

        sys_stats = metrics.get_system_stats()

        return {
            "platform": {
                "total_registered_users": u_stat['user_count'],
                "total_circulating_balance": round(u_stat['total_balances'], 2),
                "total_cases_opened": o_stat['total_cases'],
                "total_revenue_usd": round(total_spent, 2),
                "total_payout_usd": round(total_payout, 2),
                "house_profit_usd": house_profit,
                "rtp_percentage": rtp_rate,
                "active_items_in_inventories": inv_stat['total_skins'],
                "total_skins_sold": sold_stat['total_sold'],
                "tradeups_completed": tu_stat['count'],
                "total_code_redemptions": code_stat['total_redeems'],
                "total_gift_distributed": round(code_stat['total_gift_amount'], 2),
                "active_hourly_code": active_code['code'],
                "code_expires_in_secs": active_code['seconds_left']
            },
            "system_health": sys_stats
        }

@router.get("/users")
async def get_admin_users(
    limit: int = 100, 
    q: Optional[str] = None, 
    role: Optional[str] = None,
    admin_user: dict = Depends(require_admin)
):
    """Lists registered users with search, role filtering, and activity counters."""
    with get_db_connection() as conn:
        cursor = conn.cursor()
        
        query = """
            SELECT u.id, u.username, u.email, u.role, u.balance, u.daily_streak, u.created_at, u.last_login,
                   COUNT(DISTINCT o.id) as cases_opened,
                   COUNT(DISTINCT i.id) as inventory_count,
                   COALESCE(SUM(o.cost), 0) as total_spent
            FROM users u
            LEFT JOIN open_history o ON u.id = o.user_id
            LEFT JOIN inventory i ON u.id = i.user_id AND i.is_sold = 0
            WHERE 1=1
        """
        params = []
        if q:
            query += " AND (u.username LIKE ? OR u.email LIKE ?)"
            params.extend([f"%{q}%", f"%{q}%"])
        if role:
            query += " AND u.role = ?"
            params.append(role)

        query += " GROUP BY u.id ORDER BY u.created_at DESC LIMIT ?"
        params.append(limit)

        cursor.execute(query, tuple(params))
        users = [dict(r) for r in cursor.fetchall()]
        return {"users": users, "total": len(users)}

@router.post("/users/{target_user_id}/balance")
async def adjust_user_balance(
    target_user_id: int, 
    req: AdjustBalanceModel, 
    admin_user: dict = Depends(require_admin)
):
    """Admin function to adjust user balance (set or add)."""
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT id, username, balance FROM users WHERE id = ?", (target_user_id,))
        target = cursor.fetchone()
        if not target:
            raise HTTPException(status_code=404, detail="Không tìm thấy người dùng")

        current_bal = target['balance']
        if req.mode == "add":
            new_balance = round(current_bal + req.amount, 2)
            delta = req.amount
        else: # set
            new_balance = round(req.amount, 2)
            delta = round(new_balance - current_bal, 2)

        if new_balance < 0:
            raise HTTPException(status_code=400, detail="Số dư không thể âm!")

        cursor.execute("UPDATE users SET balance = ? WHERE id = ?", (new_balance, target_user_id))
        
        cursor.execute("""
            INSERT INTO transactions (user_id, type, amount, balance_after, description)
            VALUES (?, 'admin_adjustment', ?, ?, ?)
        """, (target_user_id, delta, new_balance, f"Admin {admin_user['username']} điều chỉnh số dư ({req.mode}: {req.amount})"))

        audit_logger.warning(f"Admin {admin_user['username']} adjusted balance for {target['username']} from ${current_bal} to ${new_balance}")

        return {
            "success": True, 
            "user_id": target_user_id, 
            "username": target['username'],
            "old_balance": current_bal, 
            "new_balance": new_balance,
            "message": f"Đã cập nhật số dư cho {target['username']} thành ${new_balance:.2f}"
        }

@router.post("/users/{target_user_id}/role")
async def update_user_role(
    target_user_id: int, 
    req: UpdateRoleModel, 
    admin_user: dict = Depends(require_admin)
):
    """Changes user role between 'admin' and 'user'."""
    if target_user_id == admin_user['id'] and req.role != "admin":
        raise HTTPException(status_code=400, detail="Không thể tự hạ quyền của chính bạn!")

    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT username, role FROM users WHERE id = ?", (target_user_id,))
        target = cursor.fetchone()
        if not target:
            raise HTTPException(status_code=404, detail="Không tìm thấy người dùng")

        cursor.execute("UPDATE users SET role = ? WHERE id = ?", (req.role, target_user_id))
        audit_logger.warning(f"Admin {admin_user['username']} changed role of {target['username']} to {req.role}")

        return {
            "success": True,
            "user_id": target_user_id,
            "username": target['username'],
            "new_role": req.role,
            "message": f"Đã cập nhật quyền của {target['username']} thành {req.role.upper()}"
        }

@router.get("/users/{target_user_id}/inventory")
async def get_user_inventory_admin(target_user_id: int, admin_user: dict = Depends(require_admin)):
    """Admin inspection of a user's items."""
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT username FROM users WHERE id = ?", (target_user_id,))
        target = cursor.fetchone()
        if not target:
            raise HTTPException(status_code=404, detail="Không tìm thấy người dùng")

        cursor.execute("""
            SELECT i.id, i.float_value, i.wear_name, i.is_stattrak, i.value, i.is_sold, i.acquired_at,
                   s.name, s.weapon, s.skin_name, s.rarity_tier, s.rarity_name, s.rarity_color, s.image
            FROM inventory i
            JOIN skins s ON i.skin_id = s.id
            WHERE i.user_id = ?
            ORDER BY i.acquired_at DESC
        """, (target_user_id,))
        items = [dict(r) for r in cursor.fetchall()]
        return {"username": target['username'], "items": items, "count": len(items)}

@router.get("/cases")
async def get_admin_cases(admin_user: dict = Depends(require_admin)):
    """Lists all cases with pricing and opening counters."""
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("""
            SELECT c.id, c.name, c.description, c.price, c.key_price, c.image, c.is_active,
                   COUNT(DISTINCT s.id) as total_items,
                   COUNT(DISTINCT o.id) as total_opened
            FROM cases c
            LEFT JOIN skins s ON c.id = s.case_id
            LEFT JOIN open_history o ON c.id = o.case_id
            GROUP BY c.id
            ORDER BY c.price DESC
        """)
        cases = [dict(r) for r in cursor.fetchall()]
        return {"cases": cases}

@router.post("/cases/{case_id}")
async def update_admin_case(case_id: str, req: UpdateCaseModel, admin_user: dict = Depends(require_admin)):
    """Updates case opening price or toggles case active status."""
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT name FROM cases WHERE id = ?", (case_id,))
        row = cursor.fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="Không tìm thấy rương")

        cursor.execute("UPDATE cases SET price = ?, is_active = ? WHERE id = ?", (round(req.price, 2), req.is_active, case_id))
        audit_logger.info(f"Admin {admin_user['username']} updated case {row['name']} to ${req.price:.2f} (active={req.is_active})")
        return {"success": True, "case_id": case_id, "price": req.price, "is_active": req.is_active, "message": f"Cập nhật rương {row['name']} thành công!"}

@router.get("/giftcode")
async def get_admin_giftcode(admin_user: dict = Depends(require_admin)):
    """Gets current active code and recent redemption history."""
    with get_db_connection() as conn:
        active_code = get_or_create_active_hourly_code(conn)
        cursor = conn.cursor()
        cursor.execute("""
            SELECT r.id, r.code, r.amount, r.redeemed_at, u.username, u.email
            FROM code_redemptions r
            JOIN users u ON r.user_id = u.id
            ORDER BY r.redeemed_at DESC
            LIMIT 50
        """)
        recent_redemptions = [dict(r) for r in cursor.fetchall()]
        
        cursor.execute("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM code_redemptions WHERE code = ?", (active_code['code'],))
        code_stats = cursor.fetchone()

        return {
            "active_code": active_code,
            "current_code_redemptions": code_stats['count'],
            "current_code_total_paid": code_stats['total'],
            "recent_redemptions": recent_redemptions
        }

@router.post("/giftcode/generate")
async def force_generate_code(admin_user: dict = Depends(require_admin)):
    """Force creates a brand-new 6-digit giftcode immediately, expiring any old code."""
    with get_db_connection() as conn:
        cursor = conn.cursor()
        now = datetime.now(timezone.utc)
        now_str = now.strftime("%Y-%m-%d %H:%M:%S")

        # Expire current codes
        cursor.execute("UPDATE hourly_codes SET is_active = 0, expires_at = ? WHERE expires_at > ? AND is_active = 1", (now_str, now_str))

        # Create new code
        new_code = f"{random.randint(100000, 999999)}"
        expires_at = (now + timedelta(hours=1)).strftime("%Y-%m-%d %H:%M:%S")

        cursor.execute("""
            INSERT INTO hourly_codes (code, reward_amount, created_at, expires_at, is_active)
            VALUES (?, 100.0, ?, ?, 1)
        """, (new_code, now_str, expires_at))

        audit_logger.warning(f"Admin {admin_user['username']} force-generated new giftcode: {new_code}")

        return {
            "success": True,
            "code": new_code,
            "reward_amount": 100.0,
            "expires_at": expires_at,
            "message": f"Đã phát sinh mã Giftcode mới: {new_code} (Hiệu lực 1 tiếng)"
        }

@router.post("/giftcode/reward")
async def set_giftcode_reward(req: SetRewardModel, admin_user: dict = Depends(require_admin)):
    """Sets reward value for the active giftcode."""
    with get_db_connection() as conn:
        cursor = conn.cursor()
        active = get_or_create_active_hourly_code(conn)
        cursor.execute("UPDATE hourly_codes SET reward_amount = ? WHERE id = ?", (round(req.reward_amount, 2), active['id']))
        audit_logger.warning(f"Admin {admin_user['username']} changed code {active['code']} reward to ${req.reward_amount:.2f}")
        return {"success": True, "reward_amount": req.reward_amount, "message": f"Đã đổi phần thưởng giftcode thành ${req.reward_amount:.2f}"}

@router.get("/logs")
async def get_system_logs(log_type: str = "app", lines: int = 150, admin_user: dict = Depends(require_admin)):
    """Fetches latest server or audit logs for real-time monitoring."""
    target_file = LOGS_DIR / ("audit.log" if log_type == "audit" else "app.log")
    if not target_file.exists():
        return {"logs": ["Log file does not exist yet."]}

    try:
        with open(target_file, "r", encoding="utf-8", errors="replace") as f:
            all_lines = f.readlines()
            recent_lines = all_lines[-lines:] if len(all_lines) > lines else all_lines
            return {"logs": [line.strip() for line in recent_lines]}
    except Exception as e:
        return {"logs": [f"Error reading log file: {str(e)}"]}

@router.get("/audit")
async def get_audit_trail(limit: int = 100, admin_user: dict = Depends(require_admin)):
    """Retrieves structured security audit records."""
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("""
            SELECT a.id, a.user_id, u.username, a.action, a.ip_address, a.details, a.created_at
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.id
            ORDER BY a.created_at DESC
            LIMIT ?
        """, (limit,))
        records = [dict(r) for r in cursor.fetchall()]
        return {"audit_trail": records}
