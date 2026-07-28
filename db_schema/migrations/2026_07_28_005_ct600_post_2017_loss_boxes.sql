-- Explicit CT600 box sources derived from the immutable return model.
-- These distinguish same-trade losses (box 160) from post-2017 losses
-- carried forward and claimed against total profits (box 285).
INSERT IGNORE INTO ct_filing_canonical_sources
  (target_scope, canonical_key, source_label, value_type, source_section, is_required)
VALUES
  ('ct600_rim', 'ct600.calculation.trading_profit_before_losses', 'CT600 trading profits before losses', 'numeric', 'losses', 0),
  ('ct600_rim', 'ct600.calculation.trading_losses_brought_forward_used', 'CT600 same-trade trading losses brought forward used', 'numeric', 'losses', 0),
  ('ct600_rim', 'ct600.calculation.net_trading_profits', 'CT600 net trading profits', 'numeric', 'losses', 0),
  ('ct600_rim', 'ct600.calculation.profits_before_other_deductions', 'CT600 profits before other deductions and reliefs', 'numeric', 'tax_liability', 0),
  ('ct600_rim', 'ct600.calculation.trading_losses_current_or_later_claimed', 'CT600 current or later-period trading losses claimed against total profits', 'numeric', 'losses', 0),
  ('ct600_rim', 'ct600.calculation.trading_losses_carried_forward_claimed', 'CT600 carried-forward trading losses claimed against total profits', 'numeric', 'losses', 0),
  ('ct600_rim', 'ct600.calculation.total_deductions_and_reliefs', 'CT600 total deductions and reliefs', 'numeric', 'tax_liability', 0),
  ('ct600_rim', 'ct600.calculation.profits_before_donations_group_relief', 'CT600 profits before qualifying donations and group relief', 'numeric', 'tax_liability', 0);
