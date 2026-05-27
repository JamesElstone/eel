UPDATE company_settings cs
JOIN nominal_accounts current_na ON current_na.id = CAST(cs.value AS UNSIGNED)
JOIN nominal_accounts trade_na ON trade_na.code = '2300'
SET cs.value = CAST(trade_na.id AS CHAR),
    cs.type = 'int',
    cs.updated_at = CURRENT_TIMESTAMP
WHERE cs.setting = 'default_trade_nominal_id'
  AND (
    current_na.code = '2110'
    OR current_na.name = 'Expense Claims Payable'
  );
