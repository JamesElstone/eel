CREATE TABLE IF NOT EXISTS companies_house_schema_catalogue (
  id BIGINT NOT NULL AUTO_INCREMENT,
  schema_name VARCHAR(255) NOT NULL,
  source_url VARCHAR(500) NOT NULL,
  lifecycle_status ENUM('released','live','deprecated','retired') NOT NULL,
  release_date DATE DEFAULT NULL,
  live_date DATE DEFAULT NULL,
  deprecated_date DATE DEFAULT NULL,
  retirement_date DATE DEFAULT NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ch_schema_catalogue_url (source_url),
  KEY idx_ch_schema_catalogue_status (lifecycle_status, schema_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies_house_schema_snapshots (
  id BIGINT NOT NULL AUTO_INCREMENT,
  manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  catalogue_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  local_path VARCHAR(1000) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  profile_name VARCHAR(64) NOT NULL DEFAULT 'revised_accounts',
  root_count INT NOT NULL DEFAULT 0,
  dependency_count INT NOT NULL DEFAULT 0,
  file_count INT NOT NULL DEFAULT 0,
  checked_at DATETIME NOT NULL,
  verified_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ch_schema_snapshot_manifest (manifest_sha256),
  KEY idx_ch_schema_snapshot_active (profile_name, is_active, verified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies_house_schema_files (
  id BIGINT NOT NULL AUTO_INCREMENT,
  snapshot_id BIGINT NOT NULL,
  source_url VARCHAR(500) NOT NULL,
  relative_path VARCHAR(500) NOT NULL,
  schema_name VARCHAR(255) NOT NULL,
  file_role ENUM('envelope','profile_root','dependency') NOT NULL,
  catalogue_status VARCHAR(32) DEFAULT NULL,
  target_namespace VARCHAR(1000) DEFAULT NULL,
  file_size BIGINT NOT NULL,
  sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  etag VARCHAR(255) DEFAULT NULL,
  last_modified VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ch_schema_file_url (snapshot_id, source_url),
  UNIQUE KEY uq_ch_schema_file_path (snapshot_id, relative_path),
  KEY idx_ch_schema_file_snapshot_role (snapshot_id, file_role),
  CONSTRAINT fk_ch_schema_file_snapshot FOREIGN KEY (snapshot_id)
    REFERENCES companies_house_schema_snapshots (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies_house_schema_dependencies (
  id BIGINT NOT NULL AUTO_INCREMENT,
  snapshot_id BIGINT NOT NULL,
  parent_file_id BIGINT NOT NULL,
  child_file_id BIGINT NOT NULL,
  relation_type ENUM('include','import','redefine') NOT NULL,
  declared_namespace VARCHAR(1000) DEFAULT NULL,
  schema_location VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ch_schema_dependency (parent_file_id, child_file_id, relation_type),
  KEY idx_ch_schema_dependency_snapshot (snapshot_id),
  CONSTRAINT fk_ch_schema_dependency_snapshot FOREIGN KEY (snapshot_id)
    REFERENCES companies_house_schema_snapshots (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ch_schema_dependency_parent FOREIGN KEY (parent_file_id)
    REFERENCES companies_house_schema_files (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ch_schema_dependency_child FOREIGN KEY (child_file_id)
    REFERENCES companies_house_schema_files (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE companies_house_accounts_submissions
  ADD COLUMN IF NOT EXISTS schema_snapshot_id BIGINT DEFAULT NULL AFTER revised_artifact_sha256,
  ADD COLUMN IF NOT EXISTS schema_manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER schema_snapshot_id,
  ADD COLUMN IF NOT EXISTS schema_validated_at DATETIME DEFAULT NULL AFTER schema_manifest_sha256,
  ADD KEY IF NOT EXISTS idx_ch_accounts_submission_schema_snapshot (schema_snapshot_id),
  ADD CONSTRAINT fk_ch_accounts_submission_schema_snapshot FOREIGN KEY (schema_snapshot_id)
    REFERENCES companies_house_schema_snapshots (id) ON DELETE RESTRICT ON UPDATE CASCADE;

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'tax_companies_house_accounts_schemas'
FROM role_card_permissions
WHERE card_key = 'tax_rates_ct600_rim';
