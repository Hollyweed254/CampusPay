# CampusPay REST API (v1)

Every integration calls CampusPay server-to-server. There is no browser token:
each request is signed with an HMAC key pair issued in **Admin → API
Credentials**. The API only ever moves *requests*; money moves through M-Pesa
and is confirmed by M-Pesa callbacks, never by an API caller.

- Base URL: `https://pay.example.com` (this deployment: `http://localhost/CampusPay`)
- Content type: `application/json`
- All timestamps in responses are UTC, `YYYY-MM-DD HH:MM:SS`
- Amounts are decimal strings with two places, e.g. `"1500.00"`

## Getting a key

1. An administrator creates the integration (Admin → Integrations) with a
   settlement model. M-Pesa collections require **merchant collection**.
2. A superadmin opens **Admin → API Credentials**, chooses the integration and
   creates a credential. The key ID and secret are shown **once**.
3. Store both in the integrating application's environment:

```
CAMPUSPAY_BASE_URL=https://pay.example.com
CAMPUSPAY_KEY_ID=cp_...
CAMPUSPAY_SECRET=cps_...
```

Secrets are encrypted at rest (AES-256-GCM, keyed by `APP_KEY`). To rotate,
create a new key, deploy it, then revoke the old one. Revocation is immediate
and also removes the key's used nonces.

## Signing requests

Headers:

| Header | Value |
| --- | --- |
| `X-CP-Key` | Credential key ID (`cp_...`) |
| `X-CP-Timestamp` | Unix seconds, must be within ±300s of server time |
| `X-CP-Nonce` | 16–80 chars of `A-Z a-z 0-9 _ -`, unique per key |
| `X-CP-Signature` | Lowercase hex HMAC-SHA256 of the canonical string |

Canonical string (newlines `\n`, no trailing newline):

```
METHOD
/path?query
timestamp
nonce
sha256(raw body)
```

- `METHOD` is uppercase.
- The path is the request path **without** the deployment subdirectory (this
  deployment is mounted at `/CampusPay`, so clients sign `/api/v1/...`), and
  includes the raw query string, unchanged.
- The body hash is the SHA-256 of the exact bytes sent (the empty string for
  GET).
- Compute `hex(HMAC-SHA256(canonical, secret))`.

A ready-made PHP client lives in
[`clients/campusfind/CampusPayClient.php`](../clients/campusfind/CampusPayClient.php).

Minimal signer (PHP):

```php
$method = 'POST';
$path = '/api/v1/payment-requests';
$body = json_encode($payload, JSON_UNESCAPED_SLASHES);
$timestamp = (string) time();
$nonce = bin2hex(random_bytes(16));
$canonical = $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
$signature = hash_hmac('sha256', $canonical, $secret);
```

### Replay protection

- Timestamps older or newer than 5 minutes are rejected (`timestamp_skew`).
- Each nonce is stored per credential. Reusing one returns HTTP 409
  `nonce_replay`. Nonces older than 10 minutes are purged automatically.
- Signed requests are limited per credential (default 120 per minute, the
  `api_rate_limit_per_minute` setting). Over the limit answers HTTP 429
  `rate_limited` with a `Retry-After: 60` header.
- Equal idempotency keys make request creation safe to retry (see below).

## Errors

All errors share one envelope:

```json
{"ok":false,"error":"invalid_amount","message":"The minimum amount is KSh 1.00.","details":{}}
```

`401` responses carry `WWW-Authenticate: CampusPay-HMAC` and an
`X-CP-Error` header with the same code.

| HTTP | Codes |
| --- | --- |
| 400 | `invalid_json` |
| 401 | `auth_missing`, `auth_invalid`, `credential_revoked`, `credential_expired`, `timestamp_skew`, `invalid_nonce`, `invalid_signature`, `signature_mismatch` |
| 403 | `application_inactive` |
| 404 | `not_found` |
| 409 | `nonce_replay`, `not_cancellable` |
| 422 | `invalid_description`, `invalid_amount`, `amount_too_large`, `invalid_method`, `invalid_buyer_name`, `invalid_phone`, `settlement_not_configured`, `cash_not_enabled`, `invalid_idempotency_key`, `invalid_metadata`, `metadata_too_large`, `invalid_status_filter` |
| 429 | `rate_limited` (includes `Retry-After: 60`) |
| 500 | `server_error`, `credential_unreadable`, `reference_generation_failed` |

## Payment request object

```json
{
  "reference": "CP261124ABFFF679DC5AD75A",
  "description": "Hostel deposit",
  "amount": "2500.00",
  "currency": "KES",
  "paymentMethod": "mpesa",
  "status": "pending",
  "buyerName": "Amina W.",
  "buyerPhone": "254712345678",
  "checkoutUrl": "https://pay.example.com/checkout/CP261124ABFFF679DC5AD75A",
  "expiresAt": "2026-11-24 17:10:00",
  "metadata": {"booking": 42},
  "createdAt": "2026-11-24 16:40:00",
  "transaction": null
}
```

`transaction` is populated once one exists:

```json
{
  "paymentRequestReference": "CP261124ABFFF679DC5AD75A",
  "provider": "mpesa",
  "providerReference": "ws_CO_24112026164000123",
  "receipt": "RTEST1234AB",
  "amount": "2500.00",
  "currency": "KES",
  "status": "provider_confirmed",
  "confirmedAt": "2026-11-24 16:41:12",
  "createdAt": "2026-11-24 16:40:05"
}
```

Request statuses: `pending`, `processing`, `recorded` (cash agreement),
`succeeded`, `failed`, `cancelled`, `expired`.
Transaction statuses: `pending`, `reported` (cash), `provider_confirmed`,
`failed`, `cancelled`, `expired`, `disputed`.

`recorded` means a cash-on-meeting agreement was recorded. **CampusPay did not
move or verify money**; only `succeeded` / `provider_confirmed` mean M-Pesa
confirmed the funds.

## POST /api/v1/payment-requests

Creates a payment request and returns a checkout link the buyer can open.

Request:

```json
{
  "description": "Hostel deposit",
  "amount": "2500",
  "paymentMethod": "mpesa",
  "buyerName": "Amina W.",
  "buyerPhone": "0712345678",
  "expiresMinutes": 30,
  "metadata": {"booking": 42}
}
```

| Field | Required | Rules |
| --- | --- | --- |
| `description` | yes | 3–255 characters |
| `amount` | yes | 1.00 up to `max_amount_kes` (default 250 000); max two decimals; M-Pesa needs whole shillings |
| `paymentMethod` | no | `mpesa` (default) or `cash_on_meeting` |
| `buyerName` | no | up to 120 characters |
| `buyerPhone` | mpesa | Kenyan mobile; stored normalised as `2547XXXXXXXX` |
| `expiresMinutes` | no | 5–1440, default `checkout_ttl_minutes` (30); ignored for cash |
| `metadata` | no | JSON object, encoded ≤ 4000 chars |

Idempotency: send `Idempotency-Key: <8–64 chars A-Za-z0-9._->`. A repeat with
the same key and integration returns the original request with HTTP 200 and
header `Idempotency-Replayed: true`; a new request returns **201**.

Response: `{"ok":true,"replayed":false,"paymentRequest":{...}}`

`mpesa` requests start `pending` and are charged only when the buyer opens the
checkout link and approves the prompt on their phone. `cash_on_meeting`
requests are created `recorded` with a `reported` transaction — no money moves.

## GET /api/v1/payment-requests

Lists only this integration's requests, newest first.

Query: `status`, `q` (reference/description/phone contains), `from`, `to`
(`YYYY-MM-DD`, UTC, inclusive), `limit` (1–100, default 25), `offset`.

```json
{"ok":true,"paymentRequests":[...],"meta":{"count":25,"total":132,"limit":25,"offset":0}}
```

## GET /api/v1/payment-requests/{reference}

Returns one request. Another integration's reference answers 404, not 403, so
references cannot be probed across applications.

## POST /api/v1/payment-requests/{reference}/cancel

Cancels a `pending` or `processing` request and any still-pending transaction.
Provider attempts stay on record as history. Returns the updated
`paymentRequest`. Answers 409 `not_cancellable` once the request has settled or
closed. A callback that lands after cancellation is still applied: if M-Pesa
confirms, the receipt wins.

The body may be empty. Sign the empty string.

## GET /api/v1/transactions/{reference}

`{reference}` is the **payment request** reference. Returns the transaction
object; 404 if no transaction exists yet (an M-Pesa request has one only after
an STK push is accepted or a callback is applied).

## GET /api/v1/health

Unauthenticated liveness probe: `{"status":"ok","service":"CampusPay","time":"..."}`.

## Operational notes

- Every call is logged in `api_request_logs` (credential, application, method,
  path, status, IP) for support and abuse review.
- Requests are synchronous; provider calls are not made during request
  creation. The only slow path is the checkout page's status polling.
- CampusPay never stores PINs or OTPs. Do not send them to any endpoint.
- Use HTTPS in production. The API refuses unauthenticated traffic by design;
  there is no anonymous create endpoint.
