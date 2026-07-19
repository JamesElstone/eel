CREATE TABLE IF NOT EXISTS ixbrl_accounts_filing_approvals (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  disclosure_id BIGINT NOT NULL,
  disclosure_revision INT UNSIGNED NOT NULL,
  year_end_review_id INT NOT NULL,
  year_end_locked_at DATETIME NOT NULL,
  basis_version VARCHAR(64) NOT NULL,
  basis_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  basis_json LONGTEXT NOT NULL,
  approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_by VARCHAR(100) NOT NULL,
  approval_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ixbrl_filing_approval_period (company_id, accounting_period_id, id),
  KEY idx_ixbrl_filing_approval_basis (basis_hash),
  CONSTRAINT fk_ixbrl_filing_approval_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ixbrl_filing_approval_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ixbrl_filing_approval_disclosure FOREIGN KEY (disclosure_id) REFERENCES ixbrl_accounts_disclosures (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ixbrl_filing_approval_year_end FOREIGN KEY (year_end_review_id) REFERENCES year_end_reviews (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ct_period_filing_bases (
  id BIGINT NOT NULL AUTO_INCREMENT,
  filing_approval_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  ct_period_id INT NOT NULL,
  computation_run_id INT NOT NULL,
  calculation_basis_version VARCHAR(64) NOT NULL,
  calculation_basis_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  basis_version VARCHAR(100) NOT NULL,
  basis_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  basis_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ct_period_filing_basis_approval_period (filing_approval_id, ct_period_id),
  KEY idx_ct_period_filing_basis_context (company_id, accounting_period_id, ct_period_id, id),
  KEY idx_ct_period_filing_basis_hash (basis_hash),
  CONSTRAINT fk_ct_period_filing_basis_approval FOREIGN KEY (filing_approval_id) REFERENCES ixbrl_accounts_filing_approvals (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_period_filing_basis_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_period_filing_basis_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_period_filing_basis_ct_period FOREIGN KEY (ct_period_id) REFERENCES corporation_tax_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_period_filing_basis_run FOREIGN KEY (computation_run_id) REFERENCES corporation_tax_computation_runs (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ixbrl_generation_runs
  ADD COLUMN IF NOT EXISTS filing_approval_id BIGINT NULL AFTER basis_hash,
  ADD COLUMN IF NOT EXISTS filing_approval_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER filing_approval_id;

ALTER TABLE ixbrl_generation_runs
  ADD KEY idx_ixbrl_runs_filing_approval (filing_approval_id),
  ADD CONSTRAINT fk_ixbrl_runs_filing_approval FOREIGN KEY (filing_approval_id) REFERENCES ixbrl_accounts_filing_approvals (id) ON DELETE RESTRICT ON UPDATE CASCADE;
