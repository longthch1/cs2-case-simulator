# CS2 Case Simulator — PHP / MySQL / Apache

This branch migrates the runtime from FastAPI + SQLite + Uvicorn + Nginx to PHP 8.2+, MySQL/InnoDB and Apache2 + PHP-FPM.

The existing HTML/CSS/JavaScript/Three.js frontend remains under static/. Apache exposes the app through public/; public/index.php serves the existing frontend and public/api.php is the API front controller.

## Stack

- PHP 8.2+
- Apache2
- PHP-FPM
- MySQL 8.x
- PDO / PDO_MYSQL
- PHP session cookies for authentication
- InnoDB transactions
- Application, security and audit logs
- Prometheus-compatible /metrics
- Wazuh-ready Linux log paths

## API compatibility

The branch keeps the frontend-facing API paths:
- /api/auth/*
- /api/cases/*
- /api/inventory/*
- /api/tradeup
- /api/wallet/*
- /api/daily/*
- /api/admin/*
- /api/health
- /metrics

## Security

Authentication uses a server-side PHP session instead of JWT persistence in localStorage. Passwords use password_hash()/password_verify(). Database access uses PDO prepared statements and InnoDB transactions. Mutating API requests use a CSRF token.

Security logs:
- /var/log/cs2-case-simulator/security.log
- /var/log/cs2-case-simulator/audit.log

Apache logs:
- /var/log/apache2/cs2-case-simulator-access.log
- /var/log/apache2/cs2-case-simulator-error.log

## Wazuh preparation

Wazuh Agent can later monitor Apache access/error logs, PHP security/audit logs and Ubuntu system/authentication logs. This branch prepares those sources but does not install/configure Wazuh.
