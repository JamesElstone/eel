ALTER TABLE year_end_review_acknowledgements
  ADD COLUMN IF NOT EXISTS basis_version varchar(50) DEFAULT NULL AFTER note,
  ADD COLUMN IF NOT EXISTS basis_hash char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER basis_version,
  ADD COLUMN IF NOT EXISTS basis_json longtext DEFAULT NULL AFTER basis_hash;

INSERT INTO year_end_review_acknowledgements (
  company_id, accounting_period_id, check_code,
  acknowledged_at, acknowledged_by, note,
  basis_version, basis_hash, basis_json,
  created_at, updated_at
)
SELECT company_id, accounting_period_id, 'director_loan_closing_balance',
       director_loan_closing_acknowledged_at, director_loan_closing_acknowledged_by,
       director_loan_closing_approval_note,
       NULL, NULL, NULL,
       director_loan_closing_acknowledged_at, updated_at
FROM year_end_reviews
WHERE director_loan_closing_acknowledged_at IS NOT NULL
ON DUPLICATE KEY UPDATE check_code = VALUES(check_code);

INSERT INTO year_end_review_acknowledgements (
  company_id, accounting_period_id, check_code,
  acknowledged_at, acknowledged_by, note,
  basis_version, basis_hash, basis_json,
  created_at, updated_at
)
SELECT company_id, accounting_period_id, 'tax_readiness_acknowledgement',
       tax_readiness_acknowledged_at, tax_readiness_acknowledged_by,
       tax_readiness_approval_note,
       NULL, NULL, NULL,
       tax_readiness_acknowledged_at, updated_at
FROM year_end_reviews
WHERE tax_readiness_acknowledged_at IS NOT NULL
ON DUPLICATE KEY UPDATE check_code = VALUES(check_code);

INSERT INTO year_end_review_acknowledgements (
  company_id, accounting_period_id, check_code,
  acknowledged_at, acknowledged_by, note,
  basis_version, basis_hash, basis_json,
  created_at, updated_at
)
SELECT company_id, accounting_period_id, 'expense_position_acknowledgement',
       expense_position_acknowledged_at, expense_position_acknowledged_by,
       expense_position_approval_note,
       NULL, NULL, NULL,
       expense_position_acknowledged_at, updated_at
FROM year_end_reviews
WHERE expense_position_acknowledged_at IS NOT NULL
ON DUPLICATE KEY UPDATE check_code = VALUES(check_code);

INSERT INTO year_end_review_acknowledgements (
  company_id, accounting_period_id, check_code,
  acknowledged_at, acknowledged_by, note,
  basis_version, basis_hash, basis_json,
  created_at, updated_at
)
SELECT company_id, accounting_period_id, 'retained_earnings_close_confirmation',
       retained_earnings_close_acknowledged_at, retained_earnings_close_acknowledged_by,
       retained_earnings_close_approval_note,
       NULL, NULL,
       JSON_OBJECT(
         'opening_equity', retained_earnings_close_opening_equity,
         'current_profit_loss', retained_earnings_close_current_profit_loss,
         'closing_equity_before_close', retained_earnings_close_closing_equity_before,
         'retained_earnings_movement', retained_earnings_close_amount
       ),
       retained_earnings_close_acknowledged_at, updated_at
FROM year_end_reviews
WHERE retained_earnings_close_acknowledged_at IS NOT NULL
ON DUPLICATE KEY UPDATE check_code = VALUES(check_code);

ALTER TABLE year_end_reviews
  DROP COLUMN IF EXISTS status,
  DROP COLUMN IF EXISTS director_loan_closing_acknowledged_at,
  DROP COLUMN IF EXISTS director_loan_closing_acknowledged_by,
  DROP COLUMN IF EXISTS director_loan_closing_approval_note,
  DROP COLUMN IF EXISTS tax_readiness_acknowledged_at,
  DROP COLUMN IF EXISTS tax_readiness_acknowledged_by,
  DROP COLUMN IF EXISTS tax_readiness_approval_note,
  DROP COLUMN IF EXISTS expense_position_acknowledged_at,
  DROP COLUMN IF EXISTS expense_position_acknowledged_by,
  DROP COLUMN IF EXISTS expense_position_approval_note,
  DROP COLUMN IF EXISTS retained_earnings_close_acknowledged_at,
  DROP COLUMN IF EXISTS retained_earnings_close_acknowledged_by,
  DROP COLUMN IF EXISTS retained_earnings_close_approval_note,
  DROP COLUMN IF EXISTS retained_earnings_close_opening_equity,
  DROP COLUMN IF EXISTS retained_earnings_close_current_profit_loss,
  DROP COLUMN IF EXISTS retained_earnings_close_closing_equity_before,
  DROP COLUMN IF EXISTS retained_earnings_close_amount,
  DROP COLUMN IF EXISTS last_recalculated_at;

DROP TABLE IF EXISTS year_end_check_results;
