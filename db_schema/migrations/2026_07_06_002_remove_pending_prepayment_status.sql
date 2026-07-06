UPDATE prepayment_reviews
SET status = 'not_prepaid',
    service_start_date = NULL,
    service_end_date = NULL,
    reviewed_at = COALESCE(reviewed_at, CURRENT_TIMESTAMP),
    reviewed_by = COALESCE(reviewed_by, 'migration')
WHERE status = 'pending';

ALTER TABLE prepayment_reviews
  MODIFY status enum('not_prepaid','prepaid') NOT NULL DEFAULT 'not_prepaid';
