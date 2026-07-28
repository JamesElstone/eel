-- A creditor's maturity evidence is not evidence of the terms of an earlier
-- company-to-participator advance.  Keep the legacy structured columns as the
-- participator-to-company (creditor) terms and store advance terms separately.
ALTER TABLE participator_loan_party_terms
  ADD COLUMN advance_terms_json LONGTEXT NULL AFTER settlement_intention;
