# Ubuntu deployment

Target: Ubuntu/Linux + Apache2 + PHP-FPM + MySQL.

## 1. Clone

sudo mkdir -p /var/www
sudo chown "$USER":"$USER" /var/www
cd /var/www
git clone -b feature/php-mysql-apache https://github.com/longthch1/cs2-case-simulator.git
cd cs2-case-simulator

## 2. Install runtime

sudo bash deploy/install.sh

## 3. Configure secrets

sudo cp .env.example .env
sudo chown root:www-data .env
sudo chmod 640 .env
# Set DB_PASSWORD and ADMIN_PASSWORD to strong production secrets.
# Keep .env out of Git.

## 4. Create database and user

Edit deploy/setup-mysql.sql and replace CHANGE_ME... first, then:
sudo mysql < deploy/setup-mysql.sql

Then import schema:
sudo mysql cs2_case_simulator < database/schema.sql

## 5. Seed catalog/admin

sudo -u www-data php database/seed.php

This reads the existing cases-data.js file, so the case/skin catalog is not duplicated into PHP.

## 6. Optional legacy SQLite migration

If you have the old SQLite database from the FastAPI branch:
php database/migrate_sqlite.php --sqlite=/path/to/cs2_simulator.db

Run it against an empty MySQL schema before normal traffic.

## 7. Test

curl http://127.0.0.1/api/health
curl http://127.0.0.1/api/cases

Open:
http://SERVER_IP/

## 8. HTTPS

After DNS points to the server, use Certbot for the Apache vhost and set:
APP_FORCE_SECURE_COOKIE=true
