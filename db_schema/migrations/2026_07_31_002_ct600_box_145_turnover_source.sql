-- CT600 box 145 is based on the reconciled whole-pound turnover for the
-- individual Corporation Tax period, not the accounting-period total.
INSERT IGNORE INTO ct_filing_canonical_sources
  (target_scope, canonical_key, source_label, value_type, source_section, is_required)
VALUES
  ('ct600_rim', 'ct_period_facts.ct600_box_145_turnover', 'CT600 box 145 trading turnover', 'numeric', 'identity', 1);
