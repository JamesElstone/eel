CREATE TABLE IF NOT EXISTS hmrc_ct_filing_approval_period_bases (
  hmrc_ct_filing_approval_id BIGINT NOT NULL,
  ct_period_filing_basis_id BIGINT NOT NULL,
  ct_period_id INT NOT NULL,
  basis_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (hmrc_ct_filing_approval_id, ct_period_filing_basis_id),
  UNIQUE KEY uq_hmrc_ct_approval_period (hmrc_ct_filing_approval_id, ct_period_id),
  KEY idx_hmrc_ct_approval_basis (ct_period_filing_basis_id),
  CONSTRAINT fk_hmrc_ct_approval_basis_approval FOREIGN KEY (hmrc_ct_filing_approval_id)
    REFERENCES hmrc_ct_filing_approvals (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_hmrc_ct_approval_basis_basis FOREIGN KEY (ct_period_filing_basis_id)
    REFERENCES ct_period_filing_bases (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_hmrc_ct_approval_basis_ct_period FOREIGN KEY (ct_period_id)
    REFERENCES corporation_tax_periods (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill the binding written by the initial authority-split migration. The
-- legacy nullable column remains readable for compatibility, but native code
-- writes only append-only rows to the junction table from this migration on.
INSERT IGNORE INTO hmrc_ct_filing_approval_period_bases (
  hmrc_ct_filing_approval_id,
  ct_period_filing_basis_id,
  ct_period_id,
  basis_hash,
  created_at
)
SELECT
  basis.hmrc_ct_filing_approval_id,
  basis.id,
  basis.ct_period_id,
  basis.basis_hash,
  basis.created_at
FROM ct_period_filing_bases basis
WHERE basis.hmrc_ct_filing_approval_id IS NOT NULL;
