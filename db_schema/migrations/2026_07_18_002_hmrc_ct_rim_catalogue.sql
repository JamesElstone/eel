CREATE TABLE IF NOT EXISTS hmrc_ct_rim_packages (
  id BIGINT NOT NULL AUTO_INCREMENT,
  form_version VARCHAR(16) NOT NULL,
  artifact_version VARCHAR(64) NOT NULL,
  applicable_from DATE DEFAULT NULL,
  applicable_to DATE DEFAULT NULL,
  published_at DATETIME DEFAULT NULL,
  live_from DATETIME DEFAULT NULL,
  live_to DATETIME DEFAULT NULL,
  hmrc_status VARCHAR(64) NOT NULL DEFAULT 'unknown',
  source_url VARCHAR(500) NOT NULL,
  download_url VARCHAR(1000) DEFAULT NULL,
  local_path VARCHAR(1000) DEFAULT NULL,
  sha256 CHAR(64) DEFAULT NULL,
  source_updated_at DATETIME DEFAULT NULL,
  checked_at DATETIME DEFAULT NULL,
  latest_change_note TEXT DEFAULT NULL,
  package_state VARCHAR(32) NOT NULL DEFAULT 'not_downloaded',
  xsd_count INT NOT NULL DEFAULT 0,
  verification_error TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hmrc_ct_rim_package (form_version, artifact_version),
  KEY idx_hmrc_ct_rim_applicability (form_version, applicable_from, applicable_to),
  KEY idx_hmrc_ct_rim_live (form_version, live_from, live_to, hmrc_status),
  CONSTRAINT chk_hmrc_ct_rim_dates CHECK (applicable_to IS NULL OR applicable_from <= applicable_to),
  CONSTRAINT chk_hmrc_ct_rim_state CHECK (package_state IN ('not_downloaded','downloaded','verified','stale','failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO hmrc_ct_rim_packages
  (form_version, artifact_version, applicable_from, applicable_to, live_from, hmrc_status, source_url)
VALUES
  ('V2', 'V3.99', NULL, NULL, '2015-07-22 00:00:00', 'live', 'https://www.gov.uk/government/publications/corporation-tax-technical-specifications-ct600-rim-artefacts'),
  ('V3', 'V1.994', NULL, NULL, '2026-04-07 08:23:02', 'live', 'https://www.gov.uk/government/publications/corporation-tax-technical-specifications-ct600-rim-artefacts');

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'tax_rates_ct600_rim'
FROM role_card_permissions
WHERE card_key IN ('tax_rates_ct', 'tax_rates_vat', 'tax_thresholds_vat');
