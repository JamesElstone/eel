-- Replace manifest snapshots with a direct, checksum-pinned schema inventory.
CREATE TEMPORARY TABLE ch_schema_inventory_conflict_guard (
  must_be_zero tinyint NOT NULL,
  CONSTRAINT chk_ch_schema_inventory_no_conflicts CHECK (must_be_zero = 0)
);

INSERT INTO ch_schema_inventory_conflict_guard (must_be_zero)
SELECT 1
WHERE EXISTS (
  SELECT 1
  FROM companies_house_schema_files
  GROUP BY source_url
  HAVING COUNT(DISTINCT sha256) > 1 OR COUNT(DISTINCT relative_path) > 1
);

INSERT INTO ch_schema_inventory_conflict_guard (must_be_zero)
SELECT 1
WHERE EXISTS (
  SELECT 1
  FROM companies_house_schema_files
  GROUP BY relative_path
  HAVING COUNT(DISTINCT sha256) > 1 OR COUNT(DISTINCT source_url) > 1
);

CREATE TEMPORARY TABLE ch_schema_validation_evidence (
  submission_id bigint NOT NULL,
  evidence_order datetime NOT NULL,
  evidence_json longtext NOT NULL
);

INSERT INTO ch_schema_validation_evidence (submission_id, evidence_order, evidence_json)
SELECT s.id,
       COALESCE(s.schema_validated_at, s.submitted_at, s.updated_at),
       JSON_OBJECT(
         'operation', 'accounts',
         'preflight_id', NULL,
         'validated_at', COALESCE(s.schema_validated_at, s.submitted_at, s.updated_at),
         'source_url', f.source_url,
         'relative_path', f.relative_path,
         'sha256', f.sha256
       )
FROM companies_house_accounts_submissions s
INNER JOIN companies_house_schema_files f ON f.snapshot_id = s.schema_snapshot_id
WHERE s.schema_snapshot_id IS NOT NULL;

INSERT INTO ch_schema_validation_evidence (submission_id, evidence_order, evidence_json)
SELECT p.submission_id,
       COALESCE(p.checked_at, p.created_at),
       JSON_OBJECT(
         'operation', 'company_data',
         'preflight_id', p.id,
         'validated_at', COALESCE(p.checked_at, p.created_at),
         'source_url', f.source_url,
         'relative_path', f.relative_path,
         'sha256', f.sha256
       )
FROM companies_house_company_auth_preflights p
INNER JOIN companies_house_schema_files f ON f.snapshot_id = p.schema_snapshot_id;

UPDATE companies_house_accounts_submissions s
INNER JOIN (
  SELECT submission_id,
         CONCAT('[', GROUP_CONCAT(evidence_json ORDER BY evidence_order SEPARATOR ','), ']') AS validations_json
  FROM ch_schema_validation_evidence
  GROUP BY submission_id
) evidence ON evidence.submission_id = s.id
SET s.filing_metadata_json = JSON_SET(
  CASE
    WHEN JSON_VALID(s.filing_metadata_json) THEN s.filing_metadata_json
    ELSE JSON_OBJECT()
  END,
  '$.schema_validations',
  JSON_MERGE_PRESERVE(
    CASE
      WHEN JSON_VALID(s.filing_metadata_json)
        AND JSON_TYPE(JSON_EXTRACT(s.filing_metadata_json, '$.schema_validations')) = 'ARRAY'
      THEN JSON_EXTRACT(s.filing_metadata_json, '$.schema_validations')
      ELSE JSON_ARRAY()
    END,
    JSON_EXTRACT(evidence.validations_json, '$')
  )
);

CREATE TABLE companies_house_schema_files_inventory (
  id bigint NOT NULL AUTO_INCREMENT,
  source_url varchar(500) NOT NULL,
  relative_path varchar(500) NOT NULL,
  schema_name varchar(255) NOT NULL,
  file_role enum('envelope','profile_root','dependency') NOT NULL,
  catalogue_status varchar(32) DEFAULT NULL,
  target_namespace varchar(1000) DEFAULT NULL,
  file_size bigint NOT NULL,
  sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  etag varchar(255) DEFAULT NULL,
  last_modified varchar(255) DEFAULT NULL,
  checked_at datetime NOT NULL,
  verified_at datetime NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ch_schema_file_url (source_url),
  UNIQUE KEY uq_ch_schema_file_path (relative_path),
  KEY idx_ch_schema_file_role_status (file_role,catalogue_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO companies_house_schema_files_inventory
  (source_url, relative_path, schema_name, file_role, catalogue_status,
   target_namespace, file_size, sha256, etag, last_modified, checked_at, verified_at)
SELECT f.source_url,
       MIN(f.relative_path),
       MIN(f.schema_name),
       SUBSTRING_INDEX(GROUP_CONCAT(f.file_role ORDER BY s.is_active DESC, s.id DESC), ',', 1),
       NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(f.catalogue_status, '') ORDER BY s.is_active DESC, s.id DESC), ',', 1), ''),
       NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(f.target_namespace, '') ORDER BY s.is_active DESC, s.id DESC), ',', 1), ''),
       MAX(f.file_size),
       MIN(f.sha256),
       NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(f.etag, '') ORDER BY s.is_active DESC, s.id DESC), ',', 1), ''),
       NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(f.last_modified, '') ORDER BY s.is_active DESC, s.id DESC), ',', 1), ''),
       MAX(s.checked_at),
       MAX(s.verified_at)
FROM companies_house_schema_files f
INNER JOIN companies_house_schema_snapshots s ON s.id = f.snapshot_id
GROUP BY f.source_url;

CREATE TABLE companies_house_schema_dependencies_inventory (
  id bigint NOT NULL AUTO_INCREMENT,
  parent_file_id bigint NOT NULL,
  child_file_id bigint NOT NULL,
  relation_type enum('include','import','redefine') NOT NULL,
  declared_namespace varchar(1000) DEFAULT NULL,
  schema_location varchar(1000) NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ch_schema_dependency (parent_file_id,child_file_id,relation_type),
  CONSTRAINT fk_ch_schema_dependency_inventory_parent
    FOREIGN KEY (parent_file_id) REFERENCES companies_house_schema_files_inventory (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ch_schema_dependency_inventory_child
    FOREIGN KEY (child_file_id) REFERENCES companies_house_schema_files_inventory (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO companies_house_schema_dependencies_inventory
  (parent_file_id, child_file_id, relation_type, declared_namespace, schema_location)
SELECT new_parent.id, new_child.id, d.relation_type, d.declared_namespace, d.schema_location
FROM companies_house_schema_dependencies d
INNER JOIN companies_house_schema_files old_parent ON old_parent.id = d.parent_file_id
INNER JOIN companies_house_schema_files old_child ON old_child.id = d.child_file_id
INNER JOIN companies_house_schema_files_inventory new_parent
  ON new_parent.source_url = old_parent.source_url
INNER JOIN companies_house_schema_files_inventory new_child
  ON new_child.source_url = old_child.source_url;

ALTER TABLE companies_house_accounts_submissions
  DROP FOREIGN KEY IF EXISTS fk_ch_accounts_submission_schema_snapshot,
  DROP INDEX IF EXISTS idx_ch_accounts_submission_schema_snapshot,
  DROP COLUMN IF EXISTS schema_snapshot_id,
  DROP COLUMN IF EXISTS schema_manifest_sha256;

ALTER TABLE companies_house_company_auth_preflights
  DROP FOREIGN KEY IF EXISTS fk_ch_company_auth_preflight_schema,
  DROP COLUMN IF EXISTS schema_snapshot_id,
  DROP COLUMN IF EXISTS schema_manifest_sha256;

DROP TABLE companies_house_schema_dependencies;
DROP TABLE companies_house_schema_files;
DROP TABLE companies_house_schema_snapshots;
RENAME TABLE companies_house_schema_files_inventory TO companies_house_schema_files;
RENAME TABLE companies_house_schema_dependencies_inventory TO companies_house_schema_dependencies;

DROP TEMPORARY TABLE ch_schema_validation_evidence;
DROP TEMPORARY TABLE ch_schema_inventory_conflict_guard;
