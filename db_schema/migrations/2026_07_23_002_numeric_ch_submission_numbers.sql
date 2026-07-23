-- Keep ordering unambiguous: this integration uses only the six-digit numeric
-- Companies House submission-number series.

ALTER TABLE companies_house_accounts_submissions
  DROP CONSTRAINT IF EXISTS chk_ch_accounts_submission_number,
  ADD CONSTRAINT chk_ch_accounts_submission_number
    CHECK (submission_number IS NULL OR submission_number REGEXP '^[0-9]{6}$');
