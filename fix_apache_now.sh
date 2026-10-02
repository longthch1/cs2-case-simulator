#!/usr/bin/env bash
# ==============================================================================
# CS2 Case Opening Simulator — Khắc phục nhanh lỗi trang mặc định Apache2
# Chạy script này bằng: sudo bash fix_apache_now.sh
# ==============================================================================

if [ "$EUID" -ne 0 ]; then
    echo "[LỖI] Vui lòng chạy với sudo: sudo bash fix_apache_now.sh"
    exit 1
fi

echo "[1/5] Xóa trang mặc định Ubuntu (index.html)..."
rm -f /var/www/html/index.html

# Nếu code đang nằm trong thư mục con cs2-case-simulator, sao chép thẳng ra /var/www/html
if [ -d "/var/www/html/cs2-case-simulator" ]; then
    echo "[2/5] Di chuyển mã nguồn từ thư mục con ra gốc /var/www/html..."
    cp -rf /var/www/html/cs2-case-simulator/* /var/www/html/
fi

# Hoặc nếu chạy từ thư mục hiện tại của repository
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "${SCRIPT_DIR}/index.php" ]; then
    echo "[2/5] Sao chép mã nguồn vào /var/www/html..."
    cp -rf "${SCRIPT_DIR}/"* /var/www/html/
fi

echo "[3/5] Kích hoạt module rewrite & cấu hình AllowOverride All..."
a2enmod rewrite headers deflate expires > /dev/null 2>&1 || true

if [ -f /etc/apache2/apache2.conf ]; then
    sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
fi

echo "[4/5] Tắt site 000-default và cấu hình DocumentRoot..."
a2dissite 000-default.conf > /dev/null 2>&1 || true
rm -f /etc/apache2/sites-enabled/000-default.conf

# Cập nhật cấu hình site
cat > /etc/apache2/sites-available/cs2-simulator.conf <<EOF
<VirtualHost *:80>
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
        SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=\$1
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/cs2_error.log
    CustomLog \${APACHE_LOG_DIR}/cs2_access.log combined
</VirtualHost>
EOF

a2ensite cs2-simulator.conf > /dev/null 2>&1

echo "[5/5] Phân quyền và khởi động lại Apache..."
mkdir -p /var/www/html/logs
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
chmod -R 777 /var/www/html/logs
systemctl restart apache2

echo ""
echo "============================================================"
echo "  ĐÃ KHẮC PHỤC THÀNH CÔNG!"
echo "  Bây giờ hãy tải lại trang trên trình duyệt (F5 hoặc Ctrl+F5) tại:"
echo "  http://$(hostname -I | awk '{print $1}')/ hoặc http://localhost/"
echo "============================================================"
