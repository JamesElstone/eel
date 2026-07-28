-- Immutable full-period evidence sections and append-only filing lifecycle snapshots.
CREATE TABLE IF NOT EXISTS filing_evidence_section_snapshots (
  id BIGINT NOT NULL AUTO_INCREMENT,
  bundle_id BIGINT NOT NULL,
  section_code VARCHAR(64) NOT NULL,
  section_version VARCHAR(64) NOT NULL,
  snapshot_kind ENUM('lock','lifecycle') NOT NULL DEFAULT 'lock',
  sequence_no INT NOT NULL DEFAULT 1,
  record_count INT NOT NULL DEFAULT 0,
  totals_json LONGTEXT NULL,
  snapshot_json LONGTEXT NOT NULL,
  snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_filing_evidence_section_version (bundle_id, section_code, snapshot_kind, sequence_no),
  KEY idx_filing_evidence_section_lookup (bundle_id, section_code, id),
  CONSTRAINT fk_filing_evidence_section_bundle FOREIGN KEY (bundle_id) REFERENCES filing_evidence_bundles (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'filing_evidence_coverage' FROM role_card_permissions WHERE card_key = 'filing_evidence_calculations';
INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'filing_evidence_section_detail' FROM role_card_permissions WHERE card_key = 'filing_evidence_calculations';
