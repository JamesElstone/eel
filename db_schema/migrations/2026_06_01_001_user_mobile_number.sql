ALTER TABLE users
  ADD COLUMN IF NOT EXISTS mobile_number varchar(32) DEFAULT NULL AFTER email_address;

ALTER TABLE user_account_audit
  MODIFY action_type enum('user_created','user_enabled','user_disabled','password_set_admin','password_change_required_admin','password_changed_self','email_changed','display_name_changed','mobile_number_changed','otp_requirement_changed','otp_reset_admin','login_lockout_reset_admin','otp_rotation_started','otp_rotation_completed','mfa_authenticated','role_changed') NOT NULL;
