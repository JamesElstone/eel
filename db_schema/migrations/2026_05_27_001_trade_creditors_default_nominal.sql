INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'trade_creditor', 'Trade Creditor', 'liability', 45, 1
WHERE NOT EXISTS (
  SELECT 1
  FROM nominal_account_subtypes
  WHERE code = 'trade_creditor'
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
  '2300',
  'Trade Creditors',
  'liability',
  nas.id,
  'allowable',
  1,
  70
FROM nominal_account_subtypes nas
WHERE nas.code = 'trade_creditor'
  AND NOT EXISTS (
    SELECT 1
    FROM nominal_accounts
    WHERE code = '2300'
  );
