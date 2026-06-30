ALTER TABLE categorisation_rules
  CHANGE COLUMN IF EXISTS match_type desc_match_type enum('contains','equals','starts_with','regex') NOT NULL DEFAULT 'contains';

ALTER TABLE categorisation_rules
  CHANGE COLUMN IF EXISTS match_value desc_match_value varchar(255) NOT NULL;

ALTER TABLE categorisation_rules
  MODIFY match_field enum('description','reference','name','type','card','any') NOT NULL DEFAULT 'description';

ALTER TABLE categorisation_rules
  ADD COLUMN IF NOT EXISTS ref_match_type enum('none','contains','equals','starts_with') NOT NULL DEFAULT 'none' AFTER desc_match_value;

ALTER TABLE categorisation_rules
  ADD COLUMN IF NOT EXISTS ref_match_value varchar(255) DEFAULT NULL AFTER ref_match_type;

UPDATE categorisation_rules
SET ref_match_type = 'none',
    ref_match_value = NULL
WHERE ref_match_type IS NULL
   OR ref_match_type = ''
   OR ref_match_type = 'none';
