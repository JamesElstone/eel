INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'director_loan_long_term_liability', 'Director Loan Long-Term Liability', 'liability', 57, 1
WHERE NOT EXISTS (
  SELECT 1
  FROM nominal_account_subtypes
  WHERE code = 'director_loan_long_term_liability'
);
