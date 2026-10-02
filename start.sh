#!/usr/bin/env bash
# ==============================================================================
# CS2 Case Opening Simulator — Linux Service Quick Starter
# ==============================================================================

echo "============================================================"
echo "  CS2 Case Opening Simulator — Khởi động dịch vụ Apache & MySQL"
echo "============================================================"

# Kiểm tra Docker nếu có
if command -v docker &> /dev/null && docker info &> /dev/null; then
    echo "[OK] Khởi động qua Docker Compose..."
    docker compose up -d
    echo "Website đang chạy tại: http://localhost"
    exit 0
fi

# Chạy dịch vụ hệ thống trên Ubuntu
if command -v systemctl &> /dev/null; then
    echo "[OK] Khởi động Apache2 và MySQL trên hệ thống..."
    sudo systemctl restart apache2 mysql
    sudo systemctl status apache2 --no-pager
    echo ""
    echo "Website đã khởi động thành công!"
    exit 0
fi

# Fallback: Chạy PHP built-in server nếu không có Apache
if command -v php &> /dev/null; then
    echo "[OK] Chạy PHP built-in server tại http://0.0.0.0:8000..."
    php -S 0.0.0.0:8000
    exit 0
fi

echo "Không tìm thấy Apache hoặc Docker. Vui lòng chạy: sudo bash setup_ubuntu.sh"
