ALTER TABLE year_end_reviews
  ADD COLUMN IF NOT EXISTS retained_earnings_close_acknowledged_at datetime DEFAULT NULL AFTER expense_position_acknowledged_by,
  ADD COLUMN IF NOT EXISTS retained_earnings_close_acknowledged_by varchar(100) DEFAULT NULL AFTER retained_earnings_close_acknowledged_at,
  ADD COLUMN IF NOT EXISTS retained_earnings_close_opening_equity decimal(14,2) DEFAULT NULL AFTER retained_earnings_close_acknowledged_by,
  ADD COLUMN IF NOT EXISTS retained_earnings_close_current_profit_loss decimal(14,2) DEFAULT NULL AFTER retained_earnings_close_opening_equity,
  ADD COLUMN IF NOT EXISTS retained_earnings_close_closing_equity_before decimal(14,2) DEFAULT NULL AFTER retained_earnings_close_current_profit_loss,
  ADD COLUMN IF NOT EXISTS retained_earnings_close_amount decimal(14,2) DEFAULT NULL AFTER retained_earnings_close_closing_equity_before;
