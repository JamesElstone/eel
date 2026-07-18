CREATE TABLE IF NOT EXISTS hmrc_ct_rim_files (
  id BIGINT NOT NULL AUTO_INCREMENT,
  package_id BIGINT NOT NULL,
  archive_path VARCHAR(1000) NOT NULL,
  extracted_path VARCHAR(1000) NOT NULL,
  file_type VARCHAR(16) NOT NULL,
  file_size BIGINT NOT NULL DEFAULT 0,
  sha256 CHAR(64) DEFAULT NULL,
  file_role VARCHAR(64) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hmrc_ct_rim_file (package_id, archive_path),
  KEY idx_hmrc_ct_rim_file_package (package_id),
  KEY idx_hmrc_ct_rim_file_role (package_id, file_role),
  CONSTRAINT chk_hmrc_ct_rim_file_type CHECK (file_type IN ('xsd','sch','xslt'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE hmrc_ct_rim_packages
  ADD COLUMN IF NOT EXISTS applicability_source_file_id BIGINT DEFAULT NULL AFTER xsd_count,
  ADD COLUMN IF NOT EXISTS applicability_xpath VARCHAR(500) DEFAULT NULL AFTER applicability_source_file_id,
  ADD COLUMN IF NOT EXISTS applicability_extracted_at DATETIME DEFAULT NULL AFTER applicability_xpath,
  ADD COLUMN IF NOT EXISTS applicability_status VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER applicability_extracted_at,
  ADD CONSTRAINT chk_hmrc_ct_rim_applicability_status CHECK (applicability_status IN ('pending','confirmed','open_start','ambiguous','failed'));
