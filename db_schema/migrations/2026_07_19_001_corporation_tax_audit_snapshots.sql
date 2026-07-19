CREATE TABLE IF NOT EXISTS corporation_tax_audit_snapshots (
  id BIGINT NOT NULL AUTO_INCREMENT,
  computation_run_id INT NOT NULL,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  ct_period_id INT NOT NULL,
  basis_version VARCHAR(50) NOT NULL,
  basis_hash CHAR(64) NOT NULL,
  snapshot_origin VARCHAR(32) NOT NULL DEFAULT 'year_end_lock',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ct_audit_snapshot_run (computation_run_id),
  KEY idx_ct_audit_snapshot_period (company_id, accounting_period_id, ct_period_id),
  CONSTRAINT fk_ct_audit_snapshot_run FOREIGN KEY (computation_run_id) REFERENCES corporation_tax_computation_runs (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_audit_snapshot_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_audit_snapshot_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_audit_snapshot_ct_period FOREIGN KEY (ct_period_id) REFERENCES corporation_tax_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_ct_audit_snapshot_origin CHECK (snapshot_origin IN ('year_end_lock','legacy_reconstruction'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS corporation_tax_audit_areas (
  id BIGINT NOT NULL AUTO_INCREMENT,
  snapshot_id BIGINT NOT NULL,
  area_code VARCHAR(64) NOT NULL,
  area_label VARCHAR(150) NOT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  expected_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  reconciliation_difference DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  reconciliation_status VARCHAR(16) NOT NULL DEFAULT 'reconciled',
  source_count INT NOT NULL DEFAULT 0,
  area_hash CHAR(64) NOT NULL,
  detail_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ct_audit_area_snapshot_code (snapshot_id, area_code),
  KEY idx_ct_audit_area_snapshot (snapshot_id, id),
  CONSTRAINT fk_ct_audit_area_snapshot FOREIGN KEY (snapshot_id) REFERENCES corporation_tax_audit_snapshots (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_ct_audit_area_reconciliation CHECK (reconciliation_status IN ('reconciled','discrepancy'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_card_permissions (role_id, card_key)
SELECT DISTINCT role_id, audit_card.card_key
FROM role_card_permissions existing_permission
INNER JOIN (
  SELECT 'tax_audit_areas' AS card_key
  UNION ALL SELECT 'tax_audit_detail'
) audit_card
WHERE existing_permission.card_key IN ('tax_corporation_tax_summary', 'tax_taxable_profit_bridge', 'year_end_tax_readiness');
