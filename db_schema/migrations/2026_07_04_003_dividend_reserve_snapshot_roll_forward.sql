ALTER TABLE dividend_reserve_review_snapshots
  ADD COLUMN IF NOT EXISTS as_at_date date DEFAULT NULL AFTER accounting_period_id,
  ADD COLUMN IF NOT EXISTS brought_forward_distributable_reserves decimal(12,2) NOT NULL DEFAULT 0.00 AFTER source_hash,
  ADD COLUMN IF NOT EXISTS dividends_declared decimal(12,2) NOT NULL DEFAULT 0.00 AFTER distributable_current_profit,
  ADD COLUMN IF NOT EXISTS closing_distributable_reserves decimal(12,2) NOT NULL DEFAULT 0.00 AFTER dividends_declared,
  ADD INDEX IF NOT EXISTS idx_dividend_reserve_snapshot_as_at (company_id, accounting_period_id, as_at_date);
