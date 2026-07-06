ALTER TABLE year_end_reviews
  ADD COLUMN IF NOT EXISTS director_loan_closing_approval_note text DEFAULT NULL AFTER director_loan_closing_acknowledged_by,
  ADD COLUMN IF NOT EXISTS tax_readiness_approval_note text DEFAULT NULL AFTER tax_readiness_acknowledged_by,
  ADD COLUMN IF NOT EXISTS expense_position_approval_note text DEFAULT NULL AFTER expense_position_acknowledged_by,
  ADD COLUMN IF NOT EXISTS retained_earnings_close_approval_note text DEFAULT NULL AFTER retained_earnings_close_acknowledged_by;
