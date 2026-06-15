ALTER TABLE user_account_invites
  ADD COLUMN IF NOT EXISTS token_value char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER token_hash;

CREATE TABLE IF NOT EXISTS user_account_invite_deliveries (
  id int(11) NOT NULL AUTO_INCREMENT,
  invite_id int(11) NOT NULL,
  contact_method varchar(20) NOT NULL,
  sent_to varchar(255) NOT NULL,
  status varchar(30) NOT NULL DEFAULT 'created',
  sent_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  created_by_user_id int(11) DEFAULT NULL,
  error_summary varchar(255) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_user_account_invite_deliveries_invite_id (invite_id),
  KEY idx_user_account_invite_deliveries_contact_method (contact_method),
  KEY idx_user_account_invite_deliveries_sent_at (sent_at),
  KEY idx_user_account_invite_deliveries_created_by (created_by_user_id),
  CONSTRAINT fk_user_account_invite_deliveries_invite FOREIGN KEY (invite_id) REFERENCES user_account_invites (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_user_account_invite_deliveries_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_user_account_invite_deliveries_contact_method_not_blank CHECK (contact_method <> ''),
  CONSTRAINT chk_user_account_invite_deliveries_sent_to_not_blank CHECK (sent_to <> ''),
  CONSTRAINT chk_user_account_invite_deliveries_status_not_blank CHECK (status <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO user_account_invite_deliveries (
  invite_id,
  contact_method,
  sent_to,
  status,
  sent_at,
  created_at,
  created_by_user_id,
  error_summary
)
SELECT invites.id,
       invites.contact_method,
       invites.sent_to,
       CASE WHEN invites.last_sent_at IS NOT NULL THEN 'sent' ELSE 'created' END,
       invites.last_sent_at,
       invites.created_at,
       invites.created_by_user_id,
       NULL
FROM user_account_invites invites
WHERE invites.contact_method <> ''
  AND invites.sent_to <> ''
  AND NOT EXISTS (
    SELECT 1
    FROM user_account_invite_deliveries existing
    WHERE existing.invite_id = invites.id
      AND existing.contact_method = invites.contact_method
      AND existing.sent_to = invites.sent_to
  );

ALTER TABLE user_account_invites
  DROP CONSTRAINT IF EXISTS chk_user_account_invites_contact_method_not_blank;

ALTER TABLE user_account_invites
  DROP CONSTRAINT IF EXISTS chk_user_account_invites_sent_to_not_blank;

ALTER TABLE user_account_invites
  DROP COLUMN IF EXISTS contact_method,
  DROP COLUMN IF EXISTS sent_to;
