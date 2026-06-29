CREATE TABLE IF NOT EXISTS sic_section (
  id int(11) NOT NULL AUTO_INCREMENT,
  section_letter char(1) NOT NULL,
  description varchar(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sic_section_letter (section_letter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sic_codes (
  id int(11) NOT NULL AUTO_INCREMENT,
  section_id int(11) NOT NULL,
  sic_code varchar(10) NOT NULL,
  description varchar(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sic_code (sic_code),
  KEY idx_sic_codes_section_id (section_id),
  CONSTRAINT fk_sic_codes_section FOREIGN KEY (section_id) REFERENCES sic_section (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE statement_import_mappings
  ADD COLUMN IF NOT EXISTS mapping_origin varchar(20) NOT NULL DEFAULT 'manual' AFTER source_type;

ALTER TABLE statement_import_mappings
  ADD COLUMN IF NOT EXISTS source_mapping_upload_id int(11) DEFAULT NULL AFTER mapping_origin;

ALTER TABLE statement_import_mappings
  ADD COLUMN IF NOT EXISTS confirmed_at datetime DEFAULT NULL AFTER mapping_json;

ALTER TABLE statement_import_mappings
  ADD INDEX IF NOT EXISTS idx_statement_import_mappings_origin (mapping_origin);

ALTER TABLE statement_import_mappings
  ADD INDEX IF NOT EXISTS idx_statement_import_mappings_source_upload (source_mapping_upload_id);

ALTER TABLE statement_import_mappings
  ADD FOREIGN KEY IF NOT EXISTS fk_statement_import_mappings_source_upload (source_mapping_upload_id) REFERENCES statement_uploads (id)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE statement_uploads
  MODIFY source_type varchar(50) NOT NULL DEFAULT 'bank_account';

UPDATE statement_uploads
SET source_type = 'bank_account'
WHERE source_type IN ('Account_money', 'anna_money');

ALTER TABLE statement_import_mappings
  MODIFY source_type varchar(50) NOT NULL DEFAULT 'bank_account';

UPDATE statement_import_mappings
SET source_type = 'bank_account'
WHERE source_type IN ('Account_money', 'anna_money');

ALTER TABLE statement_uploads
  MODIFY workflow_status enum('uploaded','mapped','staged','needs_tax_year','needs_accounting_period','committed','completed') NOT NULL DEFAULT 'uploaded';

UPDATE statement_uploads
SET workflow_status = 'needs_accounting_period'
WHERE workflow_status = 'needs_tax_year';

ALTER TABLE statement_uploads
  MODIFY workflow_status enum('uploaded','mapped','staged','needs_accounting_period','committed','completed') NOT NULL DEFAULT 'uploaded';
