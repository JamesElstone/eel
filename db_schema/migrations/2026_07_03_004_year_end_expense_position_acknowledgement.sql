ALTER TABLE year_end_reviews
  ADD COLUMN IF NOT EXISTS expense_position_acknowledged_at datetime DEFAULT NULL AFTER tax_readiness_acknowledged_by,
  ADD COLUMN IF NOT EXISTS expense_position_acknowledged_by varchar(100) DEFAULT NULL AFTER expense_position_acknowledged_at;
