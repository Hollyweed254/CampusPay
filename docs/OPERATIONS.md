# CampusPay — Operations

Day-to-day running: the upkeep cron, the operational settings, routine checks,
and what to do when a payment, webhook or dispute needs attention.

## 1. Upkeep cron

Run every minute (see DEPLOYMENT.md §7):

```
php bin/campuspay-cron.php
```

One run performs three independent tasks and prints one line per task:

| Output line | Meaning |
| --- | --- |
| `expired_requests=N` | Checkout windows past `expires_at` closed; pending transactions cancelled |
| `reconcile=skipped (Daraja not configured)` or `checked=… confirmed=… failed=… pending=… mismatches=… errors=…` | Open STK attempts older than the stale threshold queried against M-Pesa; each answer becomes a reconciliation record and may settle the payment |
| `webhooks_checked=… delivered=… failed=… exhausted=…` | Due webhook deliveries attempted |

Finish with `done_in=…s` and the run is recorded in `system_settings.last_cron_run_at`.
The Settings page shows that timestamp; alert if it is older than a few minutes.

Manual variants:

```
php bin/campuspay-cron.php --skip-reconcile --skip-webhooks
php bin/campuspay-cron.php --limit=100 --webhook-limit=50
```

`--limit` caps reconciliation checks (1–100 in the service, default 25);
`--webhook-limit` caps deliveries per run (default 20).

M-Pesa queries are rate-limited per request by
`status_check_cooldown_seconds`, so raising `--limit` does not hammer Daraja.

## 2. Settings (superadmin → Settings)

Values live in `system_settings` and are read per request; changes apply without
a restart.

| Setting | Default | Range | Effect |
| --- | --- | --- | --- |
| `checkout_ttl_minutes` | 30 | 5–1440 | Default lifetime of a new M-Pesa checkout (API can shorten per request, 5–1440) |
| `max_amount_kes` | 250000 | 1–1,000,000 | Largest accepted amount |
| `status_check_cooldown_seconds` | 20 | 5–300 | Minimum gap between provider status queries for one request |
| `reconciliation_stale_after_minutes` | 2 | 1–60 | Age an accepted STK attempt needs before the cron queries it |
| `webhook_max_attempts` | 5 | 1–10 | Delivery attempts before an event becomes `exhausted` |
| `api_rate_limit_per_minute` | 120 | 0–10,000 | Signed API requests per key per minute; 0 disables |

Every save writes `settings.updated` to the audit log with old and new values
for each changed key. The page also shows readiness flags (APP_KEY, Daraja
credentials, callback IP list, production mode) but never prints secrets.

## 3. Routine checks

**Daily**

- Settings → last cron run is recent.
- Webhook Events → any `failed` or `exhausted` deliveries; resend exhausted
  ones after fixing the endpoint.
- Reconciliation → mismatches in the last day.
- Overview → disputed transactions count.

**Weekly**

- Disputes → active cases and their age; follow up on the oldest.
- API Credentials → unused or expiring keys; revoke what is no longer needed.
- Audit Logs → unexpected `credential_revoked`, `application.suspended`,
  `dispute.*` or `settings.updated` entries.
- Backups restore-tested on a scratch database (see §6).

## 4. Logs

- `storage/logs/app.log` — one block per unexpected exception, UTC ISO
  timestamps, file/line and stack trace. Request data, secrets and PINs are not
  logged.
- Web server access/error logs — routing, TLS, PHP fatal errors.
- The cron log (`/var/log/campuspay-cron.log` in the example) — upkeep output.

Rotate `app.log` externally (logrotate or equivalent) and keep at least 30
days. The application itself never truncates it.

## 5. Incidents

### Customer paid but the transaction is still pending

1. Transactions → open the request; check **Payment attempts** and **Provider
   callbacks**. A missing callback points at the callback URL or token.
2. Confirm `DARAJA_CALLBACK_URL` is publicly reachable over HTTPS and the
   token matches `DARAJA_CALLBACK_TOKEN`. If `DARAJA_CALLBACK_IPS` is set, the
   Safaricom egress IPs must match or callbacks are rejected (`bad_token` /
   `ip_not_allowed` audit entries).
3. Reconciliation → **Run checks now** (or wait for cron). A successful status
   query confirms the payment through the same guarded path as a callback.
4. If M-Pesa reports success but CampusPay cannot record it, capture the receipt
   and the reconciliation detail, then escalate with the audit trail. Never
   mark a payment confirmed by hand.

### Webhook delivery keeps failing

1. Webhook Events → open the payload and the last response/error.
2. Verify the target is HTTPS (required when `DARAJA_ENV=production`), returns
   2xx quickly (10 s timeout), and verifies `X-CP-Timestamp` +
   `X-CP-Signature` with the newest active API secret.
3. Fix the receiver, then **Resend**. Resend resets attempts and audits
   `webhook.resent`.
4. An `exhausted` delivery never retries on its own; resend after the fix.
5. Webhook failures never change payment state — the record stays correct while
   delivery is repaired.

### API client receives 401 / 429

| Code | Action |
| --- | --- |
| `auth_invalid` | Key id unknown; check the credential exists and was copied fully |
| `credential_revoked` / `credential_expired` | Issue a new key and deploy it |
| `signature_mismatch` | Recheck the canonical string: method, path **without** base path, raw query, timestamp, nonce, SHA-256 of the exact body |
| `timestamp_skew` | Sync the client clock (allowed ±300 s) |
| `nonce_replay` | A fresh nonce per request; retries must re-sign |
| `rate_limited` | Slow down or raise the limit; `Retry-After: 60` |

### Credential rotation

1. API Credentials → **Create credential** for the integration (never reuse a
   secret).
2. Deploy the new key/secret to the integrating app.
3. Confirm its traffic is succeeding (`last_used_at`, request logs).
4. **Revoke** the old key. Revocation deletes its nonces immediately.
5. Webhooks switch to the newest active key automatically; receivers verifying
   with the old secret must be updated first.

### Disputes

1. Transaction → **Open dispute** with a factual reason (5–500 chars). The
   transaction becomes `disputed`; the pre-dispute status is stored.
2. **Start review** while gathering evidence: callback payloads, reconciliation
   records, provider statements, buyer/seller statements.
3. **Resolve** with an outcome and a resolution note:
   - M-Pesa: `payment_confirmed` (record stands) or `payment_failed` (correct to
     failed). The payment request status follows.
   - Cash: `record_upheld` (agreement completed) or `payment_failed`.
4. Or **Reject** when the recorded outcome stands; the transaction returns to
   its exact pre-dispute status.
5. Every transition is audited (`dispute.opened`, `dispute.review_started`,
   `dispute.resolved`, `dispute.rejected`). Closed disputes cannot be reopened;
   open a new one against the new status if needed.

### Lost `APP_KEY`

Stored API secrets become unreadable (`credential_unreadable` on use). Restore
the key from backup; if it is gone, issue new credentials and revoke the old
ones. There is no recovery tool by design.

## 6. Backup and restore

Back up nightly and before every migration:

```
mysqldump --single-transaction --routines --triggers campuspay > campuspay-$(date +%F).sql
```

Also back up `.env` (separately, access-controlled) and `storage/logs/` if you
need the audit trail of failures. Restore:

```
mysql -u root -p campuspay < campuspay-2026-11-24.sql
```

Test the restore on a scratch database at least monthly. Financial tables are
append-only in normal operation, so a restore loses only the period after the
dump.

## 7. Retention

Retained indefinitely: `payment_requests`, `transactions`, `payment_attempts`,
`provider_callbacks`, `reconciliation_records`, `webhook_deliveries`,
`disputes`, `audit_logs`.

Safe to prune with your own policy (not financial records):

```sql
DELETE FROM login_attempts WHERE attempted_at < (UTC_TIMESTAMP() - INTERVAL 90 DAY);
DELETE FROM api_request_logs WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 180 DAY);
DELETE FROM api_nonces WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY);
```

API nonces older than 10 minutes are already purged on traffic.
