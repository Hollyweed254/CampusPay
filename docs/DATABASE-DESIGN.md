# CampusPay — Database Design

Version 0.1 · Stage 1 · Database `campuspay`, utf8mb4, InnoDB.

Conventions:

- Money: `DECIMAL(12,2)`, currency `CHAR(3)` (default `KES`). Never floats.
- Time: all timestamps UTC. PDO sets `time_zone='+00:00'`; defaults use
  `CURRENT_TIMESTAMP` which then evaluates in UTC.
- Financial history is append-only where correctness matters
  (`payment_attempts`, `provider_callbacks`, `audit_logs`); rows are never
  deleted in normal operation (`ON DELETE RESTRICT` on money tables).
- Every reference that integrations see (`payment_requests.reference`) is
  unique and opaque; internal auto-increment IDs never leave the server.
- Secrets: `api_credentials.secret_cipher` stores AES-256-GCM ciphertext
  (key = `APP_KEY`), never plaintext or reversible hashes that can't verify
  HMACs.

## 1. Entity map

```
applications 1─* api_credentials 1─* api_nonces
                  api_credentials 1─* api_request_logs
applications 1─* payment_requests 1─* payment_attempts
                             │ 1
                             ▼ 1
                        transactions 1─* webhook_deliveries
                             │ 1
                             ├──* reconciliation_records (also links payment_requests)
                             └──* disputes

administrators      (admin auth; independent of integrations)
audit_logs          (actor may be administrator/system/api)
login_attempts      (throttling input)
system_settings     (key/value configuration)
provider_callbacks  (raw provider events, deduplicated by caller logic)
```

## 2. Tables

### administrators
Admin accounts for the dashboard. `password_hash` = bcrypt. Roles:
`admin` (moderate), `superadmin` (credentials, settings, administrators).
`status='disabled'` blocks login; row kept for audit references.

### applications
Each integrating product (first row: CampusFind). `slug` unique.
`cash_on_meeting_enabled` lets an app offer record-only cash transactions.
`settlement_model` ∈ `merchant_collection | direct_payment | undecided`
(see ARCHITECTURE §5). `webhook_url` receives signed payment events.

### api_credentials
Per-application API keys. `key_id` is public (sent in requests);
`secret_cipher` is encrypted at rest and used to verify HMAC signatures.
Rotation = new row, old row `revoked`. `expires_at` optional.

### api_nonces
Replay protection: one row per signed request `(credential_id, nonce)`,
unique. Rows older than 10 minutes are pruned opportunistically during
authentication; a request whose nonce is already present is rejected.

### api_request_logs
Thin request log for API traffic: credential/application, method, path,
status code, IP, time. Used for support and abuse review, never for money
state.

### payment_requests
What an integration asks to collect: description, amount, currency, optional
buyer name/phone, method (`mpesa | cash_on_meeting`), `status` ∈
`pending | processing | succeeded | failed | cancelled | expired`,
`expires_at` for checkout links, `metadata_json` for the caller's own data.
`UNIQUE(application_id, idempotency_key)` stops duplicate requests.

### transactions
The financial outcome, one per payment request. `status` ∈
`pending | provider_confirmed | failed | cancelled | expired | disputed`.
`provider_reference` (Daraja checkout request id), `provider_receipt`
(M-Pesa receipt number when confirmed), `verified_at` only set from a
validated provider result.

### payment_attempts
One row per STK Push attempt. Records the Daraja ids
(`checkout_request_id`, `merchant_request_id`), response code/description and
outcome. Append-only.

### provider_callbacks
Sanitised provider callback payloads (JSON) with `signature_valid`,
`processed` flags and `received_at`. Never stores PINs or credentials.
Duplicate callbacks must be detected against `provider_reference` + payload
digest before any state change.

### webhook_deliveries
Outbound notifications to the integrating app: event name, payload, retry
count, last response code, schedule. Delivery failures never change payment
state.

### reconciliation_records
Result of provider status queries vs recorded state: `matches`,
`provider_status`, `details`, `checked_at`. Used by the reconciliation queue.

### disputes
Opened against a transaction by an administrator (the API does not expose
dispute creation). Lifecycle `open → under_review → resolved | rejected`,
with resolution note and times. `previous_transaction_status` records the
status to restore if the dispute is rejected; while active the transaction
is `disputed`.

### audit_logs
Append-only action trail: `actor_type` ∈ `administrator | system | api`,
actor id, `action` (e.g. `admin.login`, `payment.confirmed`), target, JSON
metadata, IP. No secrets in metadata.

### login_attempts
Email, IP, success flag, timestamp. Throttle reads failures within 15 minutes
using the database clock.

### system_settings
Small key/value store for operational toggles and limits edited from the
Settings screen: checkout TTL, maximum amount, status-query cooldown,
reconciliation staleness, webhook attempts, API rate limit, and the last
upkeep-cron run timestamp. Contains no credentials.

## 3. Indexes and keys

- Unique: `administrators.email`, `applications.slug`,
  `api_credentials.key_id`, `api_nonces(credential_id, nonce)`,
  `payment_requests.reference`,
  `payment_requests(application_id, idempotency_key)`,
  `transactions.payment_request_id`.
- Hot-path indexes: `transactions.provider_receipt`,
  `payment_attempts.checkout_request_id`,
  `provider_callbacks.provider_reference`,
  `webhook_deliveries.status`, `disputes.transaction_id`,
  `audit_logs(action, created_at)`,
  `login_attempts(email, attempted_at)`, `login_attempts(ip, attempted_at)`,
  `api_request_logs(credential_id, created_at)`,
  `api_request_logs(application_id, created_at)`.

## 4. Migration policy

- Versioned, ordered files: `database/migrations/NNN-name.sql`. Current set:
  `001_initial_schema.sql`, `002_checkout_and_api_security.sql`,
  `003_provider_confirmation.sql`, `004_disputes_and_api_limits.sql`.
- `database/migrate.php` applies each file once, tracked in
  `schema_migrations`. Never edit an applied migration; add a new one.
- `database/campuspay.sql` is generated from the migrations for fresh
  installs and must be regenerated whenever a migration is added.
- Destructive changes (drops, type narrowing) require a backup and a note in
  the migration header.

## 5. Retention

Payments, attempts, callbacks, reconciliation, webhook and audit rows are
retained indefinitely (financial + compliance trail). Operational tables may
be pruned: `login_attempts` (throttling), `api_nonces` (replay window, kept
10 minutes), and `api_request_logs` (diagnostics).
