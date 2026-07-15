CREATE TABLE IF NOT EXISTS vat_rate_rules (
  id int(11) NOT NULL AUTO_INCREMENT,
  rate_type varchar(16) NOT NULL,
  scope varchar(32) NOT NULL,
  effective_from date NOT NULL,
  effective_to date DEFAULT NULL,
  rate_percentage decimal(7,3) NOT NULL,
  original_period_text varchar(255) NOT NULL,
  source_url varchar(500) NOT NULL,
  source_content_id varchar(64) NOT NULL,
  source_updated_at datetime DEFAULT NULL,
  source_checked_at datetime NOT NULL,
  rule_version varchar(64) NOT NULL,
  dataset_hash char(64) NOT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  notes text DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_vat_rate_rule_dataset (dataset_hash, rate_type, scope, effective_from),
  KEY idx_vat_rate_rules_lookup (rate_type, scope, is_active, effective_from, effective_to),
  KEY idx_vat_rate_rules_dataset (dataset_hash, is_active),
  CONSTRAINT chk_vat_rate_rule_dates CHECK (effective_to IS NULL OR effective_from <= effective_to),
  CONSTRAINT chk_vat_rate_percentage CHECK (rate_percentage >= 0 AND rate_percentage <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT role_id, 'tax_rates_ct'
FROM role_card_permissions
WHERE card_key = 'tax_rates';

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT role_id, 'tax_rates_vat'
FROM role_card_permissions
WHERE card_key = 'tax_rates';

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT role_id, 'tax_thresholds_vat'
FROM role_card_permissions
WHERE card_key = 'tax_rates';

DELETE FROM role_card_permissions
WHERE card_key = 'tax_rates';
