-- Mandatory HMRC computation identity sources required by the CT taxonomy.
INSERT IGNORE INTO ct_filing_canonical_sources
  (target_scope, canonical_key, source_label, value_type, source_section, is_required)
VALUES
  ('computation_ixbrl', 'accounting_period.start_date', 'Statutory accounting period start', 'date', 'identity', 1),
  ('computation_ixbrl', 'accounting_period.end_date', 'Statutory accounting period end', 'date', 'identity', 1),
  ('computation_ixbrl', 'supported_return_profile.company_is_partner_in_firm', 'Company is a partner in a firm', 'boolean', 'identity', 1);
