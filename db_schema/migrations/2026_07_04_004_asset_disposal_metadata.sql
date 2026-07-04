ALTER TABLE asset_register
  ADD COLUMN IF NOT EXISTS disposal_event_type varchar(64) DEFAULT NULL AFTER disposal_proceeds,
  ADD COLUMN IF NOT EXISTS disposal_reason varchar(255) DEFAULT NULL AFTER disposal_event_type;
