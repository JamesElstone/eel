INSERT INTO hmrc_ct_computation_packages
  (taxonomy_version, artifact_version, applicable_from, applicable_to, source_url, download_url, package_state)
VALUES
  (
    '2024',
    'V1.0.0',
    '2015-04-01',
    '2026-03-31',
    'https://www.gov.uk/government/publications/corporation-tax-technical-specifications-xbrl-and-ixbrl',
    'https://www.hmrc.gov.uk/softwaredevelopers/ct/CT2024-v1.0.0.zip',
    'not_downloaded'
  )
ON DUPLICATE KEY UPDATE
  applicable_from = VALUES(applicable_from),
  applicable_to = VALUES(applicable_to),
  source_url = VALUES(source_url),
  download_url = VALUES(download_url);
