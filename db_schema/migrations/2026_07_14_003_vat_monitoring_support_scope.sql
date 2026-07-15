ALTER TABLE companies
  ADD COLUMN IF NOT EXISTS vat_validation_mode varchar(8) DEFAULT NULL AFTER vat_validation_source;

CREATE TABLE IF NOT EXISTS vat_threshold_rules (
  id int(11) NOT NULL AUTO_INCREMENT,
  threshold_type varchar(32) NOT NULL,
  jurisdiction varchar(32) NOT NULL,
  effective_from date NOT NULL,
  effective_to date DEFAULT NULL,
  original_period_text varchar(255) NOT NULL,
  registration_threshold decimal(14,2) DEFAULT NULL,
  deregistration_threshold decimal(14,2) DEFAULT NULL,
  source_url varchar(500) NOT NULL,
  source_content_id char(36) NOT NULL,
  source_updated_at datetime DEFAULT NULL,
  source_checked_at datetime NOT NULL,
  dataset_hash char(64) NOT NULL,
  row_hash char(64) NOT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  audit_notes text DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_vat_threshold_rule_dataset_row (dataset_hash, row_hash),
  KEY idx_vat_threshold_rules_lookup (threshold_type, jurisdiction, is_active, effective_from, effective_to),
  KEY idx_vat_threshold_rules_dataset (dataset_hash, is_active),
  CONSTRAINT chk_vat_threshold_rule_dates CHECK (effective_to IS NULL OR effective_from <= effective_to),
  CONSTRAINT chk_vat_threshold_registration_amount CHECK (registration_threshold IS NULL OR registration_threshold > 0),
  CONSTRAINT chk_vat_threshold_deregistration_amount CHECK (deregistration_threshold IS NULL OR deregistration_threshold > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT role_id, 'vat_turnover_monitoring'
FROM role_card_permissions
WHERE card_key IN ('vat_registration', 'vat_readiness');

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT role_id, 'tax_vat_threshold'
FROM role_card_permissions
WHERE card_key IN ('tax_corporation_tax_summary', 'tax_warnings');

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'vat_support_scope'
FROM role_card_permissions
WHERE LEFT(card_key, 4) = 'tax_'
   OR LEFT(card_key, 9) = 'year_end_';
