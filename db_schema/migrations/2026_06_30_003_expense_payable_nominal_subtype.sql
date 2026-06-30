UPDATE nominal_accounts na
INNER JOIN nominal_account_subtypes nas
  ON nas.code = 'expense_payable'
 AND nas.parent_account_type = 'liability'
SET na.account_subtype_id = nas.id
WHERE na.code = '2110'
  AND na.name = 'Expense Claims Payable'
  AND na.account_type = 'liability'
  AND na.account_subtype_id IS NULL;
