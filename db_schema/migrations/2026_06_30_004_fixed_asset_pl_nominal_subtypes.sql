INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'asset_disposal_gain', 'Asset Disposal Gain', 'income', 420, 1
WHERE NOT EXISTS (
  SELECT 1
  FROM nominal_account_subtypes
  WHERE code = 'asset_disposal_gain'
);

INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'depreciation_expense', 'Depreciation Expense', 'expense', 620, 1
WHERE NOT EXISTS (
  SELECT 1
  FROM nominal_account_subtypes
  WHERE code = 'depreciation_expense'
);

INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'asset_disposal_loss', 'Asset Disposal Loss', 'expense', 621, 1
WHERE NOT EXISTS (
  SELECT 1
  FROM nominal_account_subtypes
  WHERE code = 'asset_disposal_loss'
);

UPDATE nominal_accounts na
INNER JOIN nominal_account_subtypes nas
  ON nas.code = 'asset_disposal_gain'
 AND nas.parent_account_type = 'income'
SET na.account_subtype_id = nas.id
WHERE na.code = '4200'
  AND na.name = 'Profit on Disposal'
  AND na.account_type = 'income';

UPDATE nominal_accounts na
INNER JOIN nominal_account_subtypes nas
  ON nas.code = 'depreciation_expense'
 AND nas.parent_account_type = 'expense'
SET na.account_subtype_id = nas.id
WHERE na.code = '6200'
  AND na.name = 'Depreciation Expense'
  AND na.account_type = 'expense';

UPDATE nominal_accounts na
INNER JOIN nominal_account_subtypes nas
  ON nas.code = 'asset_disposal_loss'
 AND nas.parent_account_type = 'expense'
SET na.account_subtype_id = nas.id
WHERE na.code = '6210'
  AND na.name = 'Loss on Disposal'
  AND na.account_type = 'expense';
