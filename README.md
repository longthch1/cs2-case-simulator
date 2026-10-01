# CS2 Case Opening Simulator — PHP / MySQL / Apache

This branch is the Linux/server edition of the CS2 Case Opening Simulator.

## Target architecture

Browser
→ Apache2
→ PHP-FPM
→ PHP application
→ PDO
→ MySQL / InnoDB

The existing HTML/CSS/JavaScript/Three.js frontend is preserved in `static/`. Apache serves it through `public/`, and `public/api.php` provides the REST API.

## Main features

- 3D/roulette case opening UI
- Inventory and quick sell
- Trade-up contracts
- Daily rewards and giftcode
- Wallet demo balance
- Authentication and RBAC
- Provably Fair HMAC-SHA256 result generation
- MySQL/InnoDB transactions
- Session-cookie authentication
- CSRF protection
- Rate limiting
- Security/audit/application logging
- Health endpoint and Prometheus-compatible metrics
- Wazuh-ready Linux log paths

## Ubuntu install

See [deploy/README.md](deploy/README.md).

Fast path:

    git clone -b feature/php-mysql-apache https://github.com/longthch1/cs2-case-simulator.git
    cd cs2-case-simulator
    sudo bash deploy/install.sh

Then configure `.env`, create the MySQL database/user, import `database/schema.sql`, and run:

    php database/seed.php

Test:

    curl http://127.0.0.1/api/health
    curl http://127.0.0.1/api/cases

## Full original CS2 catalog

The PHP branch now carries the authoritative catalog from the original Python `app/seed_data.py` as `database/catalog.php`. It contains the original 42 cases and 1,161 source item entries (1,093 effective unique case/item IDs, matching the original SQLite primary-key behavior).

Run `php database/seed.php` after importing the schema to populate the complete catalog. The older `cases-data.js` file is not used as the server's authoritative seed source.

## Data migration

The legacy SQLite database can be migrated with:

    php database/migrate_sqlite.php --sqlite=/path/to/cs2_simulator.db

Legacy PBKDF2 credentials are stored separately and automatically upgraded to PHP password hashes after a successful login.

## Security notes

Do not put production passwords or secrets into Git. MySQL should listen only on localhost/private networking. HTTPS should be enabled before production traffic, and `APP_FORCE_SECURE_COOKIE=true` should be used once HTTPS is active.

## Wazuh readiness

The application writes:

- `/var/log/cs2-case-simulator/app.log`
- `/var/log/cs2-case-simulator/audit.log`
- `/var/log/cs2-case-simulator/security.log`

Apache writes access/error logs under `/var/log/apache2/` using the provided virtual host. These files can later be monitored by a Wazuh Agent.
