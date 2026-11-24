-- 001: initial schema (Stage 1)
-- Money: DECIMAL(12,2). Timestamps UTC (PDO sets time_zone = '+00:00').
SET NAMES utf8mb4;

CREATE TABLE administrators (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','superadmin') NOT NULL DEFAULT 'admin',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_administrators_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE applications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(60) NOT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  cash_on_meeting_enabled TINYINT(1) NOT NULL DEFAULT 0,
  settlement_model ENUM('merchant_collection','direct_payment','undecided') NOT NULL DEFAULT 'undecided',
  webhook_url VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_applications_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_credentials (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id INT UNSIGNED NOT NULL,
  key_id CHAR(32) NOT NULL,
  secret_cipher TEXT NOT NULL,
  status ENUM('active','revoked') NOT NULL DEFAULT 'active',
  expires_at DATETIME NULL,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_credentials_key (key_id),
  KEY idx_api_credentials_app (application_id),
  CONSTRAINT fk_api_credentials_app FOREIGN KEY (application_id) REFERENCES applications (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference VARCHAR(40) NOT NULL,
  application_id INT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'KES',
  buyer_name VARCHAR(120) NULL,
  buyer_phone VARCHAR(20) NULL,
  payment_method ENUM('mpesa','cash_on_meeting') NOT NULL DEFAULT 'mpesa',
  status ENUM('pending','processing','succeeded','failed','cancelled','expired') NOT NULL DEFAULT 'pending',
  idempotency_key VARCHAR(64) NULL,
  expires_at DATETIME NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_requests_reference (reference),
  UNIQUE KEY uq_payment_requests_idem (application_id, idempotency_key),
  KEY idx_payment_requests_status (status, created_at),
  CONSTRAINT fk_payment_requests_app FOREIGN KEY (application_id) REFERENCES applications (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transactions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_request_id INT UNSIGNED NOT NULL,
  provider ENUM('mpesa','cash_on_meeting') NOT NULL,
  provider_reference VARCHAR(64) NULL,
  provider_receipt VARCHAR(64) NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'KES',
  status ENUM('pending','provider_confirmed','failed','cancelled','expired','disputed') NOT NULL DEFAULT 'pending',
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transactions_request (payment_request_id),
  KEY idx_transactions_receipt (provider_receipt),
  KEY idx_transactions_status (status, created_at),
  CONSTRAINT fk_transactions_request FOREIGN KEY (payment_request_id) REFERENCES payment_requests (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_request_id INT UNSIGNED NOT NULL,
  provider VARCHAR(20) NOT NULL DEFAULT 'mpesa',
  checkout_request_id VARCHAR(64) NULL,
  merchant_request_id VARCHAR(64) NULL,
  status ENUM('initiated','accepted','failed','timed_out') NOT NULL DEFAULT 'initiated',
  response_code VARCHAR(16) NULL,
  response_description VARCHAR(255) NULL,
  error_message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_request (payment_request_id),
  KEY idx_attempts_checkout (checkout_request_id),
  CONSTRAINT fk_attempts_request FOREIGN KEY (payment_request_id) REFERENCES payment_requests (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE provider_callbacks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(20) NOT NULL,
  provider_reference VARCHAR(64) NULL,
  payload JSON NOT NULL,
  signature_valid TINYINT(1) NOT NULL DEFAULT 0,
  processed TINYINT(1) NOT NULL DEFAULT 0,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_callbacks_reference (provider_reference),
  KEY idx_callbacks_processed (processed, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE webhook_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id INT UNSIGNED NOT NULL,
  transaction_id INT UNSIGNED NULL,
  event VARCHAR(60) NOT NULL,
  payload JSON NOT NULL,
  status ENUM('pending','delivered','failed','exhausted') NOT NULL DEFAULT 'pending',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_response_code SMALLINT NULL,
  next_retry_at DATETIME NULL,
  delivered_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_webhooks_status (status, next_retry_at),
  KEY idx_webhooks_app (application_id, created_at),
  CONSTRAINT fk_webhooks_app FOREIGN KEY (application_id) REFERENCES applications (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_webhooks_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reconciliation_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_request_id INT UNSIGNED NULL,
  transaction_id INT UNSIGNED NULL,
  provider VARCHAR(20) NOT NULL DEFAULT 'mpesa',
  provider_status VARCHAR(40) NOT NULL,
  matches TINYINT(1) NOT NULL,
  details VARCHAR(500) NULL,
  checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reconciliation_request (payment_request_id),
  KEY idx_reconciliation_transaction (transaction_id),
  CONSTRAINT fk_reconciliation_request FOREIGN KEY (payment_request_id) REFERENCES payment_requests (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_reconciliation_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE disputes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id INT UNSIGNED NOT NULL,
  opened_by_type ENUM('application','administrator') NOT NULL,
  opened_by_id INT UNSIGNED NULL,
  reason VARCHAR(500) NOT NULL,
  status ENUM('open','under_review','resolved','rejected') NOT NULL DEFAULT 'open',
  resolution VARCHAR(500) NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_disputes_status (status, created_at),
  CONSTRAINT fk_disputes_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type ENUM('administrator','system','api') NOT NULL,
  actor_id INT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  target_type VARCHAR(40) NULL,
  target_id VARCHAR(64) NULL,
  metadata_json JSON NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_action (action, created_at),
  KEY idx_audit_actor (actor_type, actor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(150) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  successful TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_email (email, attempted_at),
  KEY idx_login_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
  setting_key VARCHAR(80) NOT NULL,
  value TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
