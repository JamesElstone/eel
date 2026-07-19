CREATE TABLE IF NOT EXISTS hmrc_ct_computation_packages (
  id BIGINT NOT NULL AUTO_INCREMENT,
  taxonomy_version VARCHAR(32) NOT NULL,
  artifact_version VARCHAR(64) NOT NULL,
  applicable_from DATE NOT NULL,
  applicable_to DATE DEFAULT NULL,
  source_url VARCHAR(500) NOT NULL,
  download_url VARCHAR(1000) DEFAULT NULL,
  local_path VARCHAR(1000) DEFAULT NULL,
  entry_point_path VARCHAR(1000) DEFAULT NULL,
  combined_dpl_entry_point_path VARCHAR(1000) DEFAULT NULL,
  sha256 CHAR(64) DEFAULT NULL,
  package_state VARCHAR(32) NOT NULL DEFAULT 'not_downloaded',
  verification_error TEXT DEFAULT NULL,
  checked_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hmrc_ct_computation_package (taxonomy_version, artifact_version),
  KEY idx_hmrc_ct_computation_applicability (applicable_from, applicable_to, package_state),
  CONSTRAINT chk_hmrc_ct_computation_dates CHECK (applicable_to IS NULL OR applicable_from <= applicable_to),
  CONSTRAINT chk_hmrc_ct_computation_state CHECK (package_state IN ('not_downloaded','downloaded','verified','stale','failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hmrc_ct_computation_files (
  id BIGINT NOT NULL AUTO_INCREMENT,
  package_id BIGINT NOT NULL,
  archive_path VARCHAR(1000) NOT NULL,
  extracted_path VARCHAR(1000) NOT NULL,
  file_type ENUM('xsd','xml','json','linkbase','other') NOT NULL DEFAULT 'other',
  file_role VARCHAR(64) DEFAULT NULL,
  file_size BIGINT NOT NULL DEFAULT 0,
  sha256 CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hmrc_ct_computation_file (package_id, archive_path),
  KEY idx_hmrc_ct_computation_file_role (package_id, file_role),
  CONSTRAINT fk_hmrc_ct_computation_file_package FOREIGN KEY (package_id) REFERENCES hmrc_ct_computation_packages (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hmrc_ct_computation_concepts (
  id BIGINT NOT NULL AUTO_INCREMENT,
  package_id BIGINT NOT NULL,
  qname VARCHAR(255) NOT NULL,
  namespace_uri VARCHAR(500) NOT NULL,
  local_name VARCHAR(255) NOT NULL,
  data_type VARCHAR(255) DEFAULT NULL,
  period_type ENUM('instant','duration') DEFAULT NULL,
  substitution_group VARCHAR(255) DEFAULT NULL,
  is_abstract TINYINT(1) NOT NULL DEFAULT 0,
  is_dimension TINYINT(1) NOT NULL DEFAULT 0,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hmrc_ct_computation_concept (package_id, namespace_uri, local_name),
  KEY idx_hmrc_ct_computation_concept_qname (package_id, qname),
  CONSTRAINT fk_hmrc_ct_computation_concept_package FOREIGN KEY (package_id) REFERENCES hmrc_ct_computation_packages (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hmrc_ct_rim_components (
  id BIGINT NOT NULL AUTO_INCREMENT,
  package_id BIGINT NOT NULL,
  component_path VARCHAR(1000) NOT NULL,
  element_name VARCHAR(255) NOT NULL,
  namespace_uri VARCHAR(500) DEFAULT NULL,
  data_type VARCHAR(255) DEFAULT NULL,
  min_occurs INT DEFAULT NULL,
  max_occurs VARCHAR(32) DEFAULT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hmrc_ct_rim_component (package_id, component_path),
  KEY idx_hmrc_ct_rim_component_name (package_id, element_name),
  CONSTRAINT fk_hmrc_ct_rim_component_package FOREIGN KEY (package_id) REFERENCES hmrc_ct_rim_packages (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ct_filing_mapping_profiles (
  id BIGINT NOT NULL AUTO_INCREMENT,
  target_type ENUM('ct600_rim','computation_ixbrl') NOT NULL,
  rim_package_id BIGINT DEFAULT NULL,
  computation_package_id BIGINT DEFAULT NULL,
  profile_name VARCHAR(150) NOT NULL,
  revision_no INT NOT NULL DEFAULT 1,
  status ENUM('draft','validated','active','retired') NOT NULL DEFAULT 'draft',
  parent_profile_id BIGINT DEFAULT NULL,
  content_hash CHAR(64) DEFAULT NULL,
  compatibility_status ENUM('pending','compatible','incompatible') NOT NULL DEFAULT 'pending',
  compatibility_json LONGTEXT DEFAULT NULL,
  created_by VARCHAR(100) NOT NULL,
  validated_by VARCHAR(100) DEFAULT NULL,
  validated_at DATETIME DEFAULT NULL,
  activated_by VARCHAR(100) DEFAULT NULL,
  activated_at DATETIME DEFAULT NULL,
  retired_by VARCHAR(100) DEFAULT NULL,
  retired_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ct_filing_mapping_revision (target_type, profile_name, revision_no),
  KEY idx_ct_filing_mapping_active (target_type, status),
  KEY idx_ct_filing_mapping_rim_package (rim_package_id, status),
  KEY idx_ct_filing_mapping_computation_package (computation_package_id, status),
  CONSTRAINT fk_ct_filing_mapping_rim_package FOREIGN KEY (rim_package_id) REFERENCES hmrc_ct_rim_packages (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_filing_mapping_computation_package FOREIGN KEY (computation_package_id) REFERENCES hmrc_ct_computation_packages (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_filing_mapping_parent FOREIGN KEY (parent_profile_id) REFERENCES ct_filing_mapping_profiles (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ct600_rim_mappings (
  id BIGINT NOT NULL AUTO_INCREMENT,
  profile_id BIGINT NOT NULL,
  canonical_key VARCHAR(180) NOT NULL,
  target_xpath VARCHAR(1000) NOT NULL,
  value_type ENUM('numeric','text','date','boolean','integer') NOT NULL,
  sign_multiplier DECIMAL(8,2) NOT NULL DEFAULT 1.00,
  null_policy ENUM('omit','nil','error') NOT NULL DEFAULT 'omit',
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ct600_rim_mapping_source (profile_id, canonical_key),
  UNIQUE KEY uq_ct600_rim_mapping_target (profile_id, target_xpath),
  CONSTRAINT fk_ct600_rim_mapping_profile FOREIGN KEY (profile_id) REFERENCES ct_filing_mapping_profiles (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ct_computation_ixbrl_mappings (
  id BIGINT NOT NULL AUTO_INCREMENT,
  profile_id BIGINT NOT NULL,
  canonical_key VARCHAR(180) NOT NULL,
  taxonomy_concept VARCHAR(255) NOT NULL,
  namespace_uri VARCHAR(500) NOT NULL,
  local_name VARCHAR(255) NOT NULL,
  value_type ENUM('numeric','text','date','boolean','integer') NOT NULL,
  period_type ENUM('instant','duration') NOT NULL DEFAULT 'duration',
  context_profile VARCHAR(100) NOT NULL DEFAULT 'ct_period',
  unit_ref VARCHAR(50) DEFAULT NULL,
  decimals_value VARCHAR(20) DEFAULT NULL,
  dimensions_json LONGTEXT DEFAULT NULL,
  sign_multiplier DECIMAL(8,2) NOT NULL DEFAULT 1.00,
  presentation_section VARCHAR(100) NOT NULL,
  presentation_label VARCHAR(255) NOT NULL,
  null_policy ENUM('omit','nil','error') NOT NULL DEFAULT 'omit',
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ct_computation_mapping_source (profile_id, canonical_key),
  KEY idx_ct_computation_mapping_section (profile_id, presentation_section, sort_order),
  CONSTRAINT fk_ct_computation_mapping_profile FOREIGN KEY (profile_id) REFERENCES ct_filing_mapping_profiles (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ct_filing_mapping_events (
  id BIGINT NOT NULL AUTO_INCREMENT,
  profile_id BIGINT NOT NULL,
  event_type ENUM('created','cloned','mapping_changed','validated','activated','retired','validation_failed') NOT NULL,
  actor VARCHAR(100) NOT NULL,
  detail_json LONGTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ct_filing_mapping_event_profile (profile_id, id),
  CONSTRAINT fk_ct_filing_mapping_event_profile FOREIGN KEY (profile_id) REFERENCES ct_filing_mapping_profiles (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE corporation_tax_computation_runs
  ADD COLUMN IF NOT EXISTS ixbrl_status VARCHAR(32) NOT NULL DEFAULT 'not_generated' AFTER summary_json,
  ADD COLUMN IF NOT EXISTS computation_taxonomy_package_id BIGINT DEFAULT NULL AFTER ixbrl_status,
  ADD COLUMN IF NOT EXISTS ixbrl_mapping_profile_id BIGINT DEFAULT NULL AFTER computation_taxonomy_package_id,
  ADD COLUMN IF NOT EXISTS ixbrl_mapping_hash CHAR(64) DEFAULT NULL AFTER ixbrl_mapping_profile_id,
  ADD COLUMN IF NOT EXISTS filing_basis_version VARCHAR(50) DEFAULT NULL AFTER ixbrl_mapping_hash,
  ADD COLUMN IF NOT EXISTS filing_basis_hash CHAR(64) DEFAULT NULL AFTER filing_basis_version,
  ADD COLUMN IF NOT EXISTS ixbrl_generated_at DATETIME DEFAULT NULL AFTER external_validated_sha256;

INSERT IGNORE INTO hmrc_ct_computation_packages
  (taxonomy_version, artifact_version, applicable_from, applicable_to, source_url, package_state)
VALUES
  ('2025', 'unconfigured', '2015-04-01', NULL, 'https://www.gov.uk/government/publications/corporation-tax-technical-specifications-xbrl-and-ixbrl', 'not_downloaded');

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, new_card.card_key
FROM role_card_permissions existing_permission
INNER JOIN (
  SELECT 'tax_rates_ct_computation_taxonomy' AS card_key
  UNION ALL SELECT 'tax_ct600_rim_mappings'
  UNION ALL SELECT 'tax_ct_computation_mappings'
  UNION ALL SELECT 'tax_ct_period_return'
) new_card
WHERE existing_permission.card_key IN ('tax_rates_ct600_rim', 'tax_corporation_tax_summary');
