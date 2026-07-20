-- Freeze the complete filing source identity and link LIVE attempts to the
-- exact successful HMRC Test in Live conversation that authorised them.

ALTER TABLE hmrc_ct600_submissions
  ADD COLUMN IF NOT EXISTS source_manifest_json longtext DEFAULT NULL AFTER manifest_path,
  ADD COLUMN IF NOT EXISTS source_manifest_sha256 char(64) DEFAULT NULL AFTER source_manifest_json,
  ADD COLUMN IF NOT EXISTS test_submission_id bigint(20) DEFAULT NULL AFTER source_manifest_sha256,
  ADD KEY IF NOT EXISTS idx_hmrc_ct600_source_manifest (
    ct_period_id, environment, source_manifest_sha256, body_sha256
  ),
  ADD KEY IF NOT EXISTS idx_hmrc_ct600_test_submission (test_submission_id);

ALTER TABLE hmrc_ct600_submissions
  ADD CONSTRAINT fk_hmrc_ct600_test_submission
  FOREIGN KEY IF NOT EXISTS (test_submission_id)
  REFERENCES hmrc_ct600_submissions (id)
  ON DELETE SET NULL ON UPDATE CASCADE;
