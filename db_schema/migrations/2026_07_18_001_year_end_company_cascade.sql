ALTER TABLE year_end_audit_log
  ADD CONSTRAINT fk_year_end_audit_log_company
    FOREIGN KEY (company_id) REFERENCES companies (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_year_end_audit_log_accounting_period
    FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE year_end_reviews
  ADD CONSTRAINT fk_year_end_reviews_company
    FOREIGN KEY (company_id) REFERENCES companies (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_year_end_reviews_accounting_period
    FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE year_end_review_acknowledgements
  ADD CONSTRAINT fk_year_end_review_ack_company
    FOREIGN KEY (company_id) REFERENCES companies (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_year_end_review_ack_accounting_period
    FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id)
    ON DELETE CASCADE ON UPDATE CASCADE;
