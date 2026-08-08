INSERT INTO ixbrl_fact_mappings (
  fact_key, taxonomy_concept, namespace_uri, local_name, label, value_type,
  calculation_type, source_key, sign_multiplier, period_type, unit_ref,
  decimals_value, context_profile, dimensions_json, comparative_enabled,
  is_required, sort_order, is_active
) VALUES
('profit_loss_not_delivered_statement','direp:StatementThatDirectorsHaveElectedNotToDeliverProfitLossAccountUnderSection4445ACompaniesAct2006','http://xbrl.frc.org.uk/reports/2026-01-01/direp','StatementThatDirectorsHaveElectedNotToDeliverProfitLossAccountUnderSection4445ACompaniesAct2006','Section 444(5A) profit and loss non-delivery election','text','disclosure_statement','profit_loss_not_delivered_section_444',1,'duration',NULL,NULL,'duration',NULL,0,0,351,1),
('directors_report_small_companies_statement','direp:StatementThatDirectorsReportHasBeenPreparedInAccordanceWithProvisionsSmallCompaniesRegime','http://xbrl.frc.org.uk/reports/2026-01-01/direp','StatementThatDirectorsReportHasBeenPreparedInAccordanceWithProvisionsSmallCompaniesRegime','Directors Report small companies regime statement','text','inverse_disclosure_statement','directors_report_exempt_section_415a',1,'duration',NULL,NULL,'duration',NULL,0,0,352,1),
('directors_report_signing_date','direp:DateSigningDirectorsReport','http://xbrl.frc.org.uk/reports/2026-01-01/direp','DateSigningDirectorsReport','Date signing Directors Report','date','directors_report_signing_date','accounts_approval_date',1,'instant',NULL,NULL,'instant_end',NULL,0,0,353,1),
('director_signing_directors_report','direp:DirectorSigningDirectorsReport','http://xbrl.frc.org.uk/reports/2026-01-01/direp','DirectorSigningDirectorsReport','Director signing Directors Report','text','directors_report_marker','approving_director_name',1,'duration',NULL,NULL,'duration_director_1','{"bus:EntityOfficersDimension":"bus:Director1"}',0,0,354,1)
ON DUPLICATE KEY UPDATE
  taxonomy_concept = VALUES(taxonomy_concept),
  namespace_uri = VALUES(namespace_uri),
  local_name = VALUES(local_name),
  label = VALUES(label),
  value_type = VALUES(value_type),
  calculation_type = VALUES(calculation_type),
  source_key = VALUES(source_key),
  sign_multiplier = VALUES(sign_multiplier),
  period_type = VALUES(period_type),
  unit_ref = VALUES(unit_ref),
  decimals_value = VALUES(decimals_value),
  context_profile = VALUES(context_profile),
  dimensions_json = VALUES(dimensions_json),
  comparative_enabled = VALUES(comparative_enabled),
  is_required = VALUES(is_required),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active);
