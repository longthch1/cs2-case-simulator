@echo off
chcp 65001 >nul
echo ============================================================
echo   CS2 CASE OPENING SIMULATOR — PHP + APACHE + MYSQL
echo ============================================================
echo.

:: Check Docker
docker --version >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo [1/3] Kiểm tra Docker Compose...
    docker info >nul 2>&1
    if %ERRORLEVEL% EQU 0 (
        echo [OK] Docker daemon đang chạy! Khởi động stack Apache + PHP + MySQL...
        docker compose up -d
        echo.
        echo ============================================================
        echo   TRUY CẬP WEBSITE:
        echo   - CS2 Simulator Web (Apache): http://localhost
        echo   - phpMyAdmin Quản Lý DB:     http://localhost:8080
        echo   - Tài khoản Admin: admin / admin
        echo ============================================================
        start http://localhost
        goto end
    )
)

:: Check PHP CLI
php -v >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo [OK] Tìm thấy PHP CLI trên máy!
    echo Chạy PHP Built-in Server tại http://127.0.0.1:8000...
    echo Nhấn Ctrl+C để dừng server.
    start http://127.0.0.1:8000
    php -S 127.0.0.1:8000
    goto end
)

echo.
echo [CHÚ Ý] Để chạy ứng dụng này với Apache & MySQL:
echo  1. Cách 1 (Khuyên dùng): Bật Docker Desktop rồi chạy lại file start.bat này.
echo  2. Cách 2: Sử dụng XAMPP hoặc Laragon:
echo     - Mở XAMPP Control Panel, Start Apache và MySQL.
echo     - Vào phpMyAdmin (http://localhost/phpmyadmin), tạo database "cs2_simulator".
echo     - Import file sql/schema.sql và sql/seed_full_data.sql.
echo     - Copy thư mục này vào C:\xampp\htdocs\cs2-case-simulator.
echo     - Truy cập http://localhost/cs2-case-simulator/
echo.
pause

:end
