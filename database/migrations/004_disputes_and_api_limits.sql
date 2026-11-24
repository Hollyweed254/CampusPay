-- 004: dispute resolution bookkeeping and API rate limiting (Stage 7)
-- previous_transaction_status lets a rejected dispute restore the exact
-- status the transaction had before it was marked disputed.
-- The dispute table is empty on install, so NULL is safe for existing rows.

ALTER TABLE disputes
  ADD COLUMN previous_transaction_status
    ENUM('pending','reported','provider_confirmed','failed','cancelled','expired','disputed') NULL
    AFTER transaction_id,
  ADD KEY idx_disputes_transaction (transaction_id);

INSERT IGNORE INTO system_settings (setting_key, value) VALUES
  ('api_rate_limit_per_minute', '120');
