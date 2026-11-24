# CampusPay

Independent M-Pesa payment platform. API-first, own database, own admin.
It can serve CampusFind through an authenticated API but never depends on it.

Stack: PHP 8.2 · MariaDB · PDO · Bootstrap 5.3 · vanilla JS. No framework by design.

Local URL: `http://localhost/CampusPay/` (Apache Alias → `public/`).

## Status

| Stage | Scope | State |
| --- | --- | --- |
| 1 | Architecture, database design, schema | Done |
| 2 | Foundation, admin authentication | Done |
| 3 | Admin dashboard and public interface | Done |
| 4 | Payment requests, checkout links, transactions, integrations | Done |
| 5 | Daraja sandbox integration, callbacks, reconciliation | Done |
| 6 | Signed REST API, API credentials, webhooks, CampusFind client | Done |
| 7 | Security hardening, disputes, test suite | Done |
| 8 | Documentation, fresh-install SQL, delivery package | Done |

## Documentation

| Doc | What it covers |
| --- | --- |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | System overview, money path, decisions |
| [`docs/DATABASE-DESIGN.md`](docs/DATABASE-DESIGN.md) | Tables, keys, migrations, retention |
| [`docs/API.md`](docs/API.md) | HMAC signing, endpoints, errors, rate limit |
| [`docs/WEBHOOKS.md`](docs/WEBHOOKS.md) | Events, signatures, retries, rotation |
| [`docs/SETTLEMENT.md`](docs/SETTLEMENT.md) | Settlement models, statuses, disputes |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Controls, deployment requirements, limits |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Install, `.env`, web servers, cron |
| [`docs/OPERATIONS.md`](docs/OPERATIONS.md) | Runbooks, API codes, backup, retention |
| [`docs/TEST-REPORT.md`](docs/TEST-REPORT.md) | Test results and known gaps |

## Local setup (already done on this machine)

1. Create database `campuspay` (utf8mb4).
2. `php database/migrate.php` — applies `database/migrations/*.sql` once each.
   Alternatively import `database/campuspay.sql` for a fresh install, then
   seed the first administrator with step 3.
3. `php database/seed-admin.php` — creates the first administrator; prints a
   generated password and saves it to `storage/dev/admin-credentials.txt`.
4. Serve `public/` (Apache Alias `/CampusPay`).
5. Schedule `php bin/campuspay-cron.php` every minute (expiry, reconciliation,
   webhook delivery). See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

Config comes from `.env` (gitignored). Start from `.env.example`.
Daraja credentials ship blank; with any missing, the platform fails closed —
no STK push is attempted and no payment is ever simulated.

Contracts for integrators: [`docs/API.md`](docs/API.md) (HMAC signing, endpoints)
and [`docs/WEBHOOKS.md`](docs/WEBHOOKS.md) (events, signatures, retries). A
drop-in PHP client and webhook receiver are in
[`clients/campusfind/`](clients/campusfind/).

Tests run without dependencies: `php tests/run.php`. Results and the manual
verification log are in [`docs/TEST-REPORT.md`](docs/TEST-REPORT.md).

## Rules this codebase enforces

- Only a validated Daraja callback or status query can confirm a payment.
- Every admin write is POST + CSRF; every query is a prepared statement.
- Secrets never live in the repo; `.env` only.
- Financial records are append-only where it matters (attempts, callbacks, audit log).
- No fake dashboard numbers — every figure is a database query.
