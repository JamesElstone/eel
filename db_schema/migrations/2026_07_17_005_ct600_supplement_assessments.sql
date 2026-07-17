-- Immutable, lock-time CT600 supplementary-scope assessments.
-- Each assessment is bound to one current computation run and Year End lock.

CREATE TABLE IF NOT EXISTS ct600_supplement_assessments (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  accounting_period_id int(11) NOT NULL,
  ct_period_id int(11) NOT NULL,
  computation_run_id int(11) NOT NULL,
  year_end_locked_at datetime NOT NULL,
  assessment_hash char(64) NOT NULL,
  approved_by varchar(255) NOT NULL,
  approved_at datetime NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ct600_supplement_assessment_hash (assessment_hash),
  KEY idx_ct600_supplement_assessment_current (
    company_id, accounting_period_id, ct_period_id, computation_run_id, year_end_locked_at, approved_at
  ),
  CONSTRAINT fk_ct600_supplement_assessment_company
    FOREIGN KEY (company_id) REFERENCES companies (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct600_supplement_assessment_accounting_period
    FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct600_supplement_assessment_ct_period
    FOREIGN KEY (ct_period_id) REFERENCES corporation_tax_periods (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct600_supplement_assessment_computation_run
    FOREIGN KEY (computation_run_id) REFERENCES corporation_tax_computation_runs (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ct600_supplement_assessment_rows (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  assessment_id bigint(20) NOT NULL,
  row_order smallint(5) unsigned NOT NULL,
  contract_key varchar(64) NOT NULL,
  page varchar(16) DEFAULT NULL,
  label varchar(255) NOT NULL,
  status enum('required','not_required','unknown') NOT NULL,
  evidence_source varchar(100) NOT NULL,
  evidence_ref varchar(1000) NOT NULL DEFAULT '',
  detail text NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ct600_supplement_assessment_row_key (assessment_id, contract_key),
  UNIQUE KEY uq_ct600_supplement_assessment_row_order (assessment_id, row_order),
  CONSTRAINT fk_ct600_supplement_assessment_row_assessment
    FOREIGN KEY (assessment_id) REFERENCES ct600_supplement_assessments (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
