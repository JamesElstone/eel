ALTER TABLE ixbrl_generation_runs
  ADD COLUMN IF NOT EXISTS export_type varchar(32) NOT NULL DEFAULT 'preview',
  ADD COLUMN IF NOT EXISTS taxonomy_profile varchar(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS validation_status varchar(32) NOT NULL DEFAULT 'not_validated',
  ADD COLUMN IF NOT EXISTS validation_errors_json longtext DEFAULT NULL;

UPDATE ixbrl_fact_mappings
SET taxonomy_concept = 'uk-gaap:CreditorsDueAfterMoreThanOneYear',
    label = 'Creditors after more than one year',
    source_key = 'creditors_after_more_than_one_year'
WHERE fact_key = 'creditors_after_one_year';

UPDATE ixbrl_fact_mappings
SET source_key = 'equity_capital_reserves'
WHERE fact_key = 'equity';
