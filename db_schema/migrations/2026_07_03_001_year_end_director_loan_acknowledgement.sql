ALTER TABLE year_end_reviews
  ADD COLUMN IF NOT EXISTS director_loan_closing_acknowledged_at datetime DEFAULT NULL AFTER review_notes,
  ADD COLUMN IF NOT EXISTS director_loan_closing_acknowledged_by varchar(100) DEFAULT NULL AFTER director_loan_closing_acknowledged_at;
