# CampusPay — Deployment

CampusPay is a plain PHP 8.2 application: no Composer, no build step. Deploying
means copying files, creating one database, writing `.env`, and scheduling one
cron command.

## 1. Requirements

| Component | Minimum |
| --- | --- |
| PHP | 8.2 with `pdo_mysql`, `openssl`, `curl`, `mbstring`, `json` |
| Database | MariaDB 10.4+ or MySQL 8.0+ (InnoDB, utf8mb4) |
| Web server | Apache 2.4 with `mod_rewrite` (Nginx works; config below) |
| OpenSSL | AES-256-GCM support (standard in PHP builds) |
| Cron | Any scheduler able to run a PHP CLI command every minute |

`public/` is the only directory that may be reachable over HTTP. `app/`,
`database/`, `storage/`, `docs/`, `tests/`, and `.env` live outside it.

## 2. Files

1. Copy the project (the ZIP or a checkout) to the server.
2. Make `storage/logs/` and `storage/dev/` writable by the web-server user.
3. Copy `.env.example` to `.env` and fill it in (section 4).
4. Never copy a developer's `.env`, `storage/dev/admin-credentials.txt`,
   `storage/logs/app.log`, or backups into a production tree.

## 3. Database

Two equivalent paths. Use one, not both.

### A. Migrations (recommended)

```
php database/migrate.php
php database/seed-admin.php
```

`migrate.php` applies every `database/migrations/*.sql` file once, recording it
in `schema_migrations`. `seed-admin.php` creates the first administrator from
`ADMIN_EMAIL` / `ADMIN_PASSWORD`; if `ADMIN_PASSWORD` is empty it generates one,
prints it once, and writes it to `storage/dev/admin-credentials.txt` (delete
that file after saving the password).

### B. Fresh-install SQL

```
mysql -u root -p campuspay < database/campuspay.sql
php database/seed-admin.php
```

`database/campuspay.sql` is the complete schema plus the default
`system_settings` rows. It is generated from the same migrations, so both paths
end with identical tables. It does not contain an administrator or any secret.

### Database account

Create a dedicated user instead of using `root`:

```sql
CREATE DATABASE campuspay CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'campuspay'@'localhost' IDENTIFIED BY 'use-a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
  ON campuspay.* TO 'campuspay'@'localhost';
FLUSH PRIVILEGES;
```

Migrations need `CREATE`, `ALTER`, `INDEX`, `REFERENCES`. The application
itself only needs `SELECT`, `INSERT`, `UPDATE`, `DELETE`; revoke the rest after
installing if your process requires it.

## 4. Environment (`.env`)

| Key | Notes |
| --- | --- |
| `APP_NAME` | Shown in the UI and health endpoint |
| `APP_ENV` | `local` or `production`. Production enables secure cookies and HSTS over HTTPS |
| `APP_DEBUG` | Leave `false` outside development |
| `APP_KEY` | **Required.** 32 random bytes, base64-encoded. `php -r "echo base64_encode(random_bytes(32));"` — encrypts API secrets and signs nothing else. Losing it makes stored API secrets unreadable; see SECURITY.md |
| `APP_BASE_PATH` | URL prefix when served from a subdirectory (`/CampusPay`); empty when served from a domain root |
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASS` | Database connection |
| `SESSION_NAME` | Cookie name, default `CPSESSID` |
| `SESSION_LIFETIME_MINUTES` | Session lifetime |
| `DARAJA_ENV` | `sandbox` or `production` |
| `DARAJA_CONSUMER_KEY` `DARAJA_CONSUMER_SECRET` | Daraja app credentials |
| `DARAJA_SHORTCODE` | Paybill or till number that receives the money |
| `DARAJA_PASSKEY` | STK Push passkey for that shortcode |
| `DARAJA_TRANSACTION_TYPE` | `CustomerPayBillOnline` (paybill) or `CustomerBuyGoodsOnline` (till) |
| `DARAJA_CALLBACK_URL` | Public HTTPS URL Safaricom posts results to; empty derives `…/api/mpesa/callback/{token}` |
| `DARAJA_CALLBACK_TOKEN` | Random secret path segment; `php -r "echo bin2hex(random_bytes(16));"` |
| `DARAJA_CALLBACK_IPS` | Optional comma-separated Safaricom egress IPs; empty = token only |
| `ADMIN_EMAIL` `ADMIN_PASSWORD` | Used only by `seed-admin.php` |

The platform **fails closed**: if any of the five Daraja values or the callback
token is missing, no STK Push is attempted and the admin UI reports Daraja as
not configured. There is no simulation mode.

## 5. Web server

### Apache (local XAMPP or production vhost)

Subdirectory alias:

```apache
Alias /CampusPay "C:/xampp/htdocs/CampusPay/public"
<Directory "C:/xampp/htdocs/CampusPay/public">
    AllowOverride All
    Require all granted
</Directory>
```

Domain root:

```apache
<VirtualHost *:443>
    ServerName pay.example.com
    DocumentRoot /var/www/campuspay/public
    <Directory /var/www/campuspay/public>
        AllowOverride All
        Require all granted
    </Directory>
    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/pay.example.com.crt
    SSLCertificateKeyFile /etc/ssl/private/pay.example.com.key
</VirtualHost>
```

The shipped `.htaccess` files require `mod_rewrite`. `public/.htaccess` routes
non-files to `index.php`; the root `.htaccess` denies everything reachable
above `public/`.

### Nginx

```nginx
server {
    listen 443 ssl;
    server_name pay.example.com;
    root /var/www/campuspay/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

With Nginx, `APP_BASE_PATH` must be empty for a domain root, and the project
gives no protection above `root` because only `public/` is referenced.

## 6. Production hardening

1. `APP_ENV=production`, `APP_DEBUG=false`.
2. Serve HTTPS only; redirect port 80. HSTS is emitted automatically once the
   request is HTTPS in production.
3. Point `DARAJA_CALLBACK_URL` at the HTTPS callback URL. With
   `DARAJA_ENV=production`, the webhook service refuses plain-HTTP targets.
4. Run the web server and cron as an unprivileged user.
5. Keep `.env` readable only by that user (`chmod 600 .env`).
6. Remove `storage/dev/admin-credentials.txt` after the first login.
7. Restrict database access to the application host.
8. Keep the system clock synchronised (NTP); API timestamps allow ±5 minutes.

## 7. Cron

Every minute, run the upkeep task:

```
* * * * * php /var/www/campuspay/bin/campuspay-cron.php >> /var/log/campuspay-cron.log 2>&1
```

Windows Task Scheduler (this machine):

```
C:\xampp\php\php.exe C:\xampp\htdocs\CampusPay\bin\campuspay-cron.php
```

Options: `--limit=N` (reconciliation checks per run, default 25),
`--webhook-limit=N` (deliveries per run, default 20), `--skip-expire`,
`--skip-reconcile`, `--skip-webhooks`.

One run expires checkout windows, reconciles open M-Pesa attempts (if Daraja is
configured), and delivers due webhooks. Output is one line per task; see
OPERATIONS.md for reading it.

## 8. Post-deploy checklist

1. `GET /api/v1/health` returns `{"status":"ok",…}`.
2. Sign in at `/login` as the seeded administrator; change the password.
3. Create an integration (Integrations), then an API key (API Credentials);
   store the one-time secret in the integrating app.
4. Send one signed request and confirm a 200. A wrong signature must return 401.
5. With Daraja configured, open a checkout link and confirm the STK prompt.
6. Confirm a test payment, then verify the transaction page shows
   `provider_confirmed` with a receipt and the integrating app received the
   signed webhook.
7. Run `php tests/run.php` on the server (development/staging only; the suite
   creates and removes its own rows).
