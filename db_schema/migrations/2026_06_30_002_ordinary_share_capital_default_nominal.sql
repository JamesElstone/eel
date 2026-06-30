INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'capital_reserves', 'Capital and Reserves', 'equity', 65, 1
WHERE NOT EXISTS (
  SELECT 1
  FROM nominal_account_subtypes
  WHERE code = 'capital_reserves'
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
  '3010',
  'Ordinary Share Capital',
  'equity',
  nas.id,
  'other',
  1,
  65
FROM nominal_account_subtypes nas
WHERE nas.code = 'capital_reserves'
  AND NOT EXISTS (
    SELECT 1
    FROM nominal_accounts
    WHERE code = '3010'
  );
