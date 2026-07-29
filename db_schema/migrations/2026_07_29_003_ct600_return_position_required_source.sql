-- New CT600 RIM profiles use return_position.tax_payable for boxes 510 and
-- 525. The legacy computation-summary key remains readable for historic
-- profiles and immutable artifacts, but cannot be required for a reviewed
-- profile that intentionally uses the canonical return-position source.
UPDATE ct_filing_canonical_sources
SET is_required = 0,
    source_label = 'Legacy total Corporation Tax mapping key (historic profiles only)'
WHERE canonical_key = 'computation.summary.estimated_corporation_tax';
