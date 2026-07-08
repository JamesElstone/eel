ALTER TABLE hmrc_obligations
  ADD COLUMN IF NOT EXISTS notice_date date NULL AFTER period_end;

UPDATE hmrc_obligations
SET notice_date = due_date
WHERE notice_date IS NULL
  AND obligation_type IN ('hmrc_penalty', 'hmrc_interest');

INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'hmrc_payable', 'HMRC Penalties & Interest Payable', 'liability', 61, 1
WHERE NOT EXISTS (
  SELECT 1 FROM nominal_account_subtypes WHERE code = 'hmrc_payable'
);

INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
SELECT '2210', 'HMRC Penalties & Interest Payable', 'liability', nas.id, 'other', 1, 61
FROM nominal_account_subtypes nas
WHERE nas.code = 'hmrc_payable'
  AND NOT EXISTS (
    SELECT 1 FROM nominal_accounts WHERE code = '2210'
  );

INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
SELECT '6230', 'HMRC Penalties', 'expense', nas.id, 'disallowable', 1, 623
FROM nominal_account_subtypes nas
WHERE nas.code = 'overhead'
  AND NOT EXISTS (
    SELECT 1 FROM nominal_accounts WHERE code = '6230'
  );

INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
SELECT '6231', 'HMRC Interest', 'expense', nas.id, 'other', 1, 624
FROM nominal_account_subtypes nas
WHERE nas.code = 'overhead'
  AND NOT EXISTS (
    SELECT 1 FROM nominal_accounts WHERE code = '6231'
  );
