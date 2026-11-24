# CampusPay — Architecture

Version 1.0 · through Stage 8 · Independent M-Pesa payment platform.

CampusPay collects payments for integrating applications (first: CampusFind).
It is a separate system: own source tree, own database, own admin, own API.
No CampusFind file, table, session or credential is shared.

## 1. System overview

```
Integrating app (e.g. CampusFind)
   │  1. POST /api/v1/payment-requests   (API key + HMAC signature)
   │  2. redirect buyer to /checkout/{reference}
   │  3. webhook (signed) ← CampusPay
   ▼
Apache + PHP 8.2 (front controller → Router → Controller → Service → Repository)
   │
   ├── public/        only web-exposed directory (index.php, assets)
   ├── app/           Core (Env, Database, Router, View, Auth, Csrf),
   │                  Controllers, Services, Repositories, Middleware, Validators, Views
   ├── routes/        web.php (admin UI), api.php (JSON API)
   ├── config/        reserved for environment-backed configuration helpers
   ├── database/      migrations/, seed scripts, campuspay.sql fresh install
   ├── storage/       logs/, uploads/, dev/ (all HTTP-denied, outside public/)
   ├── docs/          architecture, database, API, webhooks, settlement,
   │                  security, deployment, operations, test report

External (optional until configured):
   Safaricom Daraja (OAuth → STK Push → callback/query) · webhook targets
```

Principles:

1. **Payment correctness first.** UI never decides money. Only a validated
   provider callback or status query can move a transaction to
   `provider_confirmed`.
2. **API-first.** The admin UI uses the same domain services as the API; the
   API is the only way integrations touch payments.
3. **Fail closed.** Missing callback token, missing credentials, invalid
   signature → reject, record, alert. No simulated success in any mode.
4. **Small procedural core, explicit wiring.** No framework, no ORM, no
   dependency manager; plain `require` in `app/bootstrap.php`.

## 2. Request lifecycle

1. `public/index.php` defines `BASE_PATH`, loads `app/bootstrap.php`.
2. Bootstrap loads `.env` (`Env::load`), function helpers, PDO (`Database`),
   and core classes (explicit requires).
3. Front controller sends security headers, starts the admin session
   (`Auth::start`), installs an exception handler (logs to `storage/logs/app.log`,
   renders 500), then dispatches.
4. `Router` strips `APP_BASE_PATH`, matches method+path, calls a controller.
5. Controllers: `Csrf::requirePost()` on every mutation → validate
   (`Validators`) → call `Repositories`/`Services` → `View::render` or
   `redirect()`.
6. Views escape all output via `e()`; layouts own the shell.

Session facts: `HttpOnly`, `SameSite=Lax`, `Secure` only in production,
strict mode on, ID regenerated at login.

## 3. Security model (stage 2 scope shipped)

| Control | Implementation |
| --- | --- |
| Admin auth | `password_hash`/`password_verify` (bcrypt), generic error text, dummy-hash timing equalisation |
| Brute force | `login_attempts` table: 5 failed attempts per email+IP per 15 min (DB clock) → 429 |
| CSRF | Per-session token, `hash_equals`, every POST |
| Headers | `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, strict CSP (self-only assets) |
| SQLi | 100% prepared statements through `q()`; `EMULATE_PREPARES=false` |
| Sessions | HttpOnly + SameSite, regeneration, full destroy on logout |
| Secrets | `.env` only (gitignored); `APP_KEY` reserved for encrypting stored API secrets |
| Exposure | Only `public/` is web-reachable; the project root denies all via `.htaccess` |
| Audit | `audit_logs` — every login attempt, login, logout; admin actions append-only |

Delivered in stages 4–7: HMAC keys with nonce replay protection and a per-key
rate limit, hashed/expiring credentials, Daraja callback token + optional IP
allow-list, idempotency keys, reconciliation records, signed webhooks with
retry/exhaustion, disputes, UTC pinning, hardened headers and HSTS in
production. Details: [`SECURITY.md`](SECURITY.md).

## 4. Money path (delivered)

```
create payment_request (pending)
      │  POST /checkout/{ref} → mpesa
      ▼
payment_attempts row (initiated) ── Daraja STK Push accepted ──┐
      │                                                        │
      ▼                                                        ▼
payment_requests.processing                      provider_callbacks (raw, immutable)
      │                                                        │
      │                    validated provider result ◄─────────┘
      ▼
transactions (provider_confirmed, receipt stored, verified_at set)
      │
      ▼
webhook_deliveries → integrating app (signed, retried, deduplicated)
```

A confirmed or failed outcome can later be contested through a dispute; the
transaction becomes `disputed` until the case is resolved or rejected
([`SETTLEMENT.md`](SETTLEMENT.md) §7).

Rules:

- An accepted STK Push is **not** a payment; a browser redirect is **not**
  proof; only `provider_callbacks` (token-validated, deduplicated) or a
  status query may confirm.
- `payment_requests.idempotency_key` prevents duplicate requests per
  application.
- Money stored as `DECIMAL(12,2)`; timestamps stored UTC (PDO session
  `time_zone = '+00:00'`).

## 5. Settlement model (decision record)

STK Push collects into the configured **merchant shortcode**. Paying an
arbitrary seller's personal M-Pesa number, seller payouts, escrow and
wallets are **not implemented** and must not be described as supported.
`applications.settlement_model` records which flow an integration uses:

- `merchant_collection` — supported direction of this platform.
- `direct_payment` — parties pay off-platform; CampusPay only records a
  reported transaction (never "verified").
- `undecided` — default; checkout refuses to start until chosen.

Seller disbursement requires approved Safaricom B2C/merchant arrangements and
is out of scope until those exist. This document is the source of truth for
that boundary.

## 6. Served layout

Apache Alias `/CampusPay` → `C:\xampp\htdocs\CampusPay\public` with
`AllowOverride All`. `public/.htaccess` funnels non-file requests to
`index.php`; the project root `.htaccess` denies everything (belt and braces:
`app/`, `.env`, `database/` are outside the docroot).

## 7. Delivered decisions and remaining gaps

1. Daraja sandbox credentials are not provisioned on the build machine. The
   integration is complete and fail-closed; STK Push and live callbacks require
   real sandbox values in `.env`.
2. Migrations remain the source of truth; `database/campuspay.sql` is generated
   from them for fresh installs.
3. Tests are plain PHP assertion scripts in `tests/` (no PHPUnit); results in
   [`TEST-REPORT.md`](TEST-REPORT.md).
4. The public site (landing, privacy, terms) is original content and makes no
   Safaricom-certification claim.
5. Stage 8 added the Settings screen for operational limits and a signed
   deployment/operations/security/settlement documentation set.
