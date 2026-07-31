-- Reviewed CT600 profiles source box 145 from the reconciled whole-pound
-- turnover for each CT period. Keep the accounting-period value available
-- for historic profiles, but do not require new profiles to map it.
UPDATE ct_filing_canonical_sources
SET is_required = 0,
    source_label = 'Legacy accounting-period turnover (historic profiles only)'
WHERE target_scope = 'ct600_rim'
  AND canonical_key = 'accounts_facts.turnover';
