ALTER TABLE companies_house_schema_files
  ADD COLUMN IF NOT EXISTS validation_profile varchar(64) DEFAULT NULL AFTER sha256,
  ADD COLUMN IF NOT EXISTS validation_relative_path varchar(500) DEFAULT NULL
    AFTER validation_profile,
  ADD COLUMN IF NOT EXISTS validation_sha256
    char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL
    AFTER validation_relative_path,
  ADD COLUMN IF NOT EXISTS validation_verified_at datetime DEFAULT NULL
    AFTER validation_sha256,
  ADD KEY IF NOT EXISTS idx_ch_schema_validation_profile
    (validation_profile, validation_verified_at);
