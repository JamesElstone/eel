ALTER TABLE expense_claims
  ADD INDEX IF NOT EXISTS idx_expense_claims_company_claimant_period (company_id, claimant_id, period_start, id);
