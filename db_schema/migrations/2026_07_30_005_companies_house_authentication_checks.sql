-- Make CompanyData authentication checks independent of prepared accounts
-- submissions while retaining legacy submission-bound history.
ALTER TABLE companies_house_company_auth_preflights
  DROP FOREIGN KEY IF EXISTS fk_ch_company_auth_preflight_submission,
  MODIFY COLUMN submission_id BIGINT NULL,
  ADD INDEX IF NOT EXISTS idx_ch_company_auth_preflight_company (
    company_id,
    accounting_period_id,
    environment,
    outcome,
    created_at
  );
