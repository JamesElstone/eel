-- Give every existing company a deterministic Sales nominal for dormant-status calculation.
-- The operation is idempotent and only fills the new setting when it is missing/blank.
INSERT INTO company_settings (company_id, setting, type, value)
SELECT c.id, 'default_sales_nominal_id', 'int', CAST(na.id AS CHAR)
FROM companies c
INNER JOIN nominal_accounts na
  ON na.is_active = 1
 AND na.account_type = 'income'
 AND (na.code = '4000' OR na.name = 'Sales')
 AND na.id = (
     SELECT candidate.id
     FROM nominal_accounts candidate
     WHERE candidate.is_active = 1
       AND candidate.account_type = 'income'
       AND (candidate.code = '4000' OR candidate.name = 'Sales')
     ORDER BY CASE WHEN candidate.code = '4000' THEN 0 ELSE 1 END, candidate.id
     LIMIT 1
 )
LEFT JOIN company_settings existing
  ON existing.company_id = c.id
 AND existing.setting = 'default_sales_nominal_id'
WHERE existing.id IS NULL;

UPDATE company_settings cs
INNER JOIN nominal_accounts na
  ON na.is_active = 1
 AND na.account_type = 'income'
 AND (na.code = '4000' OR na.name = 'Sales')
 AND na.id = (
     SELECT candidate.id
     FROM nominal_accounts candidate
     WHERE candidate.is_active = 1
       AND candidate.account_type = 'income'
       AND (candidate.code = '4000' OR candidate.name = 'Sales')
     ORDER BY CASE WHEN candidate.code = '4000' THEN 0 ELSE 1 END, candidate.id
     LIMIT 1
 )
SET cs.type = 'int',
    cs.value = CAST(na.id AS CHAR),
    cs.updated_at = CURRENT_TIMESTAMP
WHERE cs.setting = 'default_sales_nominal_id'
  AND (cs.value IS NULL OR cs.value = '');
