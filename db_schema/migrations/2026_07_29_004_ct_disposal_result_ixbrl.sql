-- Separate fixed-asset disposal results from generic capital expenditure in
-- CT-computation presentations. The legacy total remains readable from an
-- immutable basis, but is no longer a reviewed iXBRL source.
UPDATE ct_filing_canonical_sources
SET is_required = 0,
    source_label = 'Legacy aggregate capital add-backs (historic bases only)'
WHERE canonical_key = 'computation.summary.capital_add_backs';

INSERT IGNORE INTO ct_filing_canonical_sources
  (target_scope, canonical_key, source_label, value_type, source_section, is_required)
VALUES
  ('computation_ixbrl', 'computation.summary.capital_expenditure_add_backs', 'Capital expenditure add-backs', 'numeric', 'accounts_adjustments', 1),
  ('computation_ixbrl', 'computation.summary.disposal_profit_or_loss_adjustment', 'Loss or profit on disposal of fixed assets', 'numeric', 'accounts_adjustments', 0);
