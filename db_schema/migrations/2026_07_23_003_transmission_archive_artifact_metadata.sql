-- Keep the exact outbound submission envelope and its immediate response
-- addressable from the transmission archive record as well as its manifest.

ALTER TABLE transmission_archives
  ADD COLUMN IF NOT EXISTS request_path varchar(1000) DEFAULT NULL AFTER archive_path,
  ADD COLUMN IF NOT EXISTS request_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER request_path,
  ADD COLUMN IF NOT EXISTS response_path varchar(1000) DEFAULT NULL AFTER request_sha256,
  ADD COLUMN IF NOT EXISTS response_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER response_path;
