UPDATE expense_claim_lines l
INNER JOIN expense_claim_line_assets la ON la.expense_claim_line_id = l.id
INNER JOIN asset_register ar ON ar.id = la.generated_asset_id
SET l.nominal_account_id = ar.nominal_account_id
WHERE l.nominal_account_id IS NULL
  AND ar.nominal_account_id IS NOT NULL;

UPDATE expense_claim_lines l
INNER JOIN expense_claim_line_assets la ON la.expense_claim_line_id = l.id
INNER JOIN nominal_accounts na ON na.code = CASE
    WHEN la.category = 'tools_equipment' THEN '1300'
    WHEN la.category = 'plant_machinery' THEN '1310'
    WHEN la.category = 'car' THEN '1321'
    WHEN la.category = 'van' THEN '1322'
    ELSE '1320'
  END
SET l.nominal_account_id = na.id
WHERE l.nominal_account_id IS NULL;
