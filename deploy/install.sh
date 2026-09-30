#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/cs2-case-simulator"
LOG_DIR="/var/log/cs2-case-simulator"
STORAGE_DIR="/var/lib/cs2-case-simulator"

if [[ $EUID -ne 0 ]]; then
  echo "Run: sudo bash deploy/install.sh"
  exit 1
fi

apt-get update
apt-get install -y apache2 mysql-server php php-cli php-fpm php-mysql php-sqlite3 php-mbstring php-xml php-curl git unzip

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"

systemctl enable --now apache2
systemctl enable --now mysql
systemctl enable --now "${PHP_FPM_SERVICE}"

mkdir -p "${LOG_DIR}" "${STORAGE_DIR}"
chown -R www-data:www-data "${LOG_DIR}" "${STORAGE_DIR}"
chmod 0750 "${LOG_DIR}" "${STORAGE_DIR}"

rm -rf "${APP_DIR}/public/static"
ln -s "${APP_DIR}/static" "${APP_DIR}/public/static"

sed "s#php8.3-fpm.sock#php${PHP_VERSION}-fpm.sock#g" \
  "${APP_DIR}/apache/cs2-case-simulator.conf" \
  > /etc/apache2/sites-available/cs2-case-simulator.conf

a2enmod rewrite proxy proxy_fcgi headers
a2ensite cs2-case-simulator.conf
a2dissite 000-default.conf || true

apache2ctl configtest
systemctl reload apache2

echo
echo "Runtime ready."
echo "Configure .env, create the MySQL user/database, import database/schema.sql, then run:"
echo "  php database/seed.php"
echo
echo "Health check:"
echo "  curl http://127.0.0.1/api/health"
