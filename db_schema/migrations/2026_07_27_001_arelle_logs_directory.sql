-- Preserve historical Arelle log references after file_logs/ is renamed to logs/.
UPDATE ixbrl_generation_runs
SET external_validation_log_path = REPLACE(external_validation_log_path, 'file_logs', 'logs')
WHERE external_validation_log_path LIKE '%file_logs%';

UPDATE corporation_tax_computation_runs
SET external_validation_log_path = REPLACE(external_validation_log_path, 'file_logs', 'logs')
WHERE external_validation_log_path LIKE '%file_logs%';

UPDATE filing_evidence_artifacts
SET metadata_json = REPLACE(metadata_json, 'file_logs', 'logs')
WHERE metadata_json LIKE '%file_logs%';
