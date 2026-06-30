INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'capital_reserves', 'Capital and Reserves', 'equity', 65, 1
WHERE NOT EXISTS (
  SELECT 1
  FROM nominal_account_subtypes
  WHERE code = 'capital_reserves'
);

INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'dividends_payable', 'Dividends Payable', 'liability', 56, 1
WHERE NOT EXISTS (
  SELECT 1
  FROM nominal_account_subtypes
  WHERE code = 'dividends_payable'
);

INSERT INTO nominal_accounts (
  code,
  name,
  account_type,
  account_subtype_id,
  tax_treatment,
  is_active,
  sort_order
)
SELECT
  '2150',
  'Dividends Payable',
  'liability',
  nas.id,
  'other',
  1,
  56
FROM nominal_account_subtypes nas
WHERE nas.code = 'dividends_payable'
  AND nas.parent_account_type = 'liability'
  AND NOT EXISTS (
    SELECT 1
    FROM nominal_accounts
    WHERE code = '2150'
  );

INSERT INTO nominal_accounts (
  code,
  name,
  account_type,
  account_subtype_id,
  tax_treatment,
  is_active,
  sort_order
)
SELECT
  '3000',
  'Retained Earnings',
  'equity',
  nas.id,
  'other',
  1,
  66
FROM nominal_account_subtypes nas
WHERE nas.code = 'capital_reserves'
  AND nas.parent_account_type = 'equity'
  AND NOT EXISTS (
    SELECT 1
    FROM nominal_accounts
    WHERE code = '3000'
  );

INSERT INTO nominal_accounts (
  code,
  name,
  account_type,
  account_subtype_id,
  tax_treatment,
  is_active,
  sort_order
)
SELECT
  '3100',
  'Dividends Paid',
  'equity',
  nas.id,
  'other',
  1,
  71
FROM nominal_account_subtypes nas
WHERE nas.code = 'capital_reserves'
  AND nas.parent_account_type = 'equity'
  AND NOT EXISTS (
    SELECT 1
    FROM nominal_accounts
    WHERE code = '3100'
  );
