#!/usr/bin/env bash
# ==============================================================================
# CS2 Case Opening Simulator — Automated Setup Script for Ubuntu 20.04 / 22.04 / 24.04
# Installs Apache2, PHP 8.x, MySQL 8.x, configures database and deploys website.
# ==============================================================================

set -e

# Color definitions
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${CYAN}============================================================${NC}"
echo -e "${CYAN}   CS2 CASE OPENING SIMULATOR — UBUNTU AUTO DEPLOYMENT      ${NC}"
echo -e "${CYAN}   Stack: Apache 2.4 + PHP 8.x + MySQL 8.x                 ${NC}"
echo -e "${CYAN}============================================================${NC}"
echo ""

# Check root privileges
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}[LỖI] Vui lòng chạy script này với quyền root (dùng sudo):${NC}"
    echo -e "${YELLOW}sudo bash setup_ubuntu.sh${NC}"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_DIR="/var/www/html/cs2-case-simulator"

DB_NAME="cs2_simulator"
DB_USER="cs2_user"
DB_PASS="cs2_password"

# ── 1. Cập nhật hệ thống & Cài đặt gói ─────────────────────────────────────────
echo -e "${BLUE}[1/6] Đang cập nhật package và cài đặt Apache, PHP, MySQL...${NC}"
apt-get update -y

# Install Apache, MySQL, PHP and extensions
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    apache2 \
    mysql-server \
    php \
    php-cli \
    php-fpm \
    php-mysql \
    php-mbstring \
    php-curl \
    php-xml \
    php-zip \
    curl \
    git \
    unzip

echo -e "${GREEN}[OK] Đã cài đặt xong các gói cần thiết!${NC}"

# ── 2. Kích hoạt module Apache ────────────────────────────────────────────────
echo -e "${BLUE}[2/6] Đang cấu hình và kích hoạt Apache modules...${NC}"
a2enmod rewrite headers deflate expires ssl > /dev/null 2>&1 || true

systemctl enable apache2
systemctl restart apache2
echo -e "${GREEN}[OK] Apache đã sẵn sàng với mod_rewrite và headers!${NC}"

# ── 3. Khởi tạo Cơ sở dữ liệu MySQL ───────────────────────────────────────────
echo -e "${BLUE}[3/6] Đang khởi tạo Database MySQL và nạp dữ liệu CS2...${NC}"
systemctl enable mysql
systemctl start mysql

# Create database and user
mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED WITH mysql_native_password BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

echo -e "${GREEN}[OK] Đã tạo database '${DB_NAME}' và user '${DB_USER}'!${NC}"

# Import Schema and Seed Data
if [ -f "${SCRIPT_DIR}/sql/schema.sql" ]; then
    echo -e "${YELLOW}  -> Đang import cấu trúc bảng (schema.sql)...${NC}"
    mysql -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${SCRIPT_DIR}/sql/schema.sql"
fi

if [ -f "${SCRIPT_DIR}/sql/seed_full_data.sql" ]; then
    echo -e "${YELLOW}  -> Đang nạp 47 rương và 1,293 skins CS2 (seed_full_data.sql)...${NC}"
    mysql -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${SCRIPT_DIR}/sql/seed_full_data.sql"
    echo -e "${GREEN}[OK] Đã nạp thành công toàn bộ rương và skin CS2!${NC}"
fi

# ── 4. Triển khai mã nguồn vào /var/www/html/cs2-case-simulator ───────────────
echo -e "${BLUE}[4/6] Đang sao chép mã nguồn vào ${TARGET_DIR}...${NC}"
mkdir -p "${TARGET_DIR}"
cp -r "${SCRIPT_DIR}/"* "${TARGET_DIR}/"

# Tạo file .env cho Ubuntu
cat > "${TARGET_DIR}/.env" <<EOF
DB_HOST=localhost
DB_PORT=3306
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
APP_SECRET=cs2-case-simulator-production-secret-key-2024
APP_ENV=production
EOF

# ── 5. Phân quyền thư mục ─────────────────────────────────────────────────────
echo -e "${BLUE}[5/6] Đang phân quyền thư mục cho web server (www-data)...${NC}"
mkdir -p "${TARGET_DIR}/logs"
chown -R www-data:www-data "${TARGET_DIR}"
find "${TARGET_DIR}" -type d -exec chmod 755 {} +
find "${TARGET_DIR}" -type f -exec chmod 644 {} +
chmod -R 775 "${TARGET_DIR}/logs"
chmod +x "${TARGET_DIR}/setup_ubuntu.sh" 2>/dev/null || true

# ── 6. Cấu hình VirtualHost Apache ────────────────────────────────────────────
echo -e "${BLUE}[6/6] Đang kích hoạt VirtualHost Apache...${NC}"
VHOST_FILE="/etc/apache2/sites-available/cs2-simulator.conf"

if [ -f "${SCRIPT_DIR}/cs2-simulator.conf" ]; then
    cp "${SCRIPT_DIR}/cs2-simulator.conf" "${VHOST_FILE}"
else
    cat > "${VHOST_FILE}" <<EOF
<VirtualHost *:80>
    DocumentRoot ${TARGET_DIR}

    <Directory ${TARGET_DIR}>
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
fi

# Kích hoạt site và khởi động lại Apache
a2ensite cs2-simulator.conf > /dev/null 2>&1
a2dissite 000-default.conf > /dev/null 2>&1 || true
systemctl restart apache2

# Lấy địa chỉ IP máy chủ
SERVER_IP=$(hostname -I | awk '{print $1}' 2>/dev/null || echo "127.0.0.1")

echo ""
echo -e "${GREEN}============================================================${NC}"
echo -e "${GREEN}   🎉 CÀI ĐẶT THÀNH CÔNG CS2 CASE OPENING SIMULATOR!        ${NC}"
echo -e "${GREEN}============================================================${NC}"
echo ""
echo -e "${YELLOW}🌐 ĐỊA CHỈ TRUY CẬP WEBSITE:${NC}"
echo -e "   - Cục bộ (Local):   ${CYAN}http://localhost${NC}"
echo -e "   - Mạng ngoài (IP):  ${CYAN}http://${SERVER_IP}${NC}"
echo ""
echo -e "${YELLOW}🔑 THÔNG TIN TÀI KHOẢN ADMIN QUẢN TRỊ:${NC}"
echo -e "   - Tên đăng nhập:    ${CYAN}admin${NC}"
echo -e "   - Mật khẩu:         ${CYAN}admin${NC}"
echo ""
echo -e "${YELLOW}🗄️ THÔNG TIN CƠ SỞ DỮ LIỆU MYSQL:${NC}"
echo -e "   - Host:             ${CYAN}localhost:3306${NC}"
echo -e "   - Database:         ${CYAN}${DB_NAME}${NC}"
echo -e "   - User:             ${CYAN}${DB_USER}${NC}"
echo -e "   - Password:         ${CYAN}${DB_PASS}${NC}"
echo ""
echo -e "${YELLOW}📋 CÁC LỆNH HỮU ÍCH:${NC}"
echo -e "   - Xem log web:      ${CYAN}tail -f ${TARGET_DIR}/logs/app.log${NC}"
echo -e "   - Khởi động lại:    ${CYAN}sudo systemctl restart apache2 mysql${NC}"
echo -e "${GREEN}============================================================${NC}"
