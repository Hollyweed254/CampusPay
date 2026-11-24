# CampusFind → CampusPay integration

This folder contains a drop-in PHP client and an example webhook receiver for
integrating CampusFind with CampusPay. Copy the files into CampusFind (or any
PHP application); they have no dependency on the CampusPay codebase.

## 1. Provision the integration

1. In CampusPay, an administrator creates/opens the **CampusFind** integration
   and sets:
   - Settlement model: **Merchant collection**
   - Webhook endpoint: `https://<campusfind-host>/api/campuspay/webhook`
2. A superadmin opens **API Credentials** and creates a key for CampusFind.
   Copy the key ID and secret immediately; the secret is shown once.
3. Store the values in CampusFind's environment:

```
CAMPUSPAY_BASE_URL=http://localhost/CampusPay
CAMPUSPAY_KEY_ID=cp_...
CAMPUSPAY_SECRET=cps_...
CAMPUSPAY_WEBHOOK_SECRET=cps_...   # same secret, used to verify deliveries
```

## 2. Create a payment request

Call CampusPay when the buyer chooses to pay (e.g. a listing deposit):

```php
require __DIR__ . '/CampusPayClient.php';

$campusPay = new CampusPayClient(
    (string) getenv('CAMPUSPAY_BASE_URL'),
    (string) getenv('CAMPUSPAY_KEY_ID'),
    (string) getenv('CAMPUSPAY_SECRET')
);

try {
    $request = $campusPay->createPaymentRequest([
        'description' => 'Deposit: ' . $listingTitle,
        'amount' => number_format($amount, 2, '.', ''),
        'buyerName' => $buyerName,
        'buyerPhone' => $buyerPhone,
        'metadata' => ['listing_id' => $listingId, 'campusfind_transaction_id' => $transactionId],
    ], 'campusfind-tx-' . $transactionId);   // idempotent; safe to retry

    // Persist the mapping before redirecting the buyer:
    $pdo->prepare('INSERT INTO campuspay_links (reference, payment_id) VALUES (?, ?)')
        ->execute([$request['reference'], $paymentId]);

    header('Location: ' . $request['checkoutUrl']);
} catch (CampusPayApiException $e) {
    error_log('CampusPay create failed [' . $e->errorCode . ']: ' . $e->getMessage());
    // Show a friendly message; do not retry blindly unless the error is a timeout.
}
```

- Amounts: max two decimals. M-Pesa requires whole shillings; send `"1500.00"`,
  not `"1499.99"`.
- `buyerPhone` must be a Kenyan mobile number (`0712…`, `+2547…`, `2547…`).
- The buyer pays on CampusPay's checkout page; CampusPay never stores the PIN.
- Idempotency keys are 8–64 chars of `A-Z a-z 0-9 . _ -`. Reusing a key returns
  the original request with `replayed: true`.

You can also poll instead of relying on webhooks:

```php
$request = $campusPay->getPaymentRequest($reference);
$transaction = $request['transaction']; // null until one exists
```

`paymentRequest.status === 'succeeded'` means M-Pesa confirmed the money.
`recorded` is a cash-on-meeting agreement — CampusPay did not verify any cash.

## 3. Receive webhooks

`webhook-example.php` is a complete receiver:

- verifies `X-CP-Signature` (HMAC-SHA256 of `timestamp.body`) with a ±5 minute
  timestamp window,
- stores each event id once (at-least-once delivery → deduplicate),
- updates the linked CampusFind payment when the event is confirmed/failed,
- returns 2xx so CampusPay stops retrying.

One-off schema and environment variables are documented at the top of the
file. Register it as a POST route:

```
POST /api/campuspay/webhook   ->  clients/campusfind/webhook-example.php
```

Delivery failure is retried (30s, 2m, 10m, 30m, 2h; capped by
`webhook_max_attempts`). Exhausted events can be inspected and resent from
**Admin → Webhook Events** in CampusPay.

## 4. Operational checklist

- Serve the receiver over HTTPS in production. CampusPay refuses to send
  webhooks to plain HTTP when `DARAJA_ENV=production`.
- Respond in well under 10 seconds; do slow work asynchronously.
- Treat the buyer phone number and receipts as personal data; keep webhook logs
  short-lived.
- Rotate keys by creating a new credential, deploying it to CampusFind, and
  only then revoking the old one. Accept both secrets on the receiver during
  the overlap.
- Never log PINs or OTPs — CampusPay never sends them, and no M-Pesa callback
  contains them.

See `docs/API.md` and `docs/WEBHOOKS.md` in the CampusPay repository for the
full contract.
