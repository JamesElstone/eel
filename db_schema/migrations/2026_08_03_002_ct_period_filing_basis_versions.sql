ALTER TABLE ct_period_filing_bases
  DROP INDEX IF EXISTS uq_ct_period_filing_basis_approval_period,
  ADD UNIQUE KEY IF NOT EXISTS uq_ct_period_filing_basis_approval_period_version (
    filing_approval_id, ct_period_id, basis_hash
  );
