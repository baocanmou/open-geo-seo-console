ALTER TABLE app_users
  ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status;

ALTER TABLE login_attempts
  ADD INDEX idx_login_retention (attempted_at);
