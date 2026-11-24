-- 003: one M-Pesa receipt can only ever belong to one transaction (Stage 5)
-- NULL receipts stay allowed for query-confirmed payments until the callback
-- supplies the receipt.

ALTER TABLE transactions
  ADD UNIQUE KEY uq_transactions_provider_receipt (provider_receipt);

INSERT IGNORE INTO system_settings (setting_key, value) VALUES
  ('status_check_cooldown_seconds', '20'),
  ('reconciliation_stale_after_minutes', '2');
