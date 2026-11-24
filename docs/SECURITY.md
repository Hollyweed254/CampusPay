# CampusPay — Security

What CampusPay protects, how, and what it deliberately does not do. This is the
reference for reviews and incident response.

## 1. Assets and adversaries

| Asset | Why it matters |
| --- | --- |
| Payment records (`payment_requests`, `transactions`, callbacks) | Financial truth; must not be forgeable |
| API secrets | Whoever holds one can create requests and read that application's data |
| Admin accounts and sessions | Control disputes, credentials and settings |
| Daraja credentials and callback token | Could trigger STK pushes or fake confirmations |
| Audit log | Reconstruction after an incident |

Assumed adversaries: anonymous internet users, a compromised integrating
application, a malicious buyer, and a careless administrator. Insiders with
database or server access are outside this model (they can read everything;
protect with OS/DB permissions and backups).

## 2. Core guarantees

1. **Only the provider confirms money.** A transaction becomes
   `provider_confirmed` only through a token-validated Daraja callback or a
   status query answered by Daraja. Browser returns, API responses from the
   integrating app, and STK acceptance are never proof.
2. **PINs and OTPs are never collected or stored.** The checkout sends the
   buyer to the M-Pesa STK prompt on their phone; CampusPay never asks for a PIN
   or OTP, and no table has a column for one. Callback payloads contain the
   paying phone number and receipt, not the PIN.
3. **Fail closed.** Missing callback token, missing Daraja credentials, bad
   signature, or unreadable stored secret results in a refusal, not a fallback
   or simulation.
4. **No payouts or escrow.** No code path moves money to a seller. See
   SETTLEMENT.md for the boundary.

## 3. Controls

### Administrator access

| Control | Implementation |
| --- | --- |
| Password storage | `password_hash()` bcrypt; plaintext never stored or logged |
| Login errors | Generic message; dummy hash keeps timing uniform for unknown emails |
| Brute force | 5 failed attempts per email+IP per 15 minutes (`login_attempts`), then 429 |
| Sessions | `HttpOnly`, `SameSite=Lax`, `Secure` in production, strict mode, ID regenerated at login, full destroy at logout |
| Roles | `superadmin` required for users, API credentials and settings; enforced server-side as well as in the nav |
| CSRF | Per-session token, `hash_equals`, checked on every state-changing POST |
| XSS | All output escaped through `e()`; CSP blocks inline script and third-party origins |
| SQL injection | 100% prepared statements via `q()`; `ATTR_EMULATE_PREPARES=false` |
| Headers | `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, `X-Permitted-Cross-Domain-Policies: none`, CSP (`object-src 'none'`, `frame-ancestors 'none'`), HSTS in production over HTTPS |
| Audit | Logins, failures, admin writes, disputes, settings, credentials, payments |

### API (`/api/v1`)

| Control | Implementation |
| --- | --- |
| Authentication | `X-CP-Key` + HMAC-SHA256 over method, path, query, timestamp, nonce, body hash |
| Secret handling | AES-256-GCM ciphertext in `api_credentials.secret_cipher`, keyed by `APP_KEY`; decrypted only after key lookup |
| Replay | 10-digit UNIX timestamp within ±300 s; per-credential nonce with a DB unique key; 10-minute purge |
| Rate limit | 120 signed requests per key per minute by default (`api_rate_limit_per_minute`); 429 + `Retry-After` |
| Idempotency | `Idempotency-Key` (8–64 chars) unique per application; replays return the original request |
| Isolation | Every query is scoped to the credential's application; other applications' references return 404 |
| Errors | Stable codes, no stack traces; unexpected errors become `server_error` with HTTP 500 |
| Request log | Method, path, status, credential, application, IP — never bodies or signatures |

### Daraja callbacks

- Route `/api/mpesa/callback/{token}`; the token is compared with
  `hash_equals` against `DARAJA_CALLBACK_TOKEN`.
- Optional `DARAJA_CALLBACK_IPS` allow-list adds network filtering.
- Payload cap 64 KB; non-JSON rejected; rejected calls are audited
  (`mpesa.callback_rejected`).
- Raw payloads are stored in `provider_callbacks` before interpretation;
  duplicates are ignored by checkout request id and receipt uniqueness.

### Webhooks

- HMAC-SHA256 of `<timestamp>.<raw body>` using the newest active API secret
  for that application; `X-CP-Signature`, `X-CP-Timestamp`, event id and
  delivery id headers.
- Receivers should reject timestamps older than 5 minutes (the shipped client
  does).
- Plain HTTP targets are refused when `DARAJA_ENV=production`.
- 10-second timeout; retries 30 s, 2 min, 10 min, 30 min, 2 h up to
  `webhook_max_attempts`; failures never change payment state.

### Secrets and keys

| Secret | Where | Rotation |
| --- | --- | --- |
| `APP_KEY` | `.env` | Not rotatable without re-issuing API credentials (old secrets become unreadable) |
| API key/secret | Issued in API Credentials; secret stored encrypted, shown once | Create new → deploy → confirm → revoke old |
| `DARAJA_CALLBACK_TOKEN` | `.env` + callback URL | Change value, update Daraja callback URL, redeploy |
| Daraja consumer key/secret/passkey | `.env` | Rotate in the Daraja portal and `.env` |
| Admin passwords | bcrypt in DB | Change via Users (new account) or seed script; disable unused accounts |

`.env` is outside `public/` and denied by the root `.htaccess`. Never commit
`.env`, `storage/dev/admin-credentials.txt`, logs or database dumps.

## 4. Deployment requirements

1. HTTPS everywhere; `APP_ENV=production`, `APP_DEBUG=false`.
2. Dedicated least-privilege database user (DEPLOYMENT.md §3).
3. Synchronised system clock (NTP) — API timestamps allow ±5 minutes.
4. Web-server user cannot write outside `storage/`.
5. Restrict admin access at the network layer if possible (VPN, IP allow-list
   at the proxy). CampusPay itself does not implement admin IP allow-lists.
6. Monitor `storage/logs/app.log`, the cron timestamp in Settings, and the
   operational queues (webhooks, reconciliation, disputes).

## 5. Known limitations

- No multi-factor authentication for administrators. Use strong unique
  passwords and network restrictions.
- Webhook URLs are administrator-supplied and not egress-filtered; an admin can
  point one at an internal address. Restrict outbound traffic at the firewall
  if that matters in your environment.
- The database is not encrypted at rest by the application; use storage-level
  encryption and protected backups.
- Dispute evidence is captured as text and linked records; there is no file
  upload/attachment store.
- No payouts, escrow, wallets or seller settlement — outside scope by design.

## 6. Incident response

1. Contain: revoke affected API credentials; disable the administrator or
   integration; rotate the callback token; block source IPs at the proxy.
2. Preserve: export `audit_logs`, `provider_callbacks`, reconciliation records
   and the relevant window of `app.log` before cleanup.
3. Assess: use the transaction page (attempts, callbacks, reconciliation,
   webhooks, disputes) to reconstruct each affected payment.
4. Correct: only provider-confirmed changes of state are allowed. If a record is
   wrong, open a dispute and resolve it with evidence; do not edit money rows by
   hand.
5. Report: write down the window, affected references, actions taken and
   follow-ups. Rotate every secret that may have been exposed.
