<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class IncorporationShareCapitalService
{
    private const SHARE_TABLE = 'company_incorporation_share_classes';
    private const MATCH_TABLE = 'company_incorporation_share_payment_matches';
    private const ORDINARY_SHARE_CAPITAL_CODE = '3010';

    public function fetchSummary(int $companyId): array
    {
        if ($companyId <= 0) {
            return [
                'available' => false,
                'errors' => ['Select a company before reviewing incorporation shares.'],
            ];
        }

        if (!$this->tablesAvailable()) {
            return [
                'available' => false,
                'errors' => ['Incorporation share capital tables are not available. Run the database migrations.'],
            ];
        }

        $company = $this->fetchCompany($companyId);
        $shareClasses = $this->fetchShareClasses($companyId);
        $totals = [
            'issued_nominal_total' => 0.0,
            'expected_paid_total' => 0.0,
            'unpaid_total' => 0.0,
            'matched_total' => 0.0,
        ];

        foreach ($shareClasses as &$shareClass) {
            $shareClass['nominal_total'] = $this->classNominalTotal($shareClass);
            $shareClass['expected_paid_total'] = $this->classPaidTotal($shareClass);
            $shareClass['unpaid_total'] = $this->classUnpaidTotal($shareClass);
            $shareClass['current_match'] = $this->currentMatch((int)$shareClass['id']);
            $shareClass['payment_candidates'] = $this->paymentCandidatesForShareClass($shareClass, $company);
            $shareClass['payment_status'] = $this->paymentStatus($shareClass);

            $totals['issued_nominal_total'] += (float)$shareClass['nominal_total'];
            $totals['expected_paid_total'] += (float)$shareClass['expected_paid_total'];
            $totals['unpaid_total'] += (float)$shareClass['unpaid_total'];
            $totals['matched_total'] += is_array($shareClass['current_match'])
                ? (float)($shareClass['current_match']['matched_amount'] ?? 0)
                : 0.0;
        }
        unset($shareClass);

        foreach ($totals as $key => $value) {
            $totals[$key] = round((float)$value, 2);
        }

        return [
            'available' => true,
            'errors' => [],
            'company' => $company,
            'share_classes' => $shareClasses,
            'totals' => $totals,
            'status' => $this->summaryStatus($shareClasses),
            'ordinary_share_capital_nominal' => $this->ordinaryShareCapitalNominal(),
        ];
    }

    public function saveShareClass(array $input, string $changedBy = 'web_app'): array
    {
        $companyId = (int)($input['company_id'] ?? 0);
        if (!$this->tablesAvailable()) {
            return ['success' => false, 'errors' => ['Incorporation share capital tables are not available. Run the database migrations.']];
        }

        $normalised = $this->normaliseShareInput($input);
        $errors = $this->validateShareInput($normalised);
        if ($companyId <= 0) {
            $errors[] = 'Select a company before saving incorporation shares.';
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $shareClassId = (int)($input['share_class_id'] ?? 0);
        $params = [
            'company_id' => $companyId,
            'share_class' => $normalised['share_class'],
            'currency' => $normalised['currency'],
            'quantity' => $normalised['quantity'],
            'nominal_value_per_share' => $normalised['nominal_value_per_share'],
            'paid_value_per_share' => $normalised['paid_value_per_share'],
            'unpaid_value_per_share' => $normalised['unpaid_value_per_share'],
            'source_note' => $normalised['source_note'],
            'document_reference' => $normalised['document_reference'],
            'status' => $this->enteredStatus($normalised),
        ];

        if ($shareClassId > 0) {
            $existing = $this->fetchShareClass($companyId, $shareClassId);
            if ($existing === null) {
                return ['success' => false, 'errors' => ['The selected share class could not be found for this company.']];
            }

            \InterfaceDB::prepareExecute(
                'UPDATE company_incorporation_share_classes
                 SET share_class = :share_class,
                     currency = :currency,
                     quantity = :quantity,
                     nominal_value_per_share = :nominal_value_per_share,
                     paid_value_per_share = :paid_value_per_share,
                     unpaid_value_per_share = :unpaid_value_per_share,
                     source_note = :source_note,
                     document_reference = :document_reference,
                     status = :status
                 WHERE id = :id
                   AND company_id = :company_id',
                $params + ['id' => $shareClassId]
            );
        } else {
            \InterfaceDB::prepareExecute(
                'INSERT INTO company_incorporation_share_classes (
                    company_id,
                    share_class,
                    currency,
                    quantity,
                    nominal_value_per_share,
                    paid_value_per_share,
                    unpaid_value_per_share,
                    source_note,
                    document_reference,
                    status
                 ) VALUES (
                    :company_id,
                    :share_class,
                    :currency,
                    :quantity,
                    :nominal_value_per_share,
                    :paid_value_per_share,
                    :unpaid_value_per_share,
                    :source_note,
                    :document_reference,
                    :status
                )',
                $params
            );
            $shareClassId = (int)\InterfaceDB::fetchColumn(
                'SELECT id
                 FROM company_incorporation_share_classes
                 WHERE company_id = :company_id
                   AND share_class = :share_class
                   AND currency = :currency
                   AND quantity = :quantity
                   AND nominal_value_per_share = :nominal_value_per_share
                   AND paid_value_per_share = :paid_value_per_share
                   AND unpaid_value_per_share = :unpaid_value_per_share
                 ORDER BY id DESC
                 LIMIT 1',
                [
                    'company_id' => $companyId,
                    'share_class' => $normalised['share_class'],
                    'currency' => $normalised['currency'],
                    'quantity' => $normalised['quantity'],
                    'nominal_value_per_share' => $normalised['nominal_value_per_share'],
                    'paid_value_per_share' => $normalised['paid_value_per_share'],
                    'unpaid_value_per_share' => $normalised['unpaid_value_per_share'],
                ]
            );
        }

        return [
            'success' => true,
            'share_class_id' => $shareClassId,
            'changed_by' => $changedBy,
            'summary' => $this->fetchSummary($companyId),
        ];
    }

    public function markSharesUnpaid(int $companyId, int $shareClassId, string $changedBy = 'web_app'): array
    {
        $shareClass = $this->fetchShareClass($companyId, $shareClassId);
        if ($shareClass === null) {
            return ['success' => false, 'errors' => ['The selected share class could not be found.']];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }
        try {
            $this->clearCurrentMatches($companyId, $shareClassId, $changedBy);
            \InterfaceDB::prepareExecute(
                'UPDATE company_incorporation_share_classes
                 SET paid_value_per_share = 0.000000,
                     unpaid_value_per_share = nominal_value_per_share,
                     status = :status
                 WHERE id = :id
                   AND company_id = :company_id',
                [
                    'status' => 'unpaid',
                    'id' => $shareClassId,
                    'company_id' => $companyId,
                ]
            );
            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            throw $exception;
        }

        return ['success' => true, 'summary' => $this->fetchSummary($companyId)];
    }

    public function clearPaymentMatch(int $companyId, int $shareClassId, string $changedBy = 'web_app'): array
    {
        if ($this->fetchShareClass($companyId, $shareClassId) === null) {
            return ['success' => false, 'errors' => ['The selected share class could not be found.']];
        }

        $this->clearCurrentMatches($companyId, $shareClassId, $changedBy);

        return ['success' => true, 'summary' => $this->fetchSummary($companyId)];
    }

    public function matchPayment(int $companyId, int $shareClassId, int $transactionId, string $changedBy = 'web_app'): array
    {
        $shareClass = $this->fetchShareClass($companyId, $shareClassId);
        if ($shareClass === null) {
            return ['success' => false, 'errors' => ['The selected share class could not be found.']];
        }

        $company = $this->fetchCompany($companyId);
        $transaction = $this->fetchTransaction($transactionId);
        $validationErrors = $this->validatePaymentMatch($shareClass, $company, $transaction);
        if ($validationErrors !== []) {
            return ['success' => false, 'errors' => $validationErrors];
        }

        $shareCapitalNominal = $this->ordinaryShareCapitalNominal();
        if ((int)($shareCapitalNominal['id'] ?? 0) <= 0) {
            return ['success' => false, 'errors' => ['Ordinary Share Capital nominal 3010 is missing.']];
        }

        $bankNominalId = $this->defaultBankNominalId($companyId);
        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }
        try {
            $categorisation = (new \eel_accounts\Service\TransactionCategorisationService())->saveManualCategorisation(
                $transactionId,
                (int)$shareCapitalNominal['id'],
                null,
                false,
                'incorporation_share_payment',
                true
            );
            if (empty($categorisation['success'])) {
                throw new \RuntimeException(implode(' ', array_map('strval', (array)($categorisation['errors'] ?? ['Share payment transaction could not be categorised.']))));
            }

            $journal = (new \eel_accounts\Service\TransactionJournalService())->syncJournalForTransaction(
                $transactionId,
                $bankNominalId,
                'incorporation_share_payment',
                true
            );
            if (!empty($journal['errors'])) {
                throw new \RuntimeException(implode(' ', array_map('strval', (array)$journal['errors'])));
            }

            $this->clearCurrentMatches($companyId, $shareClassId, $changedBy);
            \InterfaceDB::prepareExecute(
                'INSERT INTO company_incorporation_share_payment_matches (
                    company_id,
                    share_class_id,
                    transaction_id,
                    matched_amount,
                    matched_by
                 ) VALUES (
                    :company_id,
                    :share_class_id,
                    :transaction_id,
                    :matched_amount,
                    :matched_by
                 )',
                [
                    'company_id' => $companyId,
                    'share_class_id' => $shareClassId,
                    'transaction_id' => $transactionId,
                    'matched_amount' => round((float)($transaction['amount'] ?? 0), 2),
                    'matched_by' => $this->actorValue($changedBy),
                ]
            );

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        return ['success' => true, 'summary' => $this->fetchSummary($companyId)];
    }

    public function paymentCandidates(int $companyId, int $shareClassId): array
    {
        $shareClass = $this->fetchShareClass($companyId, $shareClassId);
        if ($shareClass === null) {
            return [];
        }

        return $this->paymentCandidatesForShareClass($shareClass, $this->fetchCompany($companyId));
    }

    private function fetchShareClasses(int $companyId): array
    {
        return \InterfaceDB::fetchAll(
            'SELECT *
             FROM company_incorporation_share_classes
             WHERE company_id = :company_id
             ORDER BY id ASC',
            ['company_id' => $companyId]
        );
    }

    private function fetchShareClass(int $companyId, int $shareClassId): ?array
    {
        if ($companyId <= 0 || $shareClassId <= 0 || !$this->tablesAvailable()) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM company_incorporation_share_classes
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1',
            ['id' => $shareClassId, 'company_id' => $companyId]
        );

        return is_array($row) ? $row : null;
    }

    private function currentMatch(int $shareClassId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT m.*,
                    t.txn_date,
                    t.description,
                    t.reference,
                    t.amount,
                    t.category_status,
                    na.code AS nominal_code,
                    na.name AS nominal_name
             FROM company_incorporation_share_payment_matches m
             INNER JOIN transactions t ON t.id = m.transaction_id
             LEFT JOIN nominal_accounts na ON na.id = t.nominal_account_id
             WHERE m.share_class_id = :share_class_id
               AND m.match_status = :match_status
             ORDER BY m.matched_at DESC, m.id DESC
             LIMIT 1',
            ['share_class_id' => $shareClassId, 'match_status' => 'current']
        );

        return is_array($row) ? $row : null;
    }

    private function paymentCandidatesForShareClass(array $shareClass, array $company): array
    {
        $companyId = (int)($shareClass['company_id'] ?? 0);
        $expectedAmount = $this->classPaidTotal($shareClass);
        if ($companyId <= 0 || $expectedAmount <= 0.0 || !$this->tableExists('transactions')) {
            return [];
        }

        $start = $this->validDate((string)($company['incorporation_date'] ?? ''))
            ? (string)$company['incorporation_date']
            : '1900-01-01';
        $end = $start === '1900-01-01'
            ? '2999-12-31'
            : (new \DateTimeImmutable($start))->modify('+90 days')->format('Y-m-d');

        return \InterfaceDB::fetchAll(
            'SELECT t.id,
                    t.txn_date,
                    t.description,
                    t.reference,
                    t.amount,
                    t.category_status,
                    t.nominal_account_id,
                    na.code AS nominal_code,
                    na.name AS nominal_name,
                    CASE
                        WHEN LOWER(CONCAT(COALESCE(t.description, \'\'), \' \', COALESCE(t.reference, \'\'))) LIKE :share_keyword THEN 30
                        WHEN LOWER(CONCAT(COALESCE(t.description, \'\'), \' \', COALESCE(t.reference, \'\'))) LIKE :capital_keyword THEN 20
                        ELSE 0
                    END AS score
             FROM transactions t
             LEFT JOIN nominal_accounts na ON na.id = t.nominal_account_id
             WHERE t.company_id = :company_id
               AND t.amount BETWEEN :lower_amount AND :upper_amount
               AND t.txn_date BETWEEN :start_date AND :end_date
             ORDER BY score DESC, t.txn_date ASC, t.id ASC
             LIMIT 10',
            [
                'company_id' => $companyId,
                'lower_amount' => round($expectedAmount - 0.01, 2),
                'upper_amount' => round($expectedAmount + 0.01, 2),
                'start_date' => $start,
                'end_date' => $end,
                'share_keyword' => '%share%',
                'capital_keyword' => '%capital%',
            ]
        );
    }

    private function validatePaymentMatch(array $shareClass, array $company, ?array $transaction): array
    {
        if ($transaction === null) {
            return ['The selected transaction could not be found.'];
        }

        $errors = [];
        if ((int)($transaction['company_id'] ?? 0) !== (int)($shareClass['company_id'] ?? 0)) {
            $errors[] = 'The selected transaction belongs to a different company.';
        }

        $expectedAmount = $this->classPaidTotal($shareClass);
        if ($expectedAmount <= 0.0) {
            $errors[] = 'This share class has no expected paid amount to match.';
        }

        if (abs(round((float)($transaction['amount'] ?? 0), 2) - $expectedAmount) > 0.01) {
            $errors[] = 'The selected transaction amount does not match the expected paid share total.';
        }

        $incorporationDate = (string)($company['incorporation_date'] ?? '');
        $transactionDate = (string)($transaction['txn_date'] ?? '');
        if ($this->validDate($incorporationDate) && $this->validDate($transactionDate)) {
            $start = new \DateTimeImmutable($incorporationDate);
            $end = $start->modify('+90 days');
            $date = new \DateTimeImmutable($transactionDate);
            if ($date < $start || $date > $end) {
                $errors[] = 'The selected transaction is outside the incorporation payment matching window.';
            }
        }

        return $errors;
    }

    private function clearCurrentMatches(int $companyId, int $shareClassId, string $changedBy): void
    {
        \InterfaceDB::prepareExecute(
            'UPDATE company_incorporation_share_payment_matches
             SET match_status = :cleared_status,
                 cleared_at = CURRENT_TIMESTAMP,
                 cleared_by = :cleared_by
             WHERE company_id = :company_id
               AND share_class_id = :share_class_id
               AND match_status = :current_status',
            [
                'cleared_status' => 'cleared',
                'cleared_by' => $this->actorValue($changedBy),
                'company_id' => $companyId,
                'share_class_id' => $shareClassId,
                'current_status' => 'current',
            ]
        );
    }

    private function fetchCompany(int $companyId): array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id,
                    company_name,
                    company_number,
                    incorporation_date
             FROM companies
             WHERE id = :id
             LIMIT 1',
            ['id' => $companyId]
        );

        return is_array($row) ? $row : [];
    }

    private function fetchTransaction(int $transactionId): ?array
    {
        if ($transactionId <= 0 || !$this->tableExists('transactions')) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM transactions
             WHERE id = :id
             LIMIT 1',
            ['id' => $transactionId]
        );

        return is_array($row) ? $row : null;
    }

    private function ordinaryShareCapitalNominal(): array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id, code, name
             FROM nominal_accounts
             WHERE code = :code
             LIMIT 1',
            ['code' => self::ORDINARY_SHARE_CAPITAL_CODE]
        );

        return is_array($row) ? $row : [];
    }

    private function defaultBankNominalId(int $companyId): int
    {
        if ($companyId <= 0) {
            return 0;
        }

        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $value = trim((string)($settings['default_bank_nominal_id'] ?? ''));

        return ctype_digit($value) ? (int)$value : 0;
    }

    private function normaliseShareInput(array $input): array
    {
        return [
            'share_class' => trim((string)($input['share_class'] ?? 'Ordinary')) ?: 'Ordinary',
            'currency' => strtoupper(trim((string)($input['currency'] ?? 'GBP'))) ?: 'GBP',
            'quantity' => (int)($input['quantity'] ?? 0),
            'nominal_value_per_share' => $this->normaliseDecimal($input['nominal_value_per_share'] ?? 0),
            'paid_value_per_share' => $this->normaliseDecimal($input['paid_value_per_share'] ?? 0),
            'unpaid_value_per_share' => $this->normaliseDecimal($input['unpaid_value_per_share'] ?? 0),
            'source_note' => trim((string)($input['source_note'] ?? '')),
            'document_reference' => trim((string)($input['document_reference'] ?? '')),
        ];
    }

    private function validateShareInput(array $input): array
    {
        $errors = [];
        if ((string)$input['share_class'] === '') {
            $errors[] = 'Share class is required.';
        }
        if (!preg_match('/^[A-Z]{3}$/', (string)$input['currency'])) {
            $errors[] = 'Currency must be a 3-letter code.';
        }
        if ((int)$input['quantity'] <= 0) {
            $errors[] = 'Share quantity must be greater than zero.';
        }
        foreach (['nominal_value_per_share', 'paid_value_per_share', 'unpaid_value_per_share'] as $field) {
            if ((float)$input[$field] < 0.0) {
                $errors[] = 'Share values cannot be negative.';
                break;
            }
        }
        if ((float)$input['nominal_value_per_share'] <= 0.0) {
            $errors[] = 'Nominal value per share must be greater than zero.';
        }
        if ((float)$input['paid_value_per_share'] <= 0.0 && (float)$input['unpaid_value_per_share'] <= 0.0) {
            $errors[] = 'Enter either a paid or unpaid amount per share.';
        }

        return $errors;
    }

    private function enteredStatus(array $input): string
    {
        $paid = (float)$input['paid_value_per_share'];
        $unpaid = (float)$input['unpaid_value_per_share'];
        if ($paid > 0.0 && $unpaid <= 0.0) {
            return 'paid';
        }
        if ($paid > 0.0 && $unpaid > 0.0) {
            return 'part_paid';
        }
        if ($paid <= 0.0 && $unpaid > 0.0) {
            return 'unpaid';
        }

        return 'unresolved';
    }

    private function paymentStatus(array $shareClass): string
    {
        $expectedPaid = $this->classPaidTotal($shareClass);
        if ($expectedPaid <= 0.0) {
            return 'not_paid_up';
        }

        $match = $shareClass['current_match'] ?? null;
        if (!is_array($match)) {
            return 'payment_not_matched';
        }

        return abs(round((float)($match['matched_amount'] ?? 0), 2) - $expectedPaid) <= 0.01
            ? 'payment_matched'
            : 'payment_mismatch';
    }

    private function summaryStatus(array $shareClasses): string
    {
        if ($shareClasses === []) {
            return 'missing';
        }

        $hasUnpaid = false;
        $hasUnmatched = false;
        foreach ($shareClasses as $shareClass) {
            if ((float)($shareClass['unpaid_total'] ?? 0) > 0.0) {
                $hasUnpaid = true;
            }
            if ((string)($shareClass['payment_status'] ?? '') === 'payment_not_matched') {
                $hasUnmatched = true;
            }
        }

        if ($hasUnpaid) {
            return 'shares_not_paid_up';
        }

        return $hasUnmatched ? 'payment_unmatched' : 'complete';
    }

    private function classNominalTotal(array $shareClass): float
    {
        return round((int)($shareClass['quantity'] ?? 0) * (float)($shareClass['nominal_value_per_share'] ?? 0), 2);
    }

    private function classPaidTotal(array $shareClass): float
    {
        return round((int)($shareClass['quantity'] ?? 0) * (float)($shareClass['paid_value_per_share'] ?? 0), 2);
    }

    private function classUnpaidTotal(array $shareClass): float
    {
        return round((int)($shareClass['quantity'] ?? 0) * (float)($shareClass['unpaid_value_per_share'] ?? 0), 2);
    }

    private function normaliseDecimal(mixed $value): float
    {
        $normalised = (new \eel_accounts\Service\MoneyFormatService())->parseAmount($value);

        return $normalised !== null ? round($normalised, 6) : -1.0;
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    private function actorValue(string $value): string
    {
        $value = trim($value);
        return $value !== '' ? $value : 'web_app';
    }

    private function tablesAvailable(): bool
    {
        return $this->tableExists(self::SHARE_TABLE) && $this->tableExists(self::MATCH_TABLE);
    }

    private function tableExists(string $table): bool
    {
        return \InterfaceDB::tableExists($table);
    }
}
