-- LOCIFY Migration v2.1 — TOTP 2FA recovery codes
-- Idempotent.

USE locify;

CREATE TABLE IF NOT EXISTS user_mfa_recovery (
  id         CHAR(36)   NOT NULL PRIMARY KEY,
  user_id    CHAR(36)   NOT NULL,
  code_hash  CHAR(64)   NOT NULL,
  used_at    DATETIME   NULL,
  created_at TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mfa_recovery_user FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE CASCADE,
  UNIQUE KEY uq_mfa_recovery (user_id, code_hash),
  KEY idx_mfa_recovery_user (user_id, used_at)
) ENGINE=InnoDB;

-- Legacy rows were marked mfa_enabled=1 without a stored secret, which would
-- lock accounts out once MFA enforcement is switched on. Reset them so the
-- owner completes the TOTP setup flow before enforcement applies.
UPDATE user SET mfa_enabled = 0
 WHERE mfa_enabled = 1 AND (mfa_secret IS NULL OR mfa_secret = '');