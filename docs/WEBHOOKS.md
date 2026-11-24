# CampusPay Webhooks

CampusPay notifies an integration when M-Pesa **confirms** or **fails** a
payment. Events are queued the moment the provider answer is applied, signed
with the integration's newest active API key, and delivered by the cron runner
with automatic retries.

Webhooks are informational. The API (`GET /api/v1/payment-requests/{ref}`) is
the source of truth; if a webhook is missed, poll or reconcile instead.

## Requirements

1. The integration has a **webhook URL** (Admin → Integrations). HTTPS is
   required in production; `http://` is accepted only on non-production
   deployments.
2. The integration has at least one **active API credential**, whose secret is
   used to sign deliveries.
3. `bin/campuspay-cron.php` runs regularly (every minute is a good default).

Without all three, events are not queued. A suspended application does not
receive webhooks.

## Events

| Type | When |
| --- | --- |
| `payment.confirmed` | M-Pesa (callback or STK query) confirmed the payment; receipt is included |
| `payment.failed` | M-Pesa reported a final failure (e.g. 1032 cancelled by user, insufficient funds) |

Cash-on-meeting records do **not** emit webhooks: CampusPay cannot verify cash,
so there is nothing provider-confirmed to announce.

### Payload

```json
{
  "id": "evt_5ff367742895eca17a8ffdcf",
  "type": "payment.confirmed",
  "createdAt": "2026-11-24T16:19:57+00:00",
  "application": {"slug": "campusfind", "name": "CampusFind"},
  "paymentRequest": {
    "reference": "CP261124BFB84A882542DE3E",
    "description": "Hostel deposit",
    "amount": "1500.00",
    "currency": "KES",
    "paymentMethod": "mpesa",
    "status": "succeeded",
    "buyerName": null,
    "buyerPhone": "254712345678",
    "checkoutUrl": "https://pay.example.com/checkout/CP261124BFB84A882542DE3E",
    "expiresAt": "2026-11-24 16:49:46",
    "createdAt": "2026-11-24 16:19:46"
  },
  "transaction": {
    "provider": "mpesa",
    "providerReference": "ws_CO_smoke_confirm_0001",
    "receipt": "RTEST1234AB",
    "amount": "1500.00",
    "currency": "KES",
    "status": "provider_confirmed",
    "confirmedAt": "2026-11-24 16:19:57"
  }
}
```

For `payment.failed`, `paymentRequest.status` is `failed` and `transaction` may
be `failed` or `null` if no transaction row existed.

A failed request can still succeed later: M-Pesa may confirm a payment after a
failure answer (the receipt wins). Receivers should apply the newest event per
reference/idempotently, not treat `payment.failed` as final for all time.

`id` is unique per event. Deliveries are **at-least-once**: deduplicate on
`id` before applying side effects.

## Headers

| Header | Meaning |
| --- | --- |
| `X-CP-Event` | `payment.confirmed` or `payment.failed` |
| `X-CP-Event-Id` | Same value as payload `id` |
| `X-CP-Delivery` | Internal delivery row id (useful in support requests) |
| `X-CP-Timestamp` | Unix seconds when the delivery attempt was signed |
| `X-CP-Signature` | `hex(HMAC-SHA256("<timestamp>.<raw body>", secret))` |
| `Content-Type` | `application/json` |
| `User-Agent` | `CampusPay-Webhooks/1.0` |

The secret is the same API secret from Admin → API Credentials. The signature
is computed over the **exact raw body** — verify before `json_decode`.

### Verifying (PHP)

```php
$raw = file_get_contents('php://input');
$timestamp = (string) ($_SERVER['HTTP_X_CP_TIMESTAMP'] ?? '');
$signature = (string) ($_SERVER['HTTP_X_CP_SIGNATURE'] ?? '');
$secret = getenv('CAMPUSPAY_SECRET');

$expected = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
if (!hash_equals($expected, $signature) || abs(time() - (int) $timestamp) > 300) {
    http_response_code(401);
    exit('invalid signature');
}
```

`CampusPayClient::verifyWebhook($raw, $_SERVER, $secret)` in
[`clients/campusfind/CampusPayClient.php`](../clients/campusfind/CampusPayClient.php)
does the same checks.

## Responding

- Return **2xx quickly**, then process. Anything else is treated as a failure
  and retried.
- Make processing idempotent (unique event id) and transactional.
- Never trust the payload without a valid signature; never log PINs/OTPs —
  they are never present in M-Pesa callbacks.

## Retries

Failed deliveries are retried after 30s, 2m, 10m, 30m and 2h, capped by
`webhook_max_attempts` (default 5). After the cap the row is marked
`exhausted` and stays visible in **Admin → Webhook Events**, where an
administrator can inspect the payload and **Resend** it.

Delivery statuses: `pending`, `delivered`, `failed` (will retry),
`exhausted` (no more automatic attempts). Metrics on the same screen show the
queue depth, today's deliveries, and exhausted events from the last 7 days.

## Key rotation

New deliveries sign with the integration's **newest active** credential.
During rotation, keep the old secret accepted by your receiver until the old
key is revoked, then drop it.

## Local testing

Point the integration's webhook URL at a local receiver, for example:

```bash
php -S 127.0.0.1:8899 receiver.php
# Admin → Integrations → webhook URL: http://127.0.0.1:8899/webhook
php bin/campuspay-cron.php --skip-expire --skip-reconcile
```

The cron line reports `webhooks_checked/delivered/failed/exhausted`.
`--skip-webhooks` disables delivery for that run. A receiver that returns 503
lets you exercise retries and the Resend button without touching real data.

## Security notes

- Deliveries come only from CampusPay; verify signatures and timestamps
  (±300s) and ignore anything else.
- The payload includes the buyer's phone number; treat webhook endpoints and
  logs as sensitive data.
- CampusPay does not retry on redirects; your endpoint must answer directly.
