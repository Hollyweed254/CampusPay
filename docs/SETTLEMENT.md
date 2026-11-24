# CampusPay — Settlement Model

This document is the source of truth for what CampusPay does with money. It is
deliberately narrow: **CampusPay collects and verifies payments; it does not
move money to sellers.**

## 1. What is supported

- **Merchant collection.** The deployment is configured with one Daraja shortcode
  (`DARAJA_SHORTCODE`, paybill or till). A buyer pays through STK Push into that
  shortcode. CampusPay verifies the provider result and tells the integrating
  application.
- **Cash-on-meeting records.** For integrations that allow it, CampusPay can
  record an agreement settled in person. No money moves through CampusPay and
  the record is never marked "verified".
- **Per-integration configuration.** Each application has a settlement model and
  a cash toggle, set in Integrations (superadmin for creation, admin for
  editing):
  - `merchant_collection` — M-Pesa checkout allowed.
  - `direct_payment` — no M-Pesa checkout; only cash records if enabled
    (parties settle off-platform).
  - `undecided` — default; checkout and cash are refused until a model is chosen.

## 2. What is not supported

- Seller payouts / disbursements (B2C), escrow, wallets, or marketplace
  splitting.
- Paying an arbitrary seller's personal number.
- Multi-currency: amounts are KES with two decimals (`DECIMAL(12,2)`).
- Fees or commissions: CampusPay adds no charge to the amount.

Settlement from the deployment shortcode to merchants is an arrangement between
the operator and Safaricom. It happens outside this platform and CampusPay has no
visibility into it. Do not describe the product as handling payouts.

## 3. Payment lifecycle

### Payment request (`payment_requests.status`)

| Status | Meaning |
| --- | --- |
| `pending` | Created; checkout not started |
| `processing` | STK Push accepted by Daraja; waiting for the buyer/provider |
| `succeeded` | A validated provider result confirmed the payment |
| `failed` | Provider reported failure, or a resolved dispute corrected it to failed |
| `cancelled` | Cancelled by admin/API before settling |
| `expired` | Checkout window elapsed (`expires_at`) |
| `recorded` | Cash-on-meeting agreement recorded; no provider involved |

### Transaction (`transactions.status`)

| Status | Meaning |
| --- | --- |
| `pending` | No provider result yet |
| `reported` | Cash agreement recorded (settled in person, unverified) |
| `provider_confirmed` | Confirmed by a token-validated callback or status query; receipt stored where supplied |
| `failed` | Provider reported failure (or dispute resolution) |
| `cancelled` | Request cancelled before settling |
| `expired` | Request expired before settling |
| `disputed` | An open dispute is investigating the recorded outcome |

Only `provider_confirmed` counts as money collected. Dashboards label it
"Verified volume"; `reported` cash rows are excluded.

## 4. How a payment becomes verified

1. Admin or API creates a request; the buyer opens `/checkout/{reference}` and
   submits their phone number.
2. CampusPay sends STK Push for exactly the request amount to the deployment
   shortcode; the attempt is stored (`payment_attempts`).
3. The buyer enters their M-Pesa PIN **on their phone** — CampusPay never asks
   for or stores a PIN or OTP.
4. Daraja posts the result to `/api/mpesa/callback/{token}`, or the cron/status
   check queries Daraja. The raw payload is stored in `provider_callbacks`.
5. CampusPay confirms only if all checks pass:
   - callback token (and optional IP allow-list) valid,
   - callback matches a known checkout request,
   - `ResultCode` is success and the reported amount equals the request amount,
   - the receipt is not already attached to another transaction (DB unique key).
   On success the transaction becomes `provider_confirmed`, the receipt and
   `verified_at` are stored, and `payment.confirmed` is queued for webhook
   delivery.
6. Failures set both records to `failed` and queue `payment.failed`. A later
   validated success for the same attempt may still confirm the payment (late
   failures never override a confirmation).

While a transaction is `disputed`, incoming provider answers are recorded as
conflicts and do not change the outcome; the dispute resolution decides it.

## 5. Cash-on-meeting

- Enabled per integration (`cash_on_meeting_enabled`) and only when a settlement
  model is chosen.
- Creates a request (`recorded`) and a transaction (`reported`) immediately.
- Produces no receipt, no `verified_at`, no confirmation webhook, and is excluded
  from verified volume.
- Parties can dispute it like any other transaction; resolving a cash dispute
  either upholds the record (`reported`) or corrects it to `failed`.

## 6. Reconciliation

The upkeep cron queries Daraja for STK attempts that are old enough
(`reconciliation_stale_after_minutes`) and not too old (3 days), respecting
`status_check_cooldown_seconds` per request. Each answer is stored in
`reconciliation_records` with `matches` and a human-readable detail. A success
runs through the same guarded confirmation path; a failure marks the record
failed. Mismatches (amount, missing receipt, success without amount) are kept
visible on the Reconciliation page for follow-up and never auto-confirm.

## 7. Disputes and correction

Disputes exist because a recorded outcome can be contested after the fact
(buyer claims, missing money, wrong amount). Opening one marks the transaction
`disputed` and stores the previous status. Resolution outcomes:

| Outcome | Allowed for | Transaction becomes | Request becomes |
| --- | --- | --- | --- |
| `payment_confirmed` | M-Pesa | `provider_confirmed` | `succeeded` |
| `payment_failed` | M-Pesa and cash | `failed` | `failed` |
| `record_upheld` | Cash | `reported` | `recorded` |
| reject | Any | Previous status restored | Unchanged |

Every transition is audited. Disputes are the only supported way to change a
settled outcome; money rows are never edited by hand.

## 8. Operational limits that affect settlement

Settings (superadmin → Settings): `checkout_ttl_minutes`, `max_amount_kes`,
`status_check_cooldown_seconds`, `reconciliation_stale_after_minutes`,
`webhook_max_attempts`, `api_rate_limit_per_minute`. Daraja credentials,
shortcode and callback token live in `.env`.

## 9. Reporting truthfulness

- "Verified volume" sums only `provider_confirmed` transactions.
- "Successful requests" counts requests that a provider result settled.
- Cash records and unverified claims are never counted as collected money.
- Figures are computed from the database on each page load; there are no mocked
  or demo statistics in the admin UI.
