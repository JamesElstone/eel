-- HMRC computational-taxonomy facts for post-1 April 2017 loss relief.
INSERT IGNORE INTO ct_filing_canonical_sources
  (target_scope, canonical_key, source_label, value_type, source_section, is_required)
VALUES
  ('computation_ixbrl', 'computation.summary.loss_restriction.post_2017_trading_losses.brought_forward', 'Post-1 April 2017 trading losses brought forward', 'numeric', 'losses', 1),
  ('computation_ixbrl', 'computation.summary.loss_restriction.post_2017_trading_losses.used', 'Post-1 April 2017 trading losses used against total profits', 'numeric', 'losses', 1),
  ('computation_ixbrl', 'computation.summary.loss_restriction.post_2017_trading_losses.carried_forward', 'Post-1 April 2017 trading losses carried forward', 'numeric', 'losses', 1),
  ('computation_ixbrl', 'computation.summary.loss_restriction.deduction_allowance.amount', 'Non-group deductions allowance for the period', 'numeric', 'losses', 1),
  ('computation_ixbrl', 'computation.summary.loss_restriction.calculated_loss_restriction', 'Calculated loss restriction', 'numeric', 'losses', 1);
