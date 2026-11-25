-- 005: administrator two-factor authentication (TOTP)
-- totp_secret holds the base32 seed encrypted with SecretBox (keyed by APP_KEY),
-- so a database-only leak does not expose the second factor.
-- totp_enabled_at NULL means the administrator has not enrolled yet.
-- totp_last_counter records the last accepted time step, so a code cannot be
-- replayed inside its own 30-second window.

ALTER TABLE administrators
  ADD COLUMN totp_secret VARCHAR(255) NULL AFTER password_hash,
  ADD COLUMN totp_enabled_at DATETIME NULL AFTER totp_secret,
  ADD COLUMN totp_last_counter BIGINT UNSIGNED NULL AFTER totp_enabled_at;

-- When '1', every administrator must have TOTP enrolled before a session is
-- issued (enrolment happens during sign-in). Off by default: enrol first, then
-- switch it on in Settings so nobody is locked out.
INSERT IGNORE INTO system_settings (setting_key, value) VALUES
  ('require_admin_totp', '0');
