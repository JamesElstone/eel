ALTER TABLE corporation_tax_audit_snapshots
  ADD COLUMN IF NOT EXISTS calculation_trace_version VARCHAR(64) DEFAULT NULL AFTER basis_hash,
  ADD COLUMN IF NOT EXISTS calculation_trace_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER calculation_trace_version,
  ADD COLUMN IF NOT EXISTS calculation_trace_json LONGTEXT DEFAULT NULL AFTER calculation_trace_hash;

CREATE TABLE IF NOT EXISTS filing_evidence_bundles (
  id BIGINT NOT NULL AUTO_INCREMENT,
  evidence_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  year_end_review_id INT DEFAULT NULL,
  predecessor_bundle_id BIGINT DEFAULT NULL,
  lifecycle_status ENUM('current','reopened','invalidated','superseded') NOT NULL DEFAULT 'current',
  evidence_version VARCHAR(64) NOT NULL,
  application_name VARCHAR(100) NOT NULL,
  application_version VARCHAR(100) NOT NULL,
  calculation_build VARCHAR(100) NOT NULL,
  locked_at DATETIME NOT NULL,
  locked_by VARCHAR(100) NOT NULL,
  bundle_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  legacy_backfill TINYINT(1) NOT NULL DEFAULT 0,
  reopened_at DATETIME DEFAULT NULL,
  superseded_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_filing_evidence_id (evidence_id),
  KEY idx_filing_evidence_lock (company_id, accounting_period_id, locked_at),
  KEY idx_filing_evidence_period (company_id, accounting_period_id, id),
  KEY idx_filing_evidence_predecessor (predecessor_bundle_id),
  CONSTRAINT fk_filing_evidence_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_filing_evidence_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_filing_evidence_year_end FOREIGN KEY (year_end_review_id) REFERENCES year_end_reviews (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_filing_evidence_predecessor FOREIGN KEY (predecessor_bundle_id) REFERENCES filing_evidence_bundles (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS filing_evidence_ct_snapshots (
  id BIGINT NOT NULL AUTO_INCREMENT,
  bundle_id BIGINT NOT NULL,
  ct_period_id INT NOT NULL,
  computation_run_id INT NOT NULL,
  tax_audit_snapshot_id BIGINT NOT NULL,
  calculation_basis_version VARCHAR(64) NOT NULL,
  calculation_basis_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  trace_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_filing_evidence_snapshot (bundle_id, ct_period_id),
  KEY idx_filing_evidence_snapshot_lookup (tax_audit_snapshot_id),
  CONSTRAINT fk_filing_evidence_snapshot_bundle FOREIGN KEY (bundle_id) REFERENCES filing_evidence_bundles (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_filing_evidence_snapshot_period FOREIGN KEY (ct_period_id) REFERENCES corporation_tax_periods (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_filing_evidence_snapshot_run FOREIGN KEY (computation_run_id) REFERENCES corporation_tax_computation_runs (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_filing_evidence_snapshot_audit FOREIGN KEY (tax_audit_snapshot_id) REFERENCES corporation_tax_audit_snapshots (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS filing_evidence_artifacts (
  id BIGINT NOT NULL AUTO_INCREMENT,
  artifact_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  transaction_hex CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  bundle_id BIGINT NOT NULL,
  ct_period_id INT DEFAULT NULL,
  artifact_role VARCHAR(64) NOT NULL,
  artifact_status ENUM('reserved','generated','validated','failed','historical') NOT NULL DEFAULT 'reserved',
  filename VARCHAR(255) DEFAULT NULL,
  storage_path VARCHAR(1000) DEFAULT NULL,
  sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  schema_identity VARCHAR(255) DEFAULT NULL,
  schema_manifest_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  generator_name VARCHAR(100) NOT NULL,
  generator_version VARCHAR(100) NOT NULL,
  validator_name VARCHAR(100) DEFAULT NULL,
  validator_version VARCHAR(100) DEFAULT NULL,
  validation_status VARCHAR(32) DEFAULT NULL,
  identifier_embedded TINYINT(1) NOT NULL DEFAULT 0,
  legacy_non_embedded TINYINT(1) NOT NULL DEFAULT 0,
  metadata_json LONGTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_filing_evidence_artifact_id (artifact_id),
  UNIQUE KEY uq_filing_evidence_transaction_hex (transaction_hex),
  KEY idx_filing_evidence_artifact_bundle (bundle_id, artifact_role, id),
  CONSTRAINT fk_filing_evidence_artifact_bundle FOREIGN KEY (bundle_id) REFERENCES filing_evidence_bundles (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_filing_evidence_artifact_ct_period FOREIGN KEY (ct_period_id) REFERENCES corporation_tax_periods (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS filing_evidence_events (
  id BIGINT NOT NULL AUTO_INCREMENT,
  bundle_id BIGINT NOT NULL,
  artifact_id BIGINT DEFAULT NULL,
  event_type VARCHAR(64) NOT NULL,
  event_status VARCHAR(32) NOT NULL DEFAULT 'info',
  actor VARCHAR(100) NOT NULL,
  event_message TEXT NOT NULL,
  event_context_json LONGTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_filing_evidence_events_bundle (bundle_id, id),
  CONSTRAINT fk_filing_evidence_event_bundle FOREIGN KEY (bundle_id) REFERENCES filing_evidence_bundles (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_filing_evidence_event_artifact FOREIGN KEY (artifact_id) REFERENCES filing_evidence_artifacts (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ixbrl_accounts_filing_approvals
  ADD COLUMN IF NOT EXISTS evidence_bundle_id BIGINT DEFAULT NULL AFTER id;
ALTER TABLE ixbrl_accounts_filing_approvals
  ADD KEY IF NOT EXISTS idx_ixbrl_approval_evidence_bundle (evidence_bundle_id),
  ADD CONSTRAINT fk_ixbrl_approval_evidence_bundle FOREIGN KEY IF NOT EXISTS (evidence_bundle_id) REFERENCES filing_evidence_bundles (id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE hmrc_ct600_submissions
  ADD COLUMN IF NOT EXISTS evidence_bundle_id BIGINT DEFAULT NULL AFTER id;
ALTER TABLE hmrc_ct600_submissions
  ADD KEY IF NOT EXISTS idx_hmrc_ct600_evidence_bundle (evidence_bundle_id),
  ADD CONSTRAINT fk_hmrc_ct600_evidence_bundle FOREIGN KEY IF NOT EXISTS (evidence_bundle_id) REFERENCES filing_evidence_bundles (id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE companies_house_accounts_submissions
  ADD COLUMN IF NOT EXISTS evidence_bundle_id BIGINT DEFAULT NULL AFTER id;
ALTER TABLE companies_house_accounts_submissions
  ADD KEY IF NOT EXISTS idx_ch_accounts_evidence_bundle (evidence_bundle_id),
  ADD CONSTRAINT fk_ch_accounts_evidence_bundle FOREIGN KEY IF NOT EXISTS (evidence_bundle_id) REFERENCES filing_evidence_bundles (id) ON DELETE RESTRICT ON UPDATE CASCADE;

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, new_card.card_key
FROM role_card_permissions existing_permission
CROSS JOIN (
  SELECT 'filing_evidence_lookup' AS card_key
  UNION ALL SELECT 'filing_evidence_overview'
  UNION ALL SELECT 'filing_evidence_artifacts'
  UNION ALL SELECT 'filing_evidence_calculations'
  UNION ALL SELECT 'filing_evidence_calculation_detail'
) new_card
WHERE existing_permission.card_key IN ('tax_audit_areas','tax_audit_detail');

-- Historical records are assigned stable references without altering artifact bytes.
INSERT IGNORE INTO filing_evidence_bundles
  (evidence_id, company_id, accounting_period_id, year_end_review_id,
   lifecycle_status, evidence_version, application_name, application_version,
   calculation_build, locked_at, locked_by, bundle_hash, legacy_backfill)
SELECT CONCAT('EEL-FE-', UPPER(SUBSTRING(SHA2(CONCAT('legacy-snapshot:', s.id, ':', s.basis_hash), 256), 1, 32))),
       s.company_id, s.accounting_period_id, yr.id, 'superseded', 'filing-evidence-v1',
       'EEL Accounts', 'legacy', s.basis_version, s.created_at, 'legacy-backfill',
       SHA2(CONCAT('legacy-bundle:', s.id, ':', s.basis_hash), 256), 1
FROM corporation_tax_audit_snapshots s
LEFT JOIN year_end_reviews yr
  ON yr.company_id = s.company_id AND yr.accounting_period_id = s.accounting_period_id;

INSERT IGNORE INTO filing_evidence_ct_snapshots
  (bundle_id, ct_period_id, computation_run_id, tax_audit_snapshot_id,
   calculation_basis_version, calculation_basis_hash, trace_hash)
SELECT b.id, s.ct_period_id, s.computation_run_id, s.id,
       s.basis_version, s.basis_hash, s.calculation_trace_hash
FROM corporation_tax_audit_snapshots s
INNER JOIN filing_evidence_bundles b
  ON b.evidence_id = CONVERT(CONCAT('EEL-FE-', UPPER(SUBSTRING(SHA2(CONCAT('legacy-snapshot:', s.id, ':', s.basis_hash), 256), 1, 32))) USING ascii) COLLATE ascii_bin;

INSERT IGNORE INTO filing_evidence_events
  (bundle_id, event_type, event_status, actor, event_message, event_context_json, created_at)
SELECT b.id, 'legacy_backfill', 'warning', 'migration',
       'Historical frozen evidence registered; identifiers were not embedded in the original files.',
       JSON_OBJECT('legacy_non_embedded', 1), b.created_at
FROM filing_evidence_bundles b
WHERE b.legacy_backfill = 1;

UPDATE hmrc_ct600_submissions h
INNER JOIN filing_evidence_ct_snapshots es ON es.computation_run_id = h.computation_run_id
SET h.evidence_bundle_id = es.bundle_id
WHERE h.evidence_bundle_id IS NULL;

UPDATE ixbrl_accounts_filing_approvals a
SET a.evidence_bundle_id = (
  SELECT MAX(b.id) FROM filing_evidence_bundles b
  WHERE b.company_id = a.company_id AND b.accounting_period_id = a.accounting_period_id
)
WHERE a.evidence_bundle_id IS NULL;

UPDATE companies_house_accounts_submissions s
SET s.evidence_bundle_id = (
  SELECT MAX(b.id) FROM filing_evidence_bundles b
  WHERE b.company_id = s.company_id AND b.accounting_period_id = s.accounting_period_id
)
WHERE s.evidence_bundle_id IS NULL;

INSERT IGNORE INTO filing_evidence_artifacts
  (artifact_id, transaction_hex, bundle_id, artifact_role, artifact_status,
   filename, storage_path, sha256, schema_identity, generator_name,
   generator_version, validation_status, identifier_embedded,
   legacy_non_embedded, metadata_json, completed_at)
SELECT CONCAT('EEL-AR-', UPPER(SUBSTRING(SHA2(CONCAT('legacy-accounts:', r.id, ':', b.id), 256), 1, 32))),
       UPPER(SUBSTRING(SHA2(CONCAT('legacy-accounts:', r.id, ':', b.id), 256), 1, 32)),
       b.id, 'accounts_ixbrl', 'historical', r.generated_filename, r.generated_path,
       r.output_sha256, r.taxonomy_profile, 'EEL Accounts', 'legacy',
       r.validation_status, 0, 1, JSON_OBJECT('ixbrl_generation_run_id', r.id), r.generated_at
FROM ixbrl_generation_runs r
INNER JOIN filing_evidence_bundles b
  ON b.company_id = r.company_id AND b.accounting_period_id = r.accounting_period_id
WHERE r.generated_path IS NOT NULL AND r.output_sha256 IS NOT NULL;

INSERT IGNORE INTO filing_evidence_artifacts
  (artifact_id, transaction_hex, bundle_id, ct_period_id, artifact_role,
   artifact_status, filename, storage_path, sha256, schema_identity,
   generator_name, generator_version, validator_name, validator_version,
   validation_status, identifier_embedded, legacy_non_embedded,
   metadata_json, completed_at)
SELECT CONCAT('EEL-AR-', UPPER(SUBSTRING(SHA2(CONCAT('legacy-computation:', r.id, ':', es.bundle_id), 256), 1, 32))),
       UPPER(SUBSTRING(SHA2(CONCAT('legacy-computation:', r.id, ':', es.bundle_id), 256), 1, 32)),
       es.bundle_id, r.ct_period_id, 'computation_ixbrl', 'historical',
       r.generated_filename, r.generated_path, r.output_sha256, r.taxonomy_profile,
       'EEL Accounts', 'legacy', r.external_validator, r.external_validator_version,
       r.external_validation_status, 0, 1, JSON_OBJECT('computation_run_id', r.id), r.ixbrl_generated_at
FROM corporation_tax_computation_runs r
INNER JOIN filing_evidence_ct_snapshots es ON es.computation_run_id = r.id
WHERE r.generated_path IS NOT NULL AND r.output_sha256 IS NOT NULL;

INSERT IGNORE INTO filing_evidence_artifacts
  (artifact_id, transaction_hex, bundle_id, ct_period_id, artifact_role,
   artifact_status, filename, storage_path, sha256, schema_identity,
   generator_name, generator_version, validation_status,
   identifier_embedded, legacy_non_embedded, metadata_json, completed_at)
SELECT CONCAT('EEL-AR-', UPPER(SUBSTRING(SHA2(CONCAT('legacy-ct600:', h.id), 256), 1, 32))),
       UPPER(SUBSTRING(SHA2(CONCAT('legacy-ct600:', h.id), 256), 1, 32)),
       h.evidence_bundle_id, h.ct_period_id, 'hmrc_ct600_body', 'historical',
       SUBSTRING_INDEX(h.ct600_xml_path, '/', -1), h.ct600_xml_path, h.body_sha256,
       h.schema_version, 'EEL Accounts', 'legacy', h.status,
       0, 1, JSON_OBJECT('submission_id', h.id), h.created_at
FROM hmrc_ct600_submissions h
WHERE h.evidence_bundle_id IS NOT NULL AND h.ct600_xml_path IS NOT NULL AND h.body_sha256 IS NOT NULL;

INSERT IGNORE INTO filing_evidence_artifacts
  (artifact_id, transaction_hex, bundle_id, artifact_role, artifact_status,
   filename, storage_path, sha256, schema_identity, generator_name,
   generator_version, validation_status, identifier_embedded,
   legacy_non_embedded, metadata_json, completed_at)
SELECT CONCAT('EEL-AR-', UPPER(SUBSTRING(SHA2(CONCAT('legacy-ch:', s.id), 256), 1, 32))),
       UPPER(SUBSTRING(SHA2(CONCAT('legacy-ch:', s.id), 256), 1, 32)),
       s.evidence_bundle_id, 'companies_house_revised_accounts_ixbrl', 'historical',
       SUBSTRING_INDEX(s.revised_artifact_path, '/', -1), s.revised_artifact_path,
       s.revised_artifact_sha256, 'FRC accounts taxonomy', 'EEL Accounts',
       'legacy', s.lifecycle, 0, 1, JSON_OBJECT('submission_id', s.id), s.prepared_at
FROM companies_house_accounts_submissions s
WHERE s.evidence_bundle_id IS NOT NULL AND s.revised_artifact_path IS NOT NULL;
