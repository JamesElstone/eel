ALTER TABLE users
  ADD COLUMN must_change_password tinyint(1) NOT NULL DEFAULT 0 AFTER password_changed_at;

ALTER TABLE user_account_audit
  MODIFY action_type enum('user_created','user_enabled','user_disabled','password_set_admin','password_change_required_admin','password_changed_self','email_changed','display_name_changed','otp_reset_admin','otp_rotation_started','otp_rotation_completed','mfa_authenticated','role_changed') NOT NULL;
