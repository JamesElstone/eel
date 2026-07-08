<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class HmrcObligationService
{
    private const TYPES = ['ct_payment', 'ct600_filing', 'hmrc_penalty', 'hmrc_interest', 'other'];
    private const STATUSES = ['not_started', 'in_progress', 'ready', 'filed', 'paid', 'part_paid', 'overdue', 'cancelled', 'not_applicable'];
    private const SOURCES = ['calculated', 'manual', 'hmrc_notice', 'journal', 'bank_match'];

    public function syncObligationsForCompany(int $companyId): array
    {
        $this->ensureSchema();
        if ($companyId <= 0) {
            return ['success' => false, 'errors' => ['Select a company before syncing HMRC obligations.'], 'created' => 0];
        }

        $created = 0;
        foreach ((new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriods($companyId) as $accountingPeriod) {
            $result = $this->syncObligationsForAccountingPeriod($companyId, (int)$accountingPeriod['id']);
            $created += (int)($result['created'] ?? 0);
        }

        return ['success' => true, 'errors' => [], 'created' => $created];
    }

    public function syncObligationsForAccountingPeriod(int $companyId, int $accountingPeriodId): array
    {
        $this->ensureSchema();
        $accountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return ['success' => false, 'errors' => ['The selected accounting period could not be found.'], 'created' => 0];
        }

        $dueDates = $this->calculateDueDates($accountingPeriod);
        $created = 0;
        foreach ([
            'ct_payment' => $dueDates['ct_payment_due_date'],
            'ct600_filing' => $dueDates['ct600_filing_due_date'],
        ] as $type => $dueDate) {
            if ($this->obligationExists($companyId, $accountingPeriodId, $type)) {
                continue;
            }

            \InterfaceDB::prepareExecute(
                'INSERT INTO hmrc_obligations (
                    company_id, accounting_period_id, obligation_type, period_start, period_end, notice_date, due_date,
                    amount_due, amount_paid, status, source, source_reference, notes
                 ) VALUES (?, ?, ?, ?, ?, NULL, ?, NULL, 0.00, ?, ?, ?, ?)',
                [
                    $companyId,
                    $accountingPeriodId,
                    $type,
                    (string)$accountingPeriod['period_start'],
                    (string)$accountingPeriod['period_end'],
                    $dueDate,
                    'not_started',
                    'calculated',
                    'accounting_period:' . $accountingPeriodId,
                    $type === 'ct_payment'
                        ? 'Calculated Corporation Tax payment deadline. Amount can be updated when CT is estimated or finalised.'
                        : 'Calculated Company Tax Return filing deadline. Companies House filing does not mark this as filed.',
                ]
            );
            $created++;
        }

        return ['success' => true, 'errors' => [], 'created' => $created];
    }

    public function syncCtPaymentAmountForAccountingPeriod(int $companyId, int $accountingPeriodId, float $amountDue): array
    {
        $this->ensureSchema();
        $accountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return ['success' => false, 'errors' => ['The selected accounting period could not be found.']];
        }

        $sync = $this->syncObligationsForAccountingPeriod($companyId, $accountingPeriodId);
        if (empty($sync['success'])) {
            return ['success' => false, 'errors' => (array)($sync['errors'] ?? ['Corporation Tax payment obligation could not be prepared.'])];
        }

        \InterfaceDB::prepareExecute(
            'UPDATE hmrc_obligations
             SET amount_due = :amount_due,
                 source = CASE WHEN source IN (:calculated_source, :journal_source) THEN :calculated_source_update ELSE source END,
                 source_reference = CASE
                    WHEN COALESCE(source_reference, \'\') = \'\' OR source = :calculated_source_ref
                    THEN :source_reference
                    ELSE source_reference
                 END,
                 notes = CASE
                    WHEN COALESCE(notes, \'\') = \'\' OR source = :calculated_source_notes
                    THEN :notes
                    ELSE notes
                 END,
                 checked_at = CURRENT_TIMESTAMP
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND obligation_type = :obligation_type',
            [
                'amount_due' => number_format(max(0.0, round($amountDue, 2)), 2, '.', ''),
                'calculated_source' => 'calculated',
                'journal_source' => 'journal',
                'calculated_source_update' => 'calculated',
                'calculated_source_ref' => 'calculated',
                'source_reference' => 'corporation_tax_provision:accounting_period_' . $accountingPeriodId,
                'calculated_source_notes' => 'calculated',
                'notes' => 'Calculated Corporation Tax payment amount from the current CT provision estimate. The ledger liability is held in nominal 2200 Corporation Tax.',
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'obligation_type' => 'ct_payment',
            ]
        );

        return ['success' => true, 'errors' => []];
    }

    public function listObligations(int $companyId, array $filters = []): array
    {
        $this->ensureSchema();
        if ($companyId <= 0) {
            return [];
        }

        $filter = $this->normaliseFilter((string)($filters['filter'] ?? 'all'));
        $rows = \InterfaceDB::fetchAll(
            'SELECT o.*,
                    ty.label AS accounting_period_label,
                    ty.period_start AS accounting_period_start,
                    ty.period_end AS accounting_period_end
             FROM hmrc_obligations o
             INNER JOIN accounting_periods ty ON ty.id = o.accounting_period_id
             WHERE o.company_id = :company_id
             ORDER BY o.period_start DESC, o.due_date ASC, o.id ASC',
            ['company_id' => $companyId]
        );

        $today = $this->today();
        $currentAccountingPeriodId = $this->currentAccountingPeriodId($companyId, $today);
        $decorated = [];
        foreach ($rows as $row) {
            $item = $this->decorateObligation((array)$row, $today);
            if (!$this->passesFilter($item, $filter, $currentAccountingPeriodId, $today)) {
                continue;
            }
            $decorated[] = $item;
        }

        return $decorated;
    }

    public function getObligation(int $id, int $companyId): ?array
    {
        $this->ensureSchema();
        if ($id <= 0 || $companyId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM hmrc_obligations
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1',
            ['id' => $id, 'company_id' => $companyId]
        );

        return is_array($row) ? $this->decorateObligation($row, $this->today()) : null;
    }

    public function updateObligationStatus(int $id, string $status, string $notes = ''): array
    {
        $this->ensureSchema();
        $status = $this->normaliseStatus($status);
        if ($id <= 0) {
            return ['success' => false, 'errors' => ['Select a valid HMRC obligation.']];
        }

        \InterfaceDB::prepareExecute(
            'UPDATE hmrc_obligations
             SET status = :status,
                 notes = CASE WHEN :notes = \'\' THEN notes ELSE :notes END,
                 checked_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            ['status' => $status, 'notes' => trim($notes), 'id' => $id]
        );

        return ['success' => true, 'errors' => []];
    }

    public function markFiled(int $id, string $sourceReference, string $notes = ''): array
    {
        $this->ensureSchema();
        if ($id <= 0) {
            return ['success' => false, 'errors' => ['Select a valid filing obligation.']];
        }

        \InterfaceDB::prepareExecute(
            'UPDATE hmrc_obligations
             SET status = :status,
                 source = :source,
                 source_reference = :source_reference,
                 notes = CASE WHEN :notes = \'\' THEN notes ELSE :notes END,
                 checked_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'status' => 'filed',
                'source' => 'manual',
                'source_reference' => trim($sourceReference) !== '' ? trim($sourceReference) : null,
                'notes' => trim($notes),
                'id' => $id,
            ]
        );

        return ['success' => true, 'errors' => []];
    }

    public function markPaid(int $id, float $amountPaid, string $sourceReference, string $notes = ''): array
    {
        $this->ensureSchema();
        if ($id <= 0) {
            return ['success' => false, 'errors' => ['Select a valid payment obligation.']];
        }
        if ($amountPaid < 0) {
            return ['success' => false, 'errors' => ['Paid amount cannot be negative.']];
        }

        $row = \InterfaceDB::fetchOne('SELECT amount_due FROM hmrc_obligations WHERE id = :id LIMIT 1', ['id' => $id]);
        $amountDue = is_array($row) && $row['amount_due'] !== null ? (float)$row['amount_due'] : null;
        $status = $amountDue !== null && $amountPaid + 0.004 >= $amountDue ? 'paid' : 'part_paid';

        \InterfaceDB::prepareExecute(
            'UPDATE hmrc_obligations
             SET amount_paid = :amount_paid,
                 status = :status,
                 source = :source,
                 source_reference = :source_reference,
                 notes = CASE WHEN :notes = \'\' THEN notes ELSE :notes END,
                 checked_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'amount_paid' => number_format($amountPaid, 2, '.', ''),
                'status' => $status,
                'source' => 'manual',
                'source_reference' => trim($sourceReference) !== '' ? trim($sourceReference) : null,
                'notes' => trim($notes),
                'id' => $id,
            ]
        );

        $warnings = [];
        if ($amountDue !== null && $amountPaid > $amountDue + 0.004) {
            $warnings[] = 'Paid amount is greater than amount due; recorded as an overpayment.';
        }

        return ['success' => true, 'errors' => [], 'warnings' => $warnings];
    }

    public function createManualObligation(array $input): array
    {
        $this->ensureSchema();
        $companyId = (int)($input['company_id'] ?? 0);
        $accountingPeriodId = (int)($input['accounting_period_id'] ?? 0);
        $type = $this->normaliseType((string)($input['obligation_type'] ?? 'hmrc_penalty'));
        $noticeDate = trim((string)($input['notice_date'] ?? ''));
        $dueDate = trim((string)($input['due_date'] ?? ''));
        $amountDue = trim((string)($input['amount_due'] ?? ''));
        $sourceReference = trim((string)($input['source_reference'] ?? ''));
        $notes = trim((string)($input['notes'] ?? ''));
        $accountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
        $postsNoticeAccrual = $this->postsNoticeAccrual($type);

        $errors = [];
        if ($accountingPeriod === null) {
            $errors[] = 'Select a valid company and accounting period.';
        }
        if ($postsNoticeAccrual && !$this->isDate($noticeDate)) {
            $errors[] = 'Enter the HMRC notice or assessment date.';
        } elseif (!$postsNoticeAccrual && $noticeDate !== '' && !$this->isDate($noticeDate)) {
            $errors[] = 'Enter a valid notice date or leave it blank.';
        }
        if (!$this->isDate($dueDate)) {
            $errors[] = 'Enter a valid HMRC due date.';
        }
        if ($postsNoticeAccrual && ($amountDue === '' || (float)$amountDue <= 0)) {
            $errors[] = 'Enter the HMRC notice amount before posting the accrual.';
        } elseif ($amountDue !== '' && (float)$amountDue < 0) {
            $errors[] = 'Amount due cannot be negative.';
        }
        if ($postsNoticeAccrual && $errors === []) {
            $noticePeriod = $this->accountingPeriodForDate($companyId, $noticeDate);
            if ($noticePeriod === null) {
                $errors[] = 'The notice date does not fall inside any accounting period for this company.';
            } else {
                $accountingPeriod = $noticePeriod;
                $accountingPeriodId = (int)$noticePeriod['id'];
            }
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            \InterfaceDB::prepareExecute(
                'INSERT INTO hmrc_obligations (
                    company_id, accounting_period_id, obligation_type, period_start, period_end, notice_date, due_date,
                    amount_due, amount_paid, status, source, source_reference, notes
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?)',
                [
                    $companyId,
                    $accountingPeriodId,
                    $type,
                    (string)$accountingPeriod['period_start'],
                    (string)$accountingPeriod['period_end'],
                    $noticeDate !== '' ? $noticeDate : null,
                    $dueDate,
                    $amountDue !== '' ? number_format((float)$amountDue, 2, '.', '') : null,
                    'not_started',
                    $postsNoticeAccrual ? 'hmrc_notice' : 'manual',
                    $sourceReference !== '' ? $sourceReference : null,
                    $notes !== '' ? $notes : null,
                ]
            );

            $obligationId = $this->lastInsertedId();
            if ($obligationId <= 0) {
                throw new \RuntimeException('The HMRC obligation could not be reloaded after insert.');
            }

            $warnings = [];
            if ($postsNoticeAccrual) {
                $obligation = $this->rawObligation($obligationId, $companyId);
                if ($obligation === null) {
                    throw new \RuntimeException('The HMRC obligation could not be found after insert.');
                }

                $journalResult = $this->postNoticeAccrualJournal($obligation);
                if (empty($journalResult['success'])) {
                    throw new \RuntimeException(implode(' ', (array)($journalResult['errors'] ?? ['The HMRC notice accrual journal could not be posted.'])));
                }

                $journalId = (int)(($journalResult['journal'] ?? [])['id'] ?? 0);
                if ($journalId <= 0) {
                    throw new \RuntimeException('The HMRC notice accrual journal could not be linked.');
                }

                \InterfaceDB::prepareExecute(
                    'UPDATE hmrc_obligations
                     SET related_journal_id = :journal_id,
                         checked_at = CURRENT_TIMESTAMP
                     WHERE id = :id',
                    ['journal_id' => $journalId, 'id' => $obligationId]
                );
                $warnings[] = 'Accrual posted to the HMRC expense nominal and 2210 HMRC Penalties & Interest Payable.';
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        return ['success' => true, 'errors' => [], 'warnings' => $warnings ?? []];
    }

    public function calculateDueDates(array $accountingPeriod): array
    {
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        try {
            $end = new \DateTimeImmutable($periodEnd);
        } catch (\Throwable) {
            $end = new \DateTimeImmutable($this->today());
        }

        return [
            'ct_payment_due_date' => $end->modify('+9 months +1 day')->format('Y-m-d'),
            'ct600_filing_due_date' => $end->modify('+12 months')->format('Y-m-d'),
        ];
    }

    public function getGuidanceState(int $companyId): array
    {
        $this->syncObligationsForCompany($companyId);
        $obligations = $this->listObligations($companyId, ['filter' => 'all']);
        $summary = $this->getOutstandingSummary($companyId);
        $messages = [];

        foreach ($obligations as $item) {
            if ((string)$item['obligation_type'] === 'ct600_filing' && !empty($item['companies_house']['filed']) && (string)$item['effective_status'] !== 'filed') {
                $messages[] = 'This period has Companies House accounts but no HMRC CT600 marked as filed.';
                break;
            }
        }
        foreach ($obligations as $item) {
            if ((string)$item['obligation_type'] === 'ct_payment' && (string)$item['effective_status'] === 'overdue') {
                $messages[] = 'Corporation Tax payment appears overdue.';
                break;
            }
        }
        foreach ($obligations as $item) {
            if (in_array((string)$item['obligation_type'], ['hmrc_penalty', 'hmrc_interest'], true) && (int)($item['related_journal_id'] ?? 0) <= 0) {
                $messages[] = 'Fine or interest is recorded but no journal has been linked yet.';
                break;
            }
        }
        foreach ($this->suggestedBankMatches($companyId) as $match) {
            $messages[] = 'Possible HMRC bank payment match found for obligation #' . (int)$match['obligation_id'] . '.';
            break;
        }

        if ($messages === []) {
            $messages[] = ((float)($summary['total_outstanding'] ?? 0) > 0)
                ? 'Review outstanding balances and mark filed or paid when evidence is available.'
                : 'No immediate HMRC obligation action is flagged from local records.';
        }

        return [
            'messages' => array_values(array_unique($messages)),
            'suggested_matches' => $this->suggestedBankMatches($companyId),
        ];
    }

    public function getOutstandingSummary(int $companyId): array
    {
        $this->ensureSchema();
        if ($companyId <= 0) {
            return [
                'total_owed' => 0.0,
                'total_overdue' => 0.0,
                'next_deadline' => null,
                'overdue_count' => 0,
                'unresolved_previous_periods' => 0,
                'ct600_filed_count' => 0,
                'ct600_missing_count' => 0,
            ];
        }

        $obligations = $this->listObligations($companyId, ['filter' => 'all']);
        $today = $this->today();
        $totalOwed = 0.0;
        $totalOverdue = 0.0;
        $overdueCount = 0;
        $previousPeriodIds = [];
        $ct600Filed = 0;
        $ct600Missing = 0;
        $nextDeadline = null;

        foreach ($obligations as $item) {
            $outstanding = (float)($item['outstanding_amount'] ?? 0);
            $totalOwed += max(0, $outstanding);
            if ((string)$item['effective_status'] === 'overdue') {
                $totalOverdue += max(0, $outstanding);
                $overdueCount++;
            }
            if ((string)$item['period_end'] < $today && !in_array((string)$item['effective_status'], ['filed', 'paid', 'cancelled', 'not_applicable'], true)) {
                $previousPeriodIds[(int)$item['accounting_period_id']] = true;
            }
            if ((string)$item['obligation_type'] === 'ct600_filing') {
                if ((string)$item['effective_status'] === 'filed') {
                    $ct600Filed++;
                } else {
                    $ct600Missing++;
                }
            }
            if (!in_array((string)$item['effective_status'], ['filed', 'paid', 'cancelled', 'not_applicable'], true) && (string)$item['due_date'] >= $today) {
                if ($nextDeadline === null || (string)$item['due_date'] < (string)$nextDeadline['due_date']) {
                    $nextDeadline = $item;
                }
            }
        }

        return [
            'total_owed' => round($totalOwed, 2),
            'total_overdue' => round($totalOverdue, 2),
            'next_deadline' => $nextDeadline,
            'overdue_count' => $overdueCount,
            'unresolved_previous_periods' => count($previousPeriodIds),
            'ct600_filed_count' => $ct600Filed,
            'ct600_missing_count' => $ct600Missing,
        ];
    }

    public function periodChecklist(int $companyId, int $accountingPeriodId): array
    {
        $accountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return [];
        }
        $obligations = $this->listObligations($companyId, ['filter' => 'all']);
        $periodObligations = array_values(array_filter($obligations, static fn(array $row): bool => (int)$row['accounting_period_id'] === $accountingPeriodId));
        $ctPayment = $this->firstByType($periodObligations, 'ct_payment');
        $ct600 = $this->firstByType($periodObligations, 'ct600_filing');
        $transactions = $this->transactionSummary($companyId, $accountingPeriodId);
        $journalCount = $this->journalCount($companyId, $accountingPeriodId);
        $ch = $this->companiesHouseStatus($companyId, (string)$accountingPeriod['period_end']);

        return [
            $this->check('Bank transactions imported', (int)$transactions['total'] > 0, (int)$transactions['total'] . ' transaction(s) in period.'),
            $this->check('Transactions categorised', (int)$transactions['total'] > 0 && (int)$transactions['uncategorised'] === 0, (int)$transactions['uncategorised'] . ' uncategorised transaction(s).'),
            $this->check('Manual journals complete', $journalCount > 0, $journalCount . ' posted journal(s).'),
            $this->check('Trial balance generated', $journalCount > 0, 'Ledger postings exist for this period.'),
            $this->check('Corporation Tax estimated', is_array($ctPayment) && $ctPayment['amount_due'] !== null, 'Payment obligation can hold estimated amount until final CT.'),
            $this->check('iXBRL generated', false, 'Not recorded in this MVP.'),
            $this->check('CT600 prepared', is_array($ct600) && in_array((string)$ct600['effective_status'], ['ready', 'filed'], true), 'Mark ready/filed when prepared.'),
            $this->check('CT payment recorded', is_array($ctPayment) && in_array((string)$ctPayment['effective_status'], ['paid', 'part_paid'], true), 'Record payment reference when paid.'),
            $this->check('CT600 filed with HMRC', is_array($ct600) && (string)$ct600['effective_status'] === 'filed', 'Companies House filing is separate.'),
            $this->check('Companies House accounts filed', !empty($ch['filed']), (string)($ch['detail'] ?? 'No Companies House accounts match this period end.')),
            $this->check('HMRC acknowledgement stored', is_array($ct600) && trim((string)($ct600['source_reference'] ?? '')) !== '', 'Store HMRC submission reference once available.'),
        ];
    }

    public function filters(): array
    {
        return [
            'all' => 'All',
            'overdue' => 'Overdue',
            'due_soon' => 'Due soon',
            'unpaid' => 'Unpaid',
            'unfiled' => 'Unfiled',
            'previous_years' => 'Previous years',
            'current_year' => 'Current year',
            'fines_only' => 'Fines only',
        ];
    }

    public function ensureSchema(): void
    {
        if (\InterfaceDB::tableExists('hmrc_obligations')) {
            if (!\InterfaceDB::columnExists('hmrc_obligations', 'notice_date')) {
                \InterfaceDB::prepareExecute('ALTER TABLE hmrc_obligations ADD COLUMN notice_date DATE NULL AFTER period_end');
                \InterfaceDB::prepareExecute(
                    'UPDATE hmrc_obligations
                     SET notice_date = due_date
                     WHERE notice_date IS NULL
                       AND obligation_type IN (:penalty_type, :interest_type)',
                    ['penalty_type' => 'hmrc_penalty', 'interest_type' => 'hmrc_interest']
                );
            }
            return;
        }

        \InterfaceDB::prepareExecute(
            "CREATE TABLE IF NOT EXISTS hmrc_obligations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL,
                accounting_period_id INT NOT NULL,
                obligation_type ENUM('ct_payment','ct600_filing','hmrc_penalty','hmrc_interest','other') NOT NULL,
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                notice_date DATE NULL,
                due_date DATE NOT NULL,
                amount_due DECIMAL(12,2) NULL,
                amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status ENUM('not_started','in_progress','ready','filed','paid','part_paid','overdue','cancelled','not_applicable') NOT NULL DEFAULT 'not_started',
                source ENUM('calculated','manual','hmrc_notice','journal','bank_match') NOT NULL DEFAULT 'calculated',
                source_reference VARCHAR(255) NULL,
                related_journal_id BIGINT NULL,
                related_fine_id INT NULL,
                checked_at DATETIME NULL,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_hmrc_obligations_period_type (company_id, accounting_period_id, obligation_type),
                KEY idx_hmrc_obligations_company_accounting_period (company_id, accounting_period_id),
                KEY idx_hmrc_obligations_type (obligation_type),
                KEY idx_hmrc_obligations_due_date (due_date),
                KEY idx_hmrc_obligations_status (status),
                KEY idx_hmrc_obligations_company_due_status (company_id, due_date, status),
                CONSTRAINT fk_hmrc_obligations_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_hmrc_obligations_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods(id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_hmrc_obligations_journal FOREIGN KEY (related_journal_id) REFERENCES journals(id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function postsNoticeAccrual(string $type): bool
    {
        return in_array($type, ['hmrc_penalty', 'hmrc_interest'], true);
    }

    private function accountingPeriodForDate(int $companyId, string $date): ?array
    {
        if ($companyId <= 0 || !$this->isDate($date)) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM accounting_periods
             WHERE company_id = :company_id
               AND :notice_date BETWEEN period_start AND period_end
             ORDER BY period_start DESC, id DESC
             LIMIT 1',
            ['company_id' => $companyId, 'notice_date' => $date]
        );

        return is_array($row) ? $row : null;
    }

    private function rawObligation(int $id, int $companyId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM hmrc_obligations
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1',
            ['id' => $id, 'company_id' => $companyId]
        );

        return is_array($row) ? $row : null;
    }

    private function lastInsertedId(): int
    {
        $sql = \InterfaceDB::driverName() === 'sqlite'
            ? 'SELECT last_insert_rowid()'
            : 'SELECT LAST_INSERT_ID()';

        return (int)(\InterfaceDB::fetchColumn($sql) ?: 0);
    }

    private function postNoticeAccrualJournal(array $obligation): array
    {
        $type = $this->normaliseType((string)($obligation['obligation_type'] ?? ''));
        if (!$this->postsNoticeAccrual($type)) {
            return ['success' => true, 'errors' => [], 'skipped' => true];
        }

        if ((int)($obligation['related_journal_id'] ?? 0) > 0) {
            return ['success' => true, 'errors' => [], 'skipped' => true];
        }

        $amount = round((float)($obligation['amount_due'] ?? 0), 2);
        if ($amount <= 0) {
            return ['success' => false, 'errors' => ['HMRC penalty or interest notices need a positive amount before the accrual can be posted.']];
        }

        $noticeDate = trim((string)($obligation['notice_date'] ?? ''));
        if (!$this->isDate($noticeDate)) {
            return ['success' => false, 'errors' => ['HMRC penalty or interest notices need a valid notice date before the accrual can be posted.']];
        }

        $expenseNominalId = $this->nominalId([$type === 'hmrc_interest' ? '6231' : '6230'], 'expense');
        $payableNominalId = $this->nominalId(['2210'], 'liability');
        if ($expenseNominalId <= 0 || $payableNominalId <= 0) {
            return ['success' => false, 'errors' => ['HMRC penalty/interest expense and payable nominal accounts are required before posting the accrual.']];
        }

        $label = $type === 'hmrc_interest' ? 'HMRC interest' : 'HMRC penalty';
        $reference = trim((string)($obligation['source_reference'] ?? ''));
        $lineDescription = $label . ($reference !== '' ? ' - ' . $reference : '');
        $description = $label . ' notice accrual' . ($reference !== '' ? ' - ' . $reference : '');

        return (new \eel_accounts\Service\ManualJournalService())->saveTaggedJournal(
            (int)$obligation['company_id'],
            (int)$obligation['accounting_period_id'],
            'hmrc_obligation_accrual',
            'obligation_' . (int)$obligation['id'],
            $noticeDate,
            $description,
            [
                ['nominal_account_id' => $expenseNominalId, 'debit' => $amount, 'credit' => 0.0, 'line_description' => $lineDescription],
                ['nominal_account_id' => $payableNominalId, 'debit' => 0.0, 'credit' => $amount, 'line_description' => $lineDescription],
            ],
            'system_generated',
            null,
            null,
            'Posted from HMRC obligation #' . (int)$obligation['id'] . '. Later bank payments should clear nominal 2210, not the expense nominal.',
            'web_app'
        );
    }

    private function nominalId(array $codes, string $accountType): int
    {
        foreach ($codes as $code) {
            $id = (int)\InterfaceDB::fetchColumn(
                'SELECT id
                 FROM nominal_accounts
                 WHERE code = :code
                   AND account_type = :account_type
                   AND is_active = 1
                 LIMIT 1',
                ['code' => $code, 'account_type' => $accountType]
            );
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    private function obligationExists(int $companyId, int $accountingPeriodId, string $type): bool
    {
        return \InterfaceDB::countWhere('hmrc_obligations', [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'obligation_type' => $type,
        ]) > 0;
    }

    private function decorateObligation(array $row, string $today): array
    {
        $amountDue = $row['amount_due'] !== null ? round((float)$row['amount_due'], 2) : null;
        $amountPaid = round((float)($row['amount_paid'] ?? 0), 2);
        $outstanding = $amountDue !== null ? round($amountDue - $amountPaid, 2) : null;
        $status = (string)($row['status'] ?? 'not_started');
        $type = (string)($row['obligation_type'] ?? '');
        $effective = $status;
        if (!in_array($status, ['filed', 'paid', 'cancelled', 'not_applicable'], true)) {
            if (in_array($type, ['ct_payment', 'hmrc_penalty', 'hmrc_interest', 'other'], true) && $amountDue !== null && $amountPaid > 0 && $amountPaid < $amountDue) {
                $effective = (string)$row['due_date'] < $today ? 'overdue' : 'part_paid';
            } elseif ($amountDue !== null && $amountPaid + 0.004 >= $amountDue && $type !== 'ct600_filing') {
                $effective = 'paid';
            } elseif ((string)$row['due_date'] < $today) {
                $effective = 'overdue';
            }
        }

        $row['amount_due'] = $amountDue;
        $row['amount_paid'] = $amountPaid;
        $row['outstanding_amount'] = $outstanding;
        $row['effective_status'] = $effective;
        $row['days_delta'] = $this->daysDelta((string)$row['due_date'], $today);
        $row['action_needed'] = $this->actionNeeded($row);
        $row['companies_house'] = $this->companiesHouseStatus((int)$row['company_id'], (string)$row['period_end']);

        return $row;
    }

    private function actionNeeded(array $row): string
    {
        $status = (string)($row['effective_status'] ?? '');
        $type = (string)($row['obligation_type'] ?? '');
        if (in_array($status, ['paid', 'filed', 'cancelled', 'not_applicable'], true)) {
            return 'No action needed';
        }
        if ($status === 'overdue') {
            return $type === 'ct600_filing' ? 'File or record HMRC filing evidence' : 'Pay or record payment evidence';
        }
        if ($type === 'ct600_filing') {
            return 'Prepare CT600 / iXBRL and record filing evidence';
        }
        if ($type === 'ct_payment') {
            return 'Estimate/finalise CT and record payment when made';
        }
        if (in_array($type, ['hmrc_penalty', 'hmrc_interest'], true)) {
            return 'Record HMRC notice and payment evidence';
        }

        return 'Review obligation';
    }

    private function passesFilter(array $item, string $filter, int $currentAccountingPeriodId, string $today): bool
    {
        return match ($filter) {
            'overdue' => (string)$item['effective_status'] === 'overdue',
            'due_soon' => (int)$item['days_delta'] >= 0 && (int)$item['days_delta'] <= 30 && !in_array((string)$item['effective_status'], ['paid', 'filed', 'cancelled', 'not_applicable'], true),
            'unpaid' => in_array((string)$item['obligation_type'], ['ct_payment', 'hmrc_penalty', 'hmrc_interest', 'other'], true) && (float)($item['outstanding_amount'] ?? 0) > 0,
            'unfiled' => (string)$item['obligation_type'] === 'ct600_filing' && (string)$item['effective_status'] !== 'filed',
            'previous_years' => (int)$item['accounting_period_id'] !== $currentAccountingPeriodId && (string)$item['period_end'] < $today,
            'current_year' => (int)$item['accounting_period_id'] === $currentAccountingPeriodId,
            'fines_only' => in_array((string)$item['obligation_type'], ['hmrc_penalty', 'hmrc_interest'], true),
            default => true,
        };
    }

    private function normaliseFilter(string $filter): string
    {
        return array_key_exists($filter, $this->filters()) ? $filter : 'all';
    }

    private function normaliseStatus(string $status): string
    {
        return in_array($status, self::STATUSES, true) ? $status : 'not_started';
    }

    private function normaliseType(string $type): string
    {
        return in_array($type, self::TYPES, true) ? $type : 'other';
    }

    private function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function daysDelta(string $dueDate, string $today): int
    {
        try {
            $due = new \DateTimeImmutable($dueDate);
            $now = new \DateTimeImmutable($today);
        } catch (\Throwable) {
            return 0;
        }

        return (int)$now->diff($due)->format('%r%a');
    }

    private function currentAccountingPeriodId(int $companyId, string $today): int
    {
        $value = \InterfaceDB::fetchColumn(
            'SELECT id
             FROM accounting_periods
             WHERE company_id = :company_id
               AND :today BETWEEN period_start AND period_end
             ORDER BY period_start DESC
             LIMIT 1',
            ['company_id' => $companyId, 'today' => $today]
        );
        if ($value !== false && (int)$value > 0) {
            return (int)$value;
        }

        return (int)(\InterfaceDB::fetchColumn(
            'SELECT id
             FROM accounting_periods
             WHERE company_id = :company_id
             ORDER BY period_end DESC
             LIMIT 1',
            ['company_id' => $companyId]
        ) ?: 0);
    }

    private function companiesHouseStatus(int $companyId, string $periodEnd): array
    {
        if ($companyId <= 0 || !$this->isDate($periodEnd) || !\InterfaceDB::tableExists('companies_house_documents')) {
            return ['filed' => false, 'detail' => 'No Companies House document data available.'];
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT d.id, d.filing_date, d.filing_type, d.filing_description, d.document_id, d.parse_status
             FROM companies_house_documents d
             LEFT JOIN companies_house_document_contexts c ON c.document_fk = d.id
             WHERE d.company_id = :company_id
               AND (
                    d.significant_date = :period_end
                    OR c.period_end = :period_end
                    OR c.instant_date = :period_end
               )
               AND (
                    COALESCE(d.filing_category, \'\') LIKE :accounts
                    OR COALESCE(d.filing_description, \'\') LIKE :accounts
                    OR COALESCE(d.filing_type, \'\') LIKE :aa_type
               )
             ORDER BY d.filing_date DESC, d.id DESC
             LIMIT 1',
            [
                'company_id' => $companyId,
                'period_end' => $periodEnd,
                'accounts' => '%accounts%',
                'aa_type' => 'AA%',
            ]
        );

        if (!is_array($row)) {
            return ['filed' => false, 'detail' => 'No Companies House accounts match this period end.'];
        }

        return [
            'filed' => true,
            'filing_date' => (string)($row['filing_date'] ?? ''),
            'document_id' => (string)($row['document_id'] ?? ''),
            'parse_status' => (string)($row['parse_status'] ?? ''),
            'detail' => 'Companies House accounts filed on ' . (string)($row['filing_date'] ?? ''),
        ];
    }

    private function transactionSummary(int $companyId, int $accountingPeriodId): array
    {
        try {
            $hasInterAccountMarkers = \InterfaceDB::tableExists('transaction_inter_ac_marker');
        } catch (\Throwable) {
            $hasInterAccountMarkers = false;
        }
        $noPostPredicate = $hasInterAccountMarkers
            ? "EXISTS (
                   SELECT 1
                   FROM transaction_inter_ac_marker tiam
                   WHERE tiam.matched_transaction_id = transactions.id
               )"
            : '0 = 1';
        $row = \InterfaceDB::fetchOne(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN NOT (' . $noPostPredicate . ') AND (category_status = :uncategorised OR nominal_account_id IS NULL) THEN 1 ELSE 0 END) AS uncategorised
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id',
            ['uncategorised' => 'uncategorised', 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        ) ?: [];

        return ['total' => (int)($row['total'] ?? 0), 'uncategorised' => (int)($row['uncategorised'] ?? 0)];
    }

    private function journalCount(int $companyId, int $accountingPeriodId): int
    {
        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM journals
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND is_posted = 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
    }

    private function check(string $label, bool $complete, string $detail): array
    {
        return ['label' => $label, 'complete' => $complete, 'detail' => $detail];
    }

    private function firstByType(array $items, string $type): ?array
    {
        foreach ($items as $item) {
            if ((string)($item['obligation_type'] ?? '') === $type) {
                return $item;
            }
        }

        return null;
    }

    private function suggestedBankMatches(int $companyId): array
    {
        if ($companyId <= 0) {
            return [];
        }

        return \InterfaceDB::fetchAll(
            'SELECT o.id AS obligation_id,
                    o.obligation_type,
                    o.amount_due,
                    o.amount_paid,
                    t.id AS transaction_id,
                    t.txn_date,
                    t.description,
                    t.reference,
                    t.amount
             FROM hmrc_obligations o
             INNER JOIN transactions t ON t.company_id = o.company_id
             WHERE o.company_id = :company_id
               AND o.obligation_type IN (:ct_payment, :hmrc_penalty, :hmrc_interest, :other_type)
               AND (o.amount_due IS NULL OR o.amount_paid < o.amount_due)
               AND (
                    LOWER(COALESCE(t.description, \'\')) LIKE :hmrc
                    OR LOWER(COALESCE(t.reference, \'\')) LIKE :hmrc
                    OR (o.source_reference IS NOT NULL AND o.source_reference <> \'\' AND (
                        LOWER(COALESCE(t.description, \'\')) LIKE CONCAT(\'%\', LOWER(o.source_reference), \'%\')
                        OR LOWER(COALESCE(t.reference, \'\')) LIKE CONCAT(\'%\', LOWER(o.source_reference), \'%\')
                    ))
               )
             ORDER BY t.txn_date DESC, ABS(t.amount) DESC
             LIMIT 10',
            [
                'company_id' => $companyId,
                'ct_payment' => 'ct_payment',
                'hmrc_penalty' => 'hmrc_penalty',
                'hmrc_interest' => 'hmrc_interest',
                'other_type' => 'other',
                'hmrc' => '%hmrc%',
            ]
        );
    }
}
