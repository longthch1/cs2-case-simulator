@echo off
title CS2 Case Opening Simulator Platform
color 0E

echo =======================================================================
echo          CS2 CASE OPENING SIMULATOR - FULLSTACK WEB PLATFORM
echo =======================================================================
echo.
echo [1/3] Kiem tra moi truong Python...
python --version >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Python chua duoc cai dat hoac chua them vao PATH!
    pause
    exit /b 1
)

echo [2/3] Khoi dong Web Server tren cong 8000...
start "" http://localhost:8000

echo [3/3] Dang khoi chay FastAPI Uvicorn Server...
echo.
echo =======================================================================
echo  - Web Application:   http://localhost:8000
echo  - API Swagger Docs:  http://localhost:8000/api/docs
echo  - Prometheus Metric: http://localhost:8000/metrics
echo  - Tai khoan Admin:   admin / Admin@123456
echo  - Tai khoan Demo:    demoplayer / Demo@123456
echo =======================================================================
echo.

python -m uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload
pause
