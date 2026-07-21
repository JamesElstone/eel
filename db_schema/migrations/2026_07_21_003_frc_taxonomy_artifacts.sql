CREATE TABLE IF NOT EXISTS frc_taxonomy_packages (
  id BIGINT NOT NULL AUTO_INCREMENT,
  taxonomy_version VARCHAR(32) NOT NULL,
  artifact_version VARCHAR(32) NOT NULL,
  source_url VARCHAR(1000) NOT NULL,
  download_url VARCHAR(1000) DEFAULT NULL,
  local_path VARCHAR(1000) DEFAULT NULL,
  sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  package_state ENUM('not_downloaded','verified','failed','stale') NOT NULL DEFAULT 'not_downloaded',
  verification_error TEXT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATE DEFAULT NULL,
  verified_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_frc_taxonomy_identity (taxonomy_version, artifact_version),
  KEY idx_frc_taxonomy_active (is_active, package_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ixbrl_generation_runs
  ADD COLUMN IF NOT EXISTS external_taxonomy_package_id BIGINT DEFAULT NULL AFTER external_validated_sha256,
  ADD COLUMN IF NOT EXISTS external_taxonomy_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER external_taxonomy_package_id,
  ADD KEY IF NOT EXISTS idx_ixbrl_external_taxonomy (external_taxonomy_package_id);

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'tax_frc_taxonomy' FROM role_card_permissions WHERE card_key = 'tax_rates_ct600_rim';
