CREATE TABLE IF NOT EXISTS signup_token_rate_limits (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  client_ip varchar(45) NOT NULL,
  failed_attempts int(10) unsigned NOT NULL DEFAULT 0,
  window_started_at datetime DEFAULT NULL,
  last_failed_at datetime DEFAULT NULL,
  blocked_at datetime DEFAULT NULL,
  block_expires_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_signup_token_rate_limits_client_ip (client_ip),
  KEY idx_signup_token_rate_limits_block_expires_at (block_expires_at),
  KEY idx_signup_token_rate_limits_last_failed_at (last_failed_at),
  CONSTRAINT chk_signup_token_rate_limits_client_ip_not_blank CHECK (client_ip <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
