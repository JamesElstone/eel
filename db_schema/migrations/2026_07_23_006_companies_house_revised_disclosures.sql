ALTER TABLE companies_house_accounts_eligibility
  ADD COLUMN IF NOT EXISTS variance_explanation LONGTEXT NULL AFTER evidence_received_at;

ALTER TABLE ixbrl_accounts_disclosures
  ADD COLUMN IF NOT EXISTS companies_house_revised_accounts_public_register_confirmed TINYINT(1) NULL
    AFTER members_have_not_required_audit;
