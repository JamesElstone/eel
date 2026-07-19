CREATE TABLE IF NOT EXISTS ct_filing_canonical_sources (
  id BIGINT NOT NULL AUTO_INCREMENT,
  target_scope ENUM('both','ct600_rim','computation_ixbrl') NOT NULL DEFAULT 'both',
  canonical_key VARCHAR(180) NOT NULL,
  source_label VARCHAR(255) NOT NULL,
  value_type ENUM('numeric','text','date','boolean','integer') NOT NULL,
  source_section VARCHAR(100) NOT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ct_filing_canonical_source (target_scope, canonical_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ct_filing_mapping_events
  MODIFY event_type ENUM('created','cloned','mapping_changed','validated','activated','retired','validation_failed') NOT NULL;

INSERT IGNORE INTO ct_filing_canonical_sources
  (target_scope, canonical_key, source_label, value_type, source_section, is_required)
VALUES
  ('both','identity.company_name','Company name','text','identity',1),
  ('both','identity.company_number','Company number','text','identity',1),
  ('both','ct_period.start_date','CT period start','date','identity',1),
  ('both','ct_period.end_date','CT period end','date','identity',1),
  ('both','computation.summary.accounting_profit','Accounting profit or loss','numeric','detailed_profit_and_loss',1),
  ('both','computation.summary.disallowable_add_backs','Disallowable expense add-backs','numeric','accounts_adjustments',1),
  ('both','computation.summary.capital_add_backs','Capital expenditure add-backs','numeric','accounts_adjustments',1),
  ('both','computation.summary.depreciation_add_back','Depreciation add-back','numeric','accounts_adjustments',1),
  ('both','computation.summary.capital_allowances','Capital allowances','numeric','capital_allowances',1),
  ('both','computation.summary.taxable_before_losses','Taxable result before losses','numeric','losses',1),
  ('both','computation.summary.losses_brought_forward','Losses brought forward','numeric','losses',0),
  ('both','computation.summary.losses_used','Losses used','numeric','losses',0),
  ('both','computation.summary.taxable_profit','Taxable profit','numeric','tax_liability',1),
  ('both','computation.summary.ordinary_corporation_tax','Corporation tax on profits','numeric','tax_liability',1),
  ('both','computation.summary.s455_tax','Section 455 tax','numeric','tax_liability',0),
  ('both','computation.summary.estimated_corporation_tax','Corporation Tax liability','numeric','tax_liability',1);
