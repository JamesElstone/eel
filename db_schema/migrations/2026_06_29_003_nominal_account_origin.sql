ALTER TABLE nominal_accounts
  ADD COLUMN origin_type enum('manual','company_account_auto') NOT NULL DEFAULT 'manual' AFTER created_at,
  ADD COLUMN origin_company_id int(11) DEFAULT NULL AFTER origin_type,
  ADD COLUMN origin_company_account_id int(11) DEFAULT NULL AFTER origin_company_id,
  ADD KEY idx_nominal_origin (origin_type, origin_company_id, origin_company_account_id);

UPDATE nominal_accounts na
INNER JOIN (
  SELECT ca.nominal_account_id,
         MIN(ca.id) AS company_account_id,
         MIN(ca.company_id) AS company_id
  FROM company_accounts ca
  WHERE ca.nominal_account_id IS NOT NULL
  GROUP BY ca.nominal_account_id
  HAVING COUNT(*) = 1
) unique_link ON unique_link.nominal_account_id = na.id
INNER JOIN company_accounts ca ON ca.id = unique_link.company_account_id
LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
LEFT JOIN company_settings cs
  ON cs.setting IN (
    'default_bank_nominal_id',
    'default_trade_nominal_id',
    'default_expense_nominal_id',
    'director_loan_nominal_id',
    'vat_nominal_id',
    'uncategorised_nominal_id'
  )
 AND TRIM(COALESCE(cs.value, '')) = CAST(na.id AS CHAR)
SET na.origin_type = 'company_account_auto',
    na.origin_company_id = unique_link.company_id,
    na.origin_company_account_id = unique_link.company_account_id
WHERE na.origin_type = 'manual'
  AND cs.id IS NULL
  AND (
    (ca.account_type = 'bank' AND na.account_type = 'asset' AND COALESCE(nas.code, '') = 'bank')
    OR (ca.account_type = 'trade' AND na.account_type = 'liability' AND COALESCE(nas.code, '') = 'trade_creditor')
  )
  AND na.name = LEFT(
    CASE
      WHEN ca.account_type = 'trade'
       AND LOWER(COALESCE(NULLIF(TRIM(ca.account_name), ''), 'Company Account')) NOT LIKE '%creditor%'
       AND LOWER(COALESCE(NULLIF(TRIM(ca.account_name), ''), 'Company Account')) NOT LIKE '%payable%'
        THEN CONCAT(COALESCE(NULLIF(TRIM(ca.account_name), ''), 'Company Account'), ' Trade Creditor')
      ELSE COALESCE(NULLIF(TRIM(ca.account_name), ''), 'Company Account')
    END,
    255
  );
