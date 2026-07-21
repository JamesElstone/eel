INSERT IGNORE INTO ct_filing_canonical_sources
  (target_scope, canonical_key, source_label, value_type, source_section, is_required)
VALUES
  ('both','return_position.ct600a_a80','CT600A A80 / CT600 box 480','numeric','tax_liability',0),
  ('both','return_position.tax_payable','Total Corporation Tax payable','numeric','tax_liability',1);

UPDATE ct_filing_canonical_sources
SET source_label = 'Legacy CT600A box 480 mapping key'
WHERE canonical_key = 'computation.summary.s455_tax';

UPDATE ct_filing_canonical_sources
SET source_label = 'Legacy total Corporation Tax mapping key'
WHERE canonical_key = 'computation.summary.estimated_corporation_tax';
