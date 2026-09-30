import re
from fastapi import APIRouter, HTTPException, status, Request, Depends
from app.models import RegisterRequest, LoginRequest, TokenResponse, UserProfileResponse, ForgotPasswordRequest
from app.database import get_db_connection, hash_password, verify_password
from app.auth import create_access_token, get_current_user
from app.security import rate_limiter
from app.logging_config import logger, audit_logger
from app.config import settings

router = APIRouter(prefix="/api/auth", tags=["Authentication"])

@router.post("/register", response_model=TokenResponse)
async def register(req: RegisterRequest, request: Request):
    ip = request.client.host if request.client else "unknown"
    if not rate_limiter.is_allowed(f"register_{ip}", 10, 60):
        raise HTTPException(status_code=429, detail="Thao tác quá nhiều lần. Vui lòng chờ 1 phút.")

    # Validate email format (syntax check only, no SMTP verify needed)
    email_clean = req.email.strip().lower()
    if not re.match(r"^[^@\s]+@[^@\s]+\.[^@\s]+$", email_clean):
        raise HTTPException(status_code=400, detail="Email không đúng định dạng chuẩn (ví dụ: abc@def.com)")

    username_clean = req.username.strip()
    if len(username_clean) < 3:
        raise HTTPException(status_code=400, detail="Tên đăng nhập phải có ít nhất 3 ký tự")

    with get_db_connection() as conn:
        cursor = conn.cursor()
        
        # Check if username or email exists
        cursor.execute("SELECT id FROM users WHERE username = ? OR email = ?", (username_clean, email_clean))
        if cursor.fetchone():
            raise HTTPException(status_code=400, detail="Tên đăng nhập hoặc email đã được sử dụng")

        pwd_hash, salt = hash_password(req.password)
        cursor.execute("""
            INSERT INTO users (username, email, password_hash, salt, role, balance, daily_streak)
            VALUES (?, ?, ?, ?, 'user', ?, 0)
        """, (username_clean, email_clean, pwd_hash, salt, settings.USER_STARTING_BALANCE))
        user_id = cursor.lastrowid
        
        # Audit log
        cursor.execute("""
            INSERT INTO audit_logs (user_id, action, ip_address, details)
            VALUES (?, 'USER_REGISTER', ?, ?)
        """, (user_id, ip, f"Registered new account: {username_clean}"))
        
        audit_logger.info(f"User registered: {username_clean} (ID: {user_id}) from {ip}")

        token = create_access_token({"sub": user_id, "username": username_clean, "role": "user"})
        user_data = {
            "id": user_id,
            "username": username_clean,
            "email": email_clean,
            "role": "user",
            "balance": settings.USER_STARTING_BALANCE,
            "daily_streak": 0,
            "last_daily_claim": None,
            "avatar_url": ""
        }
        return {"access_token": token, "token_type": "Bearer", "user": user_data}

@router.post("/forgot-password")
async def forgot_password(req: ForgotPasswordRequest, request: Request):
    ip = request.client.host if request.client else "unknown"
    if not rate_limiter.is_allowed(f"forgot_{ip}", 8, 60):
        raise HTTPException(status_code=429, detail="Thao tác quá nhanh. Vui lòng thử lại sau 1 phút.")

    username_clean = req.username.strip()
    email_clean = req.email.strip().lower()

    if len(req.new_password) < 6:
        raise HTTPException(status_code=400, detail="Mật khẩu mới phải có ít nhất 6 ký tự")

    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("""
            SELECT id, username, email FROM users 
            WHERE username = ? AND LOWER(email) = ?
        """, (username_clean, email_clean))
        user = cursor.fetchone()
        
        if not user:
            raise HTTPException(status_code=404, detail="Không tìm thấy tài khoản với Tên đăng nhập và Email này")

        pwd_hash, salt = hash_password(req.new_password)
        cursor.execute("UPDATE users SET password_hash = ?, salt = ? WHERE id = ?", (pwd_hash, salt, user['id']))

        cursor.execute("""
            INSERT INTO audit_logs (user_id, action, ip_address, details)
            VALUES (?, 'PASSWORD_RESET', ?, ?)
        """, (user['id'], ip, f"Password reset for: {username_clean}"))
        audit_logger.info(f"Password reset for {username_clean} from {ip}")

        return {"success": True, "message": "Đặt lại mật khẩu thành công! Bạn có thể đăng nhập ngay."}

@router.post("/login", response_model=TokenResponse)
async def login(req: LoginRequest, request: Request):
    ip = request.client.host if request.client else "unknown"
    if not rate_limiter.is_allowed(f"login_{ip}", 20, 60):
        raise HTTPException(status_code=429, detail="Quá nhiều lần thử đăng nhập. Vui lòng chờ 1 phút.")

    identifier = req.username_or_email.strip()
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("""
            SELECT id, username, email, password_hash, salt, role, balance, daily_streak, last_daily_claim, avatar_url 
            FROM users 
            WHERE username = ? OR email = ?
        """, (identifier, identifier.lower()))
        user = cursor.fetchone()
        
        if not user or not verify_password(req.password, user['password_hash'], user['salt']):
            audit_logger.warning(f"Failed login attempt for identifier: {identifier} from {ip}")
            raise HTTPException(status_code=401, detail="Sai tên đăng nhập hoặc mật khẩu")

        cursor.execute("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?", (user['id'],))
        audit_logger.info(f"User logged in: {user['username']} (ID: {user['id']}) from {ip}")

        token = create_access_token({"sub": user['id'], "username": user['username'], "role": user['role']})
        user_data = {
            "id": user['id'],
            "username": user['username'],
            "email": user['email'],
            "role": user['role'],
            "balance": user['balance'],
            "daily_streak": user['daily_streak'] or 0,
            "last_daily_claim": str(user['last_daily_claim'] or ""),
            "avatar_url": user['avatar_url'] or ""
        }
        return {"access_token": token, "token_type": "Bearer", "user": user_data}


@router.get("/me")
async def get_me(current_user: dict = Depends(get_current_user)):
    with get_db_connection() as conn:
        cursor = conn.cursor()
        # Fetch summary stats
        cursor.execute("SELECT COUNT(*) as count, COALESCE(SUM(value), 0) as total_val FROM inventory WHERE user_id = ? AND is_sold = 0", (current_user['id'],))
        inv_stat = cursor.fetchone()
        
        cursor.execute("SELECT COUNT(*) as total_opened, COALESCE(SUM(cost), 0) as total_spent, COALESCE(SUM(payout), 0) as total_won FROM open_history WHERE user_id = ?", (current_user['id'],))
        open_stat = cursor.fetchone()

        return {
            "user": current_user,
            "stats": {
                "inventory_count": inv_stat['count'],
                "inventory_value": round(inv_stat['total_val'], 2),
                "total_cases_opened": open_stat['total_opened'],
                "total_spent": round(open_stat['total_spent'], 2),
                "total_won": round(open_stat['total_won'], 2)
            }
        }
