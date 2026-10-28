ALTER TABLE hmrc_obligation_evidence_links
  DROP FOREIGN KEY IF EXISTS fk_hmrc_evidence_transaction;
ALTER TABLE hmrc_obligation_evidence_links
  DROP FOREIGN KEY IF EXISTS fk_hmrc_evidence_expense;
ALTER TABLE hmrc_obligation_evidence_links
  ADD CONSTRAINT fk_hmrc_evidence_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE hmrc_obligation_evidence_links
  ADD CONSTRAINT fk_hmrc_evidence_expense FOREIGN KEY (expense_claim_line_id) REFERENCES expense_claim_lines (id) ON DELETE RESTRICT ON UPDATE RESTRICT;
