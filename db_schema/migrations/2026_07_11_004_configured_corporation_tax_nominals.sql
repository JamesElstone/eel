-- Give Corporation Tax expense its own semantic subtype and persist explicit
-- per-company nominal mappings. Runtime services resolve only these IDs.
INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
SELECT 'corp_tax_expense', 'Corporation Tax Expense', 'expense', 85, 1
WHERE NOT EXISTS (
    SELECT 1 FROM nominal_account_subtypes WHERE code = 'corp_tax_expense'
);

UPDATE nominal_accounts na
INNER JOIN nominal_account_subtypes nas ON nas.code = 'corp_tax_expense'
SET na.account_subtype_id = nas.id
WHERE na.code = '8500'
  AND na.account_type = 'expense'
  AND (na.account_subtype_id IS NULL OR na.account_subtype_id = 0);

INSERT INTO company_settings (company_id, setting, type, value, created_at, updated_at)
SELECT c.id, 'corporation_tax_expense_nominal_id', 'int', CAST(na.id AS CHAR), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM companies c
INNER JOIN (
    SELECT MIN(na.id) AS id
    FROM nominal_accounts na
    INNER JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
    WHERE na.account_type = 'expense' AND na.is_active = 1 AND nas.code = 'corp_tax_expense'
) na ON na.id IS NOT NULL
WHERE NOT EXISTS (
    SELECT 1
    FROM company_settings cs
    WHERE cs.company_id = c.id
      AND cs.setting = 'corporation_tax_expense_nominal_id'
);

INSERT INTO company_settings (company_id, setting, type, value, created_at, updated_at)
SELECT c.id, 'corporation_tax_liability_nominal_id', 'int', CAST(na.id AS CHAR), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM companies c
INNER JOIN (
    SELECT MIN(na.id) AS id
    FROM nominal_accounts na
    INNER JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
    WHERE na.account_type = 'liability' AND na.is_active = 1 AND nas.code = 'corp_tax'
) na ON na.id IS NOT NULL
WHERE NOT EXISTS (
    SELECT 1
    FROM company_settings cs
    WHERE cs.company_id = c.id
      AND cs.setting = 'corporation_tax_liability_nominal_id'
);
