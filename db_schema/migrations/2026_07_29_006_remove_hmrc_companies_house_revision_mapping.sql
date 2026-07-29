DELETE FROM ixbrl_fact_mappings
WHERE fact_key = 'companies_house_revision_explanation';

ALTER TABLE ixbrl_fact_mappings
  MODIFY COLUMN calculation_type enum(
    'nominal_subtype_sum','nominal_account_sum','manual','derived',
    'company_field','period_field','disclosure_field','disclosure_statement',
    'absence_statement','application_value','fixed_marker',
    'director_loan_statement','director_loan_numeric'
  ) NOT NULL;
