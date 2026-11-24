# Test Report

CampusPay Stage 7 verification. Everything below was executed on the local
development deployment described in the project README (XAMPP, alias
`/CampusPay`).

## Environment

| Item | Value |
| --- | --- |
| OS | Windows 11 (x64) |
| Web server | Apache 2.4 (XAMPP 8.2.12), alias `/CampusPay` |
| PHP | 8.2.12 (ZTS), extensions: pdo_mysql, openssl, curl, mbstring |
| Database | MariaDB 10.4.32, session time zone `+00:00` |
| App time zone | UTC (`date_default_timezone_set('UTC')`) |
| Browser checks | Headless Chrome via CDP, 1440 px and 390 px |

## Automated suite

Run with:

```
php database/migrate.php
php tests/run.php
```

The suite is dependency-free plain PHP. Database tests create their own
application, request and transaction rows inside the live schema, then remove
them (including their audit rows) in a `finally` block.

Last run: **28 passed, 0 failed** (exit code 0).

| Group | Tests |
| --- | --- |
| Phone numbers | 07/+254/254 forms normalise; 01 ranges; malformed rejected; display grouping |
| Reference | `CP` + date + 16 hex pattern; 200 draws without collisions |
| SecretBox | AES-256-GCM roundtrip; random IV; tampered ciphertext and garbage fail closed with `credential_unreadable` |
| API signing | canonical string hashes the raw body; `signedPath` strips the base path and keeps the query |
| Webhook verification | valid signature accepted; tampered body, stale timestamp and malformed headers rejected |
| Formatting helpers | KSh formatting, status chips, phone masking |
| Dispute lifecycle (database) | open → disputed with prior status kept; review; resolve confirmed (transaction + request corrected); resolve failed; reject restores exact prior status; cash resolution outcomes; guards for pending transactions, duplicate disputes, short reasons, repeated transitions and invalid resolution text |

## Live dispute flow (admin UI, HTTP)

Exercised through the real session and CSRF-protected forms against
transaction #2 (cash record, `reported`):

1. `GET /admin/transactions/2` offered **Open dispute**.
2. `POST /admin/transactions/2/disputes` → 302 to `/admin/disputes/13`;
   transaction became `disputed`, dispute `open`, audit `dispute.opened`.
3. `POST /admin/disputes/13/review` → `under_review`, audit
   `dispute.review_started`.
4. `POST /admin/disputes/13/resolve` with `record_upheld` → dispute `resolved`,
   transaction `reported`, request `recorded`, audit `dispute.resolved`.
5. A second dispute (#14) was rejected → dispute `rejected`, transaction
   restored to `reported` (no drift).
6. A 2-character reason re-rendered the form with the validation flash
   "Explain the dispute in 5 to 500 characters."; no row was created.

Both desktop (1440 px) and 390 px layouts were screenshotted: the log renders
as a table on desktop and as stacked cards on mobile with no horizontal page
overflow. The show page renders the case, the transaction snapshot, the audit
trail and outcome forms only while the dispute is active.

Test rows (#13, #14 and their audit entries) were deleted afterwards; the
sample request and transaction were left exactly as they were.

## Security hardening checks

| Check | Result |
| --- | --- |
| PHP time zone pinned to UTC | `date_default_timezone_get()` → `UTC`; DB session already `+00:00`, so stored and rendered timestamps agree |
| Security headers | `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, `X-Permitted-Cross-Domain-Policies: none`, CSP with `object-src 'none'` / `frame-ancestors 'none'` present on HTML pages; HSTS emitted only for production over HTTPS |
| Session cookie | `HttpOnly`, `SameSite=Lax`, `Secure` in production, strict mode, id regenerated on login |
| CSRF | Every state-changing admin form posts a session-bound token; invalid tokens render 403 |
| Login throttling | 5 failures per email+IP per 15 minutes |
| API rate limit | With the setting temporarily at 3/min, three signed requests returned 200 and the fourth returned **429 `rate_limited`** with `Retry-After: 60`; setting restored to 120 and the temporary credential removed |
| Webhook signature (client) | Valid accepted, tampered and stale rejected (automated tests) |
| Error surface | API errors never include stack traces; unexpected errors render `server_error` |

## Regression checks after the changes

- All admin pages returned 200 with an authenticated session: overview,
  transactions, payment requests, integrations, webhook events, disputes,
  reconciliation, API credentials, users, audit logs.
- Public landing page and checkout pages render with the hardened CSP.
- `storage/logs/app.log` recorded no new entries during the Stage 7 window.

## Stage 8 checks

| Check | Result |
| --- | --- |
| Settings page | Loads 200 as superadmin at 1440 px and 390 px; six numeric fields show stored values; readiness panel reports APP_KEY configured and Daraja credentials not set |
| Settings save | Changing `webhook_max_attempts` 5 → 7 redirected back with "Settings saved", persisted in `system_settings`, and wrote an `settings.updated` audit row with the changed map; value reverted to 5 |
| Settings validation | Out-of-range value 99 rejected with "must be between 1 and 10"; no write |
| Upkeep cron | `php bin/campuspay-cron.php` exit 0 and wrote `last_cron_run_at`; Settings → System displays it |
| Fresh-install SQL | `database/campuspay.sql` imported into a scratch database: 16 tables created, default settings seeded, four migrations recorded, zero administrators; all 16 `SHOW CREATE TABLE` outputs identical to the migrated database; scratch database dropped |
| Test suite | `php tests/run.php` → 28 passed, 0 failed |

## Known gaps

- **Live Daraja credentials are not available on this machine.** STK Push,
  status queries and provider callbacks were verified against the fail-closed
  paths and by unit/integration tests, not against the real sandbox. The code
  refuses to start a payment without complete credentials.
- The automated suite covers pure classes, the signing contract and the
  dispute domain. Browser-level flows were verified manually (see above), not
  through an end-to-end browser automation runner.
