ALTER TABLE expense_claims DROP FOREIGN KEY fk_expense_claims_tax_year;
ALTER TABLE journal_entry_metadata DROP FOREIGN KEY fk_journal_entry_metadata_tax_year;
ALTER TABLE journals DROP FOREIGN KEY fk_journals_tax_year;
ALTER TABLE hmrc_obligations DROP FOREIGN KEY fk_hmrc_obligations_tax_year;
ALTER TABLE hmrc_ct600_submissions DROP FOREIGN KEY fk_hmrc_ct600_tax_year;
ALTER TABLE ixbrl_generation_runs DROP FOREIGN KEY fk_ixbrl_runs_tax_year;
ALTER TABLE statement_import_rows DROP FOREIGN KEY fk_statement_import_rows_tax_year;
ALTER TABLE statement_uploads DROP FOREIGN KEY fk_statement_uploads_tax_year;
ALTER TABLE tax_loss_carryforwards DROP FOREIGN KEY fk_tax_loss_year;
ALTER TABLE tax_loss_movement_history DROP FOREIGN KEY fk_tax_loss_history_tax_year;
ALTER TABLE tax_year_adjustments DROP FOREIGN KEY fk_tax_adjustments_year;
ALTER TABLE transactions DROP FOREIGN KEY fk_transactions_tax_year;
ALTER TABLE asset_depreciation_entries DROP FOREIGN KEY fk_asset_depreciation_tax_year;

RENAME TABLE tax_years TO accounting_periods;
RENAME TABLE tax_year_adjustments TO accounting_period_adjustments;

ALTER TABLE expense_claims CHANGE tax_year_id accounting_period_id int(11) NOT NULL;
ALTER TABLE journal_entry_metadata CHANGE tax_year_id accounting_period_id int(11) NOT NULL;
ALTER TABLE journals CHANGE tax_year_id accounting_period_id int(11) NOT NULL;
ALTER TABLE hmrc_obligations CHANGE tax_year_id accounting_period_id int(11) NOT NULL;
ALTER TABLE hmrc_ct600_submissions CHANGE tax_year_id accounting_period_id int(11) NOT NULL;
ALTER TABLE ixbrl_generation_runs CHANGE tax_year_id accounting_period_id int(11) NOT NULL;
ALTER TABLE statement_import_rows CHANGE tax_year_id accounting_period_id int(11) DEFAULT NULL;
ALTER TABLE statement_uploads CHANGE tax_year_id accounting_period_id int(11) DEFAULT NULL;
ALTER TABLE tax_loss_carryforwards CHANGE origin_tax_year_id origin_accounting_period_id int(11) NOT NULL;
ALTER TABLE tax_loss_movement_history CHANGE tax_year_id accounting_period_id int(11) NOT NULL;
ALTER TABLE accounting_period_adjustments CHANGE tax_year_id accounting_period_id int(11) NOT NULL;
ALTER TABLE transactions CHANGE tax_year_id accounting_period_id int(11) NOT NULL;
ALTER TABLE asset_depreciation_entries CHANGE tax_year_id accounting_period_id int(11) NOT NULL;
ALTER TABLE year_end_audit_log CHANGE tax_year_id accounting_period_id int(11) NOT NULL;
ALTER TABLE year_end_check_results CHANGE tax_year_id accounting_period_id int(11) NOT NULL;
ALTER TABLE year_end_reviews CHANGE tax_year_id accounting_period_id int(11) NOT NULL;

ALTER TABLE hmrc_ct600_submissions ADD COLUMN ct_period_id int(11) NULL AFTER accounting_period_id;
ALTER TABLE tax_loss_carryforwards ADD COLUMN origin_ct_period_id int(11) NULL AFTER origin_accounting_period_id;
ALTER TABLE tax_loss_movement_history ADD COLUMN ct_period_id int(11) NULL AFTER accounting_period_id;
ALTER TABLE accounting_period_adjustments ADD COLUMN ct_period_id int(11) NULL AFTER accounting_period_id;

CREATE TABLE IF NOT EXISTS corporation_tax_periods (
  id int(11) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  accounting_period_id int(11) NOT NULL,
  sequence_no int(11) NOT NULL,
  period_start date NOT NULL,
  period_end date NOT NULL,
  status enum('pending','computed','ready','submitted','accepted','rejected','superseded') NOT NULL DEFAULT 'pending',
  latest_computation_run_id int(11) DEFAULT NULL,
  latest_submission_id bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ct_period_sequence (accounting_period_id, sequence_no),
  KEY idx_ct_period_company_period (company_id, accounting_period_id),
  KEY idx_ct_period_status (company_id, accounting_period_id, status),
  CONSTRAINT fk_ct_period_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_period_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS corporation_tax_computation_runs (
  id int(11) NOT NULL AUTO_INCREMENT,
  company_id int(11) NOT NULL,
  accounting_period_id int(11) NOT NULL,
  ct_period_id int(11) NOT NULL,
  period_start date NOT NULL,
  period_end date NOT NULL,
  status enum('draft','generated','failed') NOT NULL DEFAULT 'draft',
  computation_hash char(64) NOT NULL,
  summary_json longtext NOT NULL,
  generated_path varchar(1000) DEFAULT NULL,
  generated_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_ct_computation_period (ct_period_id, generated_at),
  KEY idx_ct_computation_company_period (company_id, accounting_period_id, generated_at),
  CONSTRAINT fk_ct_computation_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_computation_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_ct_computation_ct_period FOREIGN KEY (ct_period_id) REFERENCES corporation_tax_periods (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE expense_claims ADD CONSTRAINT fk_expense_claims_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON UPDATE CASCADE;
ALTER TABLE journal_entry_metadata ADD CONSTRAINT fk_journal_entry_metadata_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE journals ADD CONSTRAINT fk_journals_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE hmrc_obligations ADD CONSTRAINT fk_hmrc_obligations_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE hmrc_ct600_submissions ADD CONSTRAINT fk_hmrc_ct600_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE hmrc_ct600_submissions ADD CONSTRAINT fk_hmrc_ct600_ct_period FOREIGN KEY (ct_period_id) REFERENCES corporation_tax_periods (id) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE ixbrl_generation_runs ADD CONSTRAINT fk_ixbrl_runs_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE statement_import_rows ADD CONSTRAINT fk_statement_import_rows_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE statement_uploads ADD CONSTRAINT fk_statement_uploads_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE tax_loss_carryforwards ADD CONSTRAINT fk_tax_loss_accounting_period FOREIGN KEY (origin_accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE tax_loss_carryforwards ADD CONSTRAINT fk_tax_loss_origin_ct_period FOREIGN KEY (origin_ct_period_id) REFERENCES corporation_tax_periods (id) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE tax_loss_movement_history ADD CONSTRAINT fk_tax_loss_history_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE tax_loss_movement_history ADD CONSTRAINT fk_tax_loss_history_ct_period FOREIGN KEY (ct_period_id) REFERENCES corporation_tax_periods (id) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE accounting_period_adjustments ADD CONSTRAINT fk_accounting_period_adjustments_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE accounting_period_adjustments ADD CONSTRAINT fk_accounting_period_adjustments_ct_period FOREIGN KEY (ct_period_id) REFERENCES corporation_tax_periods (id) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE transactions ADD CONSTRAINT fk_transactions_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE asset_depreciation_entries ADD CONSTRAINT fk_asset_depreciation_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE CASCADE ON UPDATE CASCADE;
