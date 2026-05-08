ALTER TABLE users
  MODIFY email_address varchar(255) NOT NULL;

ALTER TABLE users
  ADD CONSTRAINT chk_users_email_address_not_blank
  CHECK (email_address <> '');

ALTER TABLE users
  ADD CONSTRAINT chk_users_role_id_reserved_or_positive
  CHECK (role_id = -1 OR role_id > 0);

ALTER TABLE role_card_permissions
  ADD CONSTRAINT chk_role_card_permissions_card_key_not_blank
  CHECK (card_key <> '');

ALTER TABLE user_login_rate_limits
  ADD CONSTRAINT chk_user_login_rate_limits_email_address_not_blank
  CHECK (email_address <> '');

ALTER TABLE user_login_rate_limits
  ADD CONSTRAINT chk_user_login_rate_limits_scope_type_not_blank
  CHECK (scope_type <> '');

ALTER TABLE user_account_audit
  DROP FOREIGN KEY fk_user_account_audit_actor_user;

ALTER TABLE user_account_audit
  DROP FOREIGN KEY fk_user_account_audit_affected_user;

ALTER TABLE user_account_audit
  ADD CONSTRAINT fk_user_account_audit_actor_user
  FOREIGN KEY (actor_user_id)
  REFERENCES users (id)
  ON DELETE SET NULL
  ON UPDATE CASCADE;

ALTER TABLE user_account_audit
  ADD CONSTRAINT fk_user_account_audit_affected_user
  FOREIGN KEY (affected_user_id)
  REFERENCES users (id)
  ON DELETE RESTRICT
  ON UPDATE CASCADE;

ALTER TABLE user_logon_history
  DROP FOREIGN KEY fk_user_logon_history_user;

ALTER TABLE user_logon_history
  ADD CONSTRAINT fk_user_logon_history_user
  FOREIGN KEY (user_id)
  REFERENCES users (id)
  ON DELETE SET NULL
  ON UPDATE CASCADE;

ALTER TABLE user_totp
  ADD CONSTRAINT chk_user_totp_otp_digits
  CHECK (otp_digits BETWEEN 6 AND 8);

ALTER TABLE user_totp
  ADD CONSTRAINT chk_user_totp_pending_otp_digits
  CHECK (pending_otp_digits IS NULL OR pending_otp_digits BETWEEN 6 AND 8);

ALTER TABLE user_totp
  ADD CONSTRAINT chk_user_totp_otp_period_positive
  CHECK (otp_period > 0);

ALTER TABLE user_totp
  ADD CONSTRAINT chk_user_totp_pending_otp_period_positive
  CHECK (pending_otp_period IS NULL OR pending_otp_period > 0);
