ALTER TABLE users
  ADD COLUMN IF NOT EXISTS account_status varchar(30) NOT NULL DEFAULT 'active' AFTER is_active,
  ADD COLUMN IF NOT EXISTS account_completed_at datetime DEFAULT NULL AFTER password_changed_at;

ALTER TABLE users
  MODIFY email_address varchar(255) DEFAULT NULL,
  MODIFY password_hash varchar(255) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS user_account_invites (
  id int(11) NOT NULL AUTO_INCREMENT,
  user_id int(11) NOT NULL,
  token_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  purpose varchar(50) NOT NULL DEFAULT 'account_completion',
  status varchar(30) NOT NULL DEFAULT 'pending',
  contact_method varchar(20) NOT NULL,
  sent_to varchar(255) NOT NULL,
  expires_at datetime NOT NULL,
  used_at datetime DEFAULT NULL,
  revoked_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  created_by_user_id int(11) DEFAULT NULL,
  last_sent_at datetime DEFAULT NULL,
  send_attempts int(11) NOT NULL DEFAULT 0,
  failed_attempts int(11) NOT NULL DEFAULT 0,
  last_failed_at datetime DEFAULT NULL,
  next_allowed_attempt_at datetime DEFAULT NULL,
  locked_at datetime DEFAULT NULL,
  lock_expires_at datetime DEFAULT NULL,
  opened_at datetime DEFAULT NULL,
  verified_at datetime DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  ip_created varchar(45) DEFAULT NULL,
  ip_opened varchar(45) DEFAULT NULL,
  ip_used varchar(45) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_account_invites_token_hash (token_hash),
  KEY idx_user_account_invites_user_id (user_id),
  KEY idx_user_account_invites_status (status),
  KEY idx_user_account_invites_expires_at (expires_at),
  CONSTRAINT fk_user_account_invites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_user_account_invites_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_user_account_invites_purpose_not_blank CHECK (purpose <> ''),
  CONSTRAINT chk_user_account_invites_status_not_blank CHECK (status <> ''),
  CONSTRAINT chk_user_account_invites_contact_method_not_blank CHECK (contact_method <> ''),
  CONSTRAINT chk_user_account_invites_sent_to_not_blank CHECK (sent_to <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE user_account_audit
  MODIFY action_type enum('user_created','user_enabled','user_disabled','password_set_admin','password_change_required_admin','password_changed_self','email_changed','display_name_changed','mobile_number_changed','otp_requirement_changed','otp_reset_admin','login_lockout_reset_admin','otp_rotation_started','otp_rotation_completed','mfa_authenticated','role_changed','invite_created','invite_link_copied','invite_email_sent','invite_sms_sent','invite_opened','invite_verification_failed','invite_verification_succeeded','invite_completion_failed','invite_completed','invite_expired','invite_revoked','invite_locked') NOT NULL;
