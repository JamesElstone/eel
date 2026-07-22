CREATE TABLE IF NOT EXISTS journal_reversals (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  accounting_period_id INT NOT NULL,
  source_journal_id BIGINT NOT NULL,
  reversal_journal_id BIGINT NOT NULL,
  replacement_journal_id BIGINT NULL,
  effective_date DATE NOT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  reason TEXT NOT NULL,
  created_by VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_journal_reversals_source (source_journal_id),
  UNIQUE KEY uq_journal_reversals_reversal (reversal_journal_id),
  UNIQUE KEY uq_journal_reversals_company_key (company_id, idempotency_key),
  KEY idx_journal_reversals_period (company_id, accounting_period_id, effective_date),
  KEY idx_journal_reversals_replacement (replacement_journal_id),
  CONSTRAINT fk_journal_reversals_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_journal_reversals_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_journal_reversals_source FOREIGN KEY (source_journal_id) REFERENCES journals (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_journal_reversals_reversal FOREIGN KEY (reversal_journal_id) REFERENCES journals (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_journal_reversals_replacement FOREIGN KEY (replacement_journal_id) REFERENCES journals (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE hmrc_obligations
  ADD COLUMN IF NOT EXISTS reversal_journal_id BIGINT NULL AFTER related_journal_id,
  ADD COLUMN IF NOT EXISTS cancelled_on DATE NULL AFTER checked_at,
  ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL AFTER cancelled_on,
  ADD COLUMN IF NOT EXISTS cancelled_by VARCHAR(100) NULL AFTER cancelled_at,
  ADD COLUMN IF NOT EXISTS cancellation_reason TEXT NULL AFTER cancelled_by,
  ADD COLUMN IF NOT EXISTS superseded_by_obligation_id INT NULL AFTER cancellation_reason;

ALTER TABLE hmrc_obligations
  ADD KEY IF NOT EXISTS idx_hmrc_obligations_reversal_journal (reversal_journal_id),
  ADD KEY IF NOT EXISTS idx_hmrc_obligations_superseded_by (superseded_by_obligation_id),
  ADD CONSTRAINT fk_hmrc_obligations_reversal_journal FOREIGN KEY IF NOT EXISTS (reversal_journal_id) REFERENCES journals (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_hmrc_obligations_superseded_by FOREIGN KEY IF NOT EXISTS (superseded_by_obligation_id) REFERENCES hmrc_obligations (id) ON DELETE RESTRICT ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS hmrc_obligation_credit_transfers (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  from_obligation_id INT NOT NULL,
  to_obligation_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  reason TEXT NOT NULL,
  created_by VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hmrc_credit_transfer_pair (from_obligation_id, to_obligation_id),
  KEY idx_hmrc_credit_transfer_company (company_id, created_at),
  KEY idx_hmrc_credit_transfer_to (to_obligation_id),
  CONSTRAINT chk_hmrc_credit_transfer_positive CHECK (amount > 0),
  CONSTRAINT fk_hmrc_credit_transfer_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_hmrc_credit_transfer_from FOREIGN KEY (from_obligation_id) REFERENCES hmrc_obligations (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_hmrc_credit_transfer_to FOREIGN KEY (to_obligation_id) REFERENCES hmrc_obligations (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
