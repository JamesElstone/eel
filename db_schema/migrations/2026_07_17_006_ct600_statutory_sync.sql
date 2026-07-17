-- Keep HMRC's final LIVE business outcome separate from the retryable local
-- projection into Corporation Tax periods and the accounting-period filing
-- obligation. A projection failure must never erase a LIVE acceptance.

ALTER TABLE hmrc_ct600_submissions
  ADD COLUMN IF NOT EXISTS statutory_sync_state enum(
    'not_applicable','pending','applied','failed'
  ) NOT NULL DEFAULT 'not_applicable' AFTER business_outcome,
  ADD COLUMN IF NOT EXISTS statutory_sync_error text DEFAULT NULL AFTER statutory_sync_state,
  ADD COLUMN IF NOT EXISTS statutory_synced_at datetime DEFAULT NULL AFTER statutory_sync_error,
  ADD KEY IF NOT EXISTS idx_hmrc_ct600_statutory_sync (statutory_sync_state, environment, business_outcome);

-- Existing final LIVE acceptances are safe to project again: the projection
-- service is idempotent and preserves any already-linked filing evidence.
UPDATE hmrc_ct600_submissions
SET statutory_sync_state = CASE
      WHEN environment = 'LIVE'
       AND submission_type = 'original'
       AND business_outcome = 'live_accepted'
      THEN 'pending'
      ELSE 'not_applicable'
    END,
    statutory_sync_error = NULL,
    statutory_synced_at = NULL;
