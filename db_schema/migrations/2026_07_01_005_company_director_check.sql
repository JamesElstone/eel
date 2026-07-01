ALTER TABLE companies
  ADD COLUMN IF NOT EXISTS companies_house_active_director_count int(11) DEFAULT NULL AFTER companies_house_profile_json,
  ADD COLUMN IF NOT EXISTS companies_house_officers_last_checked_at datetime DEFAULT NULL AFTER companies_house_active_director_count,
  ADD COLUMN IF NOT EXISTS companies_house_officers_json longtext DEFAULT NULL AFTER companies_house_officers_last_checked_at;
