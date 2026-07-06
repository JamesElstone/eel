UPDATE accounting_period_month_confirmations
   SET confirmed_by = 'James Elstone using the web_app'
 WHERE company_id = 49
   AND accounting_period_id = 79
   AND month_start = '2022-09-01'
   AND confirmed_at = '2026-07-02 14:15:15'
   AND confirmed_by = 'web_app'
   AND revoked_at IS NULL;
