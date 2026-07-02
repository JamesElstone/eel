ALTER TABLE asset_register
  ADD COLUMN IF NOT EXISTS manual_evidence_path varchar(512) DEFAULT NULL AFTER manual_offset_nominal_id,
  ADD COLUMN IF NOT EXISTS manual_evidence_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER manual_evidence_path,
  ADD COLUMN IF NOT EXISTS manual_evidence_original_filename varchar(255) DEFAULT NULL AFTER manual_evidence_sha256,
  ADD COLUMN IF NOT EXISTS manual_evidence_content_type varchar(128) DEFAULT NULL AFTER manual_evidence_original_filename,
  ADD COLUMN IF NOT EXISTS manual_evidence_size_bytes int(11) DEFAULT NULL AFTER manual_evidence_content_type,
  ADD COLUMN IF NOT EXISTS manual_legal_warning_version varchar(128) DEFAULT NULL AFTER manual_evidence_size_bytes,
  ADD COLUMN IF NOT EXISTS manual_legal_acknowledged_at datetime DEFAULT NULL AFTER manual_legal_warning_version,
  ADD INDEX IF NOT EXISTS idx_asset_register_manual_evidence_sha (company_id, manual_evidence_sha256);
