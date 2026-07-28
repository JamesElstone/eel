-- Immutable director-loan filing evidence captured with each new Year End bundle.

CREATE TABLE IF NOT EXISTS filing_evidence_loan_snapshots (
  id BIGINT NOT NULL AUTO_INCREMENT,
  bundle_id BIGINT NOT NULL,
  snapshot_version VARCHAR(64) NOT NULL,
  snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  snapshot_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_filing_evidence_loan_snapshot_bundle (bundle_id),
  KEY idx_filing_evidence_loan_snapshot_hash (snapshot_hash),
  CONSTRAINT fk_filing_evidence_loan_snapshot_bundle
    FOREIGN KEY (bundle_id) REFERENCES filing_evidence_bundles (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'filing_evidence_loans'
FROM role_card_permissions
WHERE card_key = 'filing_evidence_calculations';

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, 'director_loan_filing_evidence'
FROM role_card_permissions
WHERE card_key = 'director_loan_s455';
