ALTER TABLE categorisation_rules
  MODIFY desc_match_type enum('contains','equals','starts_with','regex') NOT NULL DEFAULT 'contains';
