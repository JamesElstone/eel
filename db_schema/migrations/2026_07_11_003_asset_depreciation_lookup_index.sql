ALTER TABLE asset_depreciation_entries
  ADD INDEX IF NOT EXISTS idx_asset_depreciation_asset_period_end (asset_id, period_end);
