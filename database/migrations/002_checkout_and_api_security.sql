-- 002: checkout flows, cash records, API replay/request logs (Stage 4-6)
-- Additive only. Money columns unchanged. New ENUM values are appended,
-- so existing rows keep their meaning.

ALTER TABLE payment_requests
  MODIFY status ENUM('pending','processing','succeeded','failed','cancelled','expired','recorded') NOT NULL DEFAULT 'pending',
  ADD COLUMN last_status_check_at DATETIME NULL AFTER status,
  ADD COLUMN created_via ENUM('api','admin','checkout') NOT NULL DEFAULT 'api' AFTER payment_method,
  ADD KEY idx_payment_requests_app (application_id, status, created_at);

ALTER TABLE transactions
  MODIFY status ENUM('pending','reported','provider_confirmed','failed','cancelled','expired','disputed') NOT NULL DEFAULT 'pending';

ALTER TABLE webhook_deliveries
  ADD COLUMN last_error VARCHAR(500) NULL AFTER last_response_code;

CREATE TABLE api_nonces (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  credential_id INT UNSIGNED NOT NULL,
  nonce VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_nonces (credential_id, nonce),
  KEY idx_api_nonces_created (created_at),
  CONSTRAINT fk_api_nonces_credential FOREIGN KEY (credential_id) REFERENCES api_credentials (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_request_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  credential_id INT UNSIGNED NULL,
  application_id INT UNSIGNED NULL,
  method VARCHAR(8) NOT NULL,
  path VARCHAR(190) NOT NULL,
  status_code SMALLINT UNSIGNED NOT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_api_logs_credential (credential_id, created_at),
  KEY idx_api_logs_application (application_id, created_at),
  CONSTRAINT fk_api_logs_credential FOREIGN KEY (credential_id) REFERENCES api_credentials (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_api_logs_application FOREIGN KEY (application_id) REFERENCES applications (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO system_settings (setting_key, value) VALUES
  ('checkout_ttl_minutes', '30'),
  ('max_amount_kes', '250000'),
  ('webhook_max_attempts', '5');
