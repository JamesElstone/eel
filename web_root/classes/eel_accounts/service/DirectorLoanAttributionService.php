<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class DirectorLoanAttributionService
{
    public function controlNominalIds(int $companyId): array
    {
        if ($companyId <= 0) {
            return ['asset' => 0, 'liability' => 0, 'all' => []];
        }

        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $asset = (int)($settings['director_loan_asset_nominal_id'] ?? 0);
        $liability = (int)($settings['director_loan_liability_nominal_id'] ?? $settings['director_loan_nominal_id'] ?? 0);
        if ($asset <= 0) {
            $asset = $this->uniqueSubtypeNominalId('director_loan_asset');
        }
        if ($liability <= 0) {
            $liability = $this->uniqueSubtypeNominalId('director_loan_liability');
        }
        $ids = array_values(array_unique(array_filter([$asset, $liability], static fn(int $id): bool => $id > 0)));

        return ['asset' => $asset, 'liability' => $liability, 'all' => $ids];
    }

    public function isDirectorLoanNominal(int $companyId, ?int $nominalAccountId): bool
    {
        $nominalAccountId = (int)$nominalAccountId;
        return $nominalAccountId > 0
            && in_array($nominalAccountId, $this->controlNominalIds($companyId)['all'], true);
    }

    public function normaliseDirectorId(int $companyId, ?int $nominalAccountId, ?int $directorId): ?int
    {
        if (!$this->isDirectorLoanNominal($companyId, $nominalAccountId)) {
            return null;
        }

        $directorId = (int)$directorId;
        if ($directorId <= 0) {
            throw new \RuntimeException('Select the director loan account for this entry.');
        }

        (new CompanyDirectorService())->requireForCompany($companyId, $directorId);
        return $directorId;
    }

    public function assignJournalLine(
        int $companyId,
        int $journalLineId,
        ?int $directorId,
        string $changedBy = 'web_app',
        string $reason = 'Director loan statement attribution.'
    ): array {
        if ($companyId <= 0 || $journalLineId <= 0) {
            return ['success' => false, 'errors' => ['Select a valid director loan entry.']];
        }

        $line = \InterfaceDB::fetchOne(
            'SELECT jl.id,
                    jl.journal_id,
                    jl.nominal_account_id,
                    jl.director_id,
                    jl.debit,
                    jl.credit,
                    COALESCE(jl.line_description, \'\') AS line_description,
                    j.company_id,
                    j.source_type,
                    COALESCE(j.source_ref, \'\') AS source_ref
             FROM journal_lines jl
             INNER JOIN journals j ON j.id = jl.journal_id
             WHERE jl.id = :journal_line_id
               AND j.company_id = :company_id
             LIMIT 1',
            ['journal_line_id' => $journalLineId, 'company_id' => $companyId]
        );
        if (!is_array($line) || !$this->isDirectorLoanNominal($companyId, (int)$line['nominal_account_id'])) {
            return ['success' => false, 'errors' => ['The selected journal line is not a Director Loan control-account entry for this company.']];
        }

        $directorId = (int)$directorId > 0 ? (int)$directorId : null;
        if ($directorId === null) {
            return ['success' => false, 'changed' => false, 'errors' => ['Select the director loan account for this entry.']];
        }
        (new CompanyDirectorService())->requireForCompany($companyId, $directorId);

        $oldDirectorId = (int)($line['director_id'] ?? 0) > 0 ? (int)$line['director_id'] : null;
        if ($oldDirectorId === $directorId) {
            return ['success' => true, 'changed' => false, 'errors' => []];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            \InterfaceDB::prepareExecute(
                'UPDATE journal_lines SET director_id = :director_id WHERE id = :id',
                ['director_id' => $directorId, 'id' => $journalLineId]
            );
            $this->recordChange(
                $companyId,
                'journal_line',
                $journalLineId,
                $oldDirectorId,
                $directorId,
                $changedBy,
                $reason
            );
            $this->propagateToSource($line, $directorId, $changedBy, $reason);

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'changed' => false, 'errors' => [$exception->getMessage()]];
        }

        return ['success' => true, 'changed' => true, 'errors' => []];
    }

    public function recordChange(
        int $companyId,
        string $sourceType,
        int $sourceId,
        ?int $oldDirectorId,
        ?int $newDirectorId,
        string $changedBy,
        string $reason
    ): void {
        $oldDirectorId = (int)$oldDirectorId > 0 ? (int)$oldDirectorId : null;
        $newDirectorId = (int)$newDirectorId > 0 ? (int)$newDirectorId : null;
        if (
            $companyId <= 0
            || $sourceId <= 0
            || $oldDirectorId === $newDirectorId
            || !$this->hasAuditSchema()
        ) {
            return;
        }

        \InterfaceDB::prepareExecute(
            'INSERT INTO director_loan_attribution_audit (
                company_id, source_type, source_id, old_director_id,
                new_director_id, changed_by, reason, changed_at
             ) VALUES (
                :company_id, :source_type, :source_id, :old_director_id,
                :new_director_id, :changed_by, :reason, CURRENT_TIMESTAMP
             )',
            [
                'company_id' => $companyId,
                'source_type' => trim($sourceType),
                'source_id' => $sourceId,
                'old_director_id' => $oldDirectorId,
                'new_director_id' => $newDirectorId,
                'changed_by' => trim($changedBy) !== '' ? trim($changedBy) : 'web_app',
                'reason' => trim($reason) !== '' ? trim($reason) : 'Director loan attribution changed.',
            ]
        );
    }

    public function mapControlNominalsIfUnambiguous(int $companyId): array
    {
        if ($companyId <= 0) {
            return ['mapped' => [], 'warnings' => ['Select a company before mapping director loan control accounts.']];
        }

        $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
        $current = $settings->all();
        $mapped = [];
        $warnings = [];
        foreach (
            [
                'director_loan_asset_nominal_id' => 'director_loan_asset',
                'director_loan_liability_nominal_id' => 'director_loan_liability',
            ] as $setting => $subtype
        ) {
            if ((int)($current[$setting] ?? 0) > 0) {
                continue;
            }

            $rows = \InterfaceDB::fetchAll(
                'SELECT na.id
                 FROM nominal_accounts na
                 INNER JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
                 WHERE nas.code = :subtype AND na.is_active = 1
                 ORDER BY na.id',
                ['subtype' => $subtype]
            );
            if (count($rows) === 1) {
                $nominalId = (int)$rows[0]['id'];
                $settings->set($setting, $nominalId, 'int');
                if ($setting === 'director_loan_liability_nominal_id') {
                    $settings->set('director_loan_nominal_id', $nominalId, 'int');
                }
                $mapped[$setting] = $nominalId;
            } elseif (count($rows) !== 1) {
                $warnings[] = 'The ' . str_replace('_', ' ', $subtype) . ' control nominal could not be mapped unambiguously.';
            }
        }
        $settings->flush();

        return ['mapped' => $mapped, 'warnings' => $warnings];
    }

    private function hasAuditSchema(): bool
    {
        try {
            return \InterfaceDB::tableExists('director_loan_attribution_audit');
        } catch (\Throwable) {
            return false;
        }
    }

    private function uniqueSubtypeNominalId(string $subtype): int
    {
        try {
            $rows = \InterfaceDB::fetchAll(
                'SELECT na.id
                 FROM nominal_accounts na
                 INNER JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
                 WHERE nas.code = :subtype AND na.is_active = 1
                 ORDER BY na.id',
                ['subtype' => $subtype]
            );
            return count($rows) === 1 ? (int)$rows[0]['id'] : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function propagateToSource(array $line, ?int $directorId, string $changedBy, string $reason): void
    {
        $companyId = (int)$line['company_id'];
        $sourceRef = trim((string)($line['source_ref'] ?? ''));
        $nominalId = (int)$line['nominal_account_id'];
        $amount = round((float)$line['debit'] + (float)$line['credit'], 2);
        $description = trim((string)($line['line_description'] ?? ''));

        if ((string)$line['source_type'] === 'bank_csv' && preg_match('/^transaction:(\d+)$/', $sourceRef, $matches) === 1) {
            $transactionId = (int)$matches[1];
            $splitRows = \InterfaceDB::fetchAll(
                'SELECT tsl.id, tsl.director_id
                 FROM transaction_splits ts
                 INNER JOIN transaction_split_lines tsl ON tsl.split_id = ts.id
                 WHERE ts.transaction_id = :transaction_id
                   AND tsl.nominal_account_id = :nominal_account_id
                   AND ROUND(COALESCE(tsl.amount, 0), 2) = :amount
                   AND (:description = \'\' OR COALESCE(tsl.description, \'\') = :description_match)',
                [
                    'transaction_id' => $transactionId,
                    'nominal_account_id' => $nominalId,
                    'amount' => number_format($amount, 2, '.', ''),
                    'description' => $description,
                    'description_match' => $description,
                ]
            );
            if (count($splitRows) === 1) {
                $row = $splitRows[0];
                \InterfaceDB::prepareExecute(
                    'UPDATE transaction_split_lines SET director_id = :director_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                    ['director_id' => $directorId, 'id' => (int)$row['id']]
                );
                $this->recordChange($companyId, 'transaction_split_line', (int)$row['id'], (int)($row['director_id'] ?? 0) ?: null, $directorId, $changedBy, $reason);
                return;
            }

            $transaction = \InterfaceDB::fetchOne(
                'SELECT id, director_id FROM transactions WHERE id = :id AND company_id = :company_id LIMIT 1',
                ['id' => $transactionId, 'company_id' => $companyId]
            );
            if (is_array($transaction)) {
                \InterfaceDB::prepareExecute(
                    'UPDATE transactions SET director_id = :director_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                    ['director_id' => $directorId, 'id' => $transactionId]
                );
                $this->recordChange($companyId, 'transaction', $transactionId, (int)($transaction['director_id'] ?? 0) ?: null, $directorId, $changedBy, $reason);
            }
            return;
        }

        if ((string)$line['source_type'] === 'expense_register') {
            $expenseRows = \InterfaceDB::fetchAll(
                'SELECT ecl.id, ecl.director_id
                 FROM expense_claims ec
                 INNER JOIN expense_claim_lines ecl ON ecl.expense_claim_id = ec.id
                 WHERE ec.company_id = :company_id
                   AND ec.posted_journal_id = :journal_id
                   AND ecl.nominal_account_id = :nominal_account_id
                   AND ROUND(ecl.amount, 2) = :amount
                   AND (:description = \'\' OR ecl.description = :description_match)',
                [
                    'company_id' => $companyId,
                    'journal_id' => (int)$line['journal_id'],
                    'nominal_account_id' => $nominalId,
                    'amount' => number_format($amount, 2, '.', ''),
                    'description' => $description,
                    'description_match' => $description,
                ]
            );
            if (count($expenseRows) === 1) {
                $row = $expenseRows[0];
                \InterfaceDB::prepareExecute(
                    'UPDATE expense_claim_lines SET director_id = :director_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                    ['director_id' => $directorId, 'id' => (int)$row['id']]
                );
                $this->recordChange($companyId, 'expense_claim_line', (int)$row['id'], (int)($row['director_id'] ?? 0) ?: null, $directorId, $changedBy, $reason);
            }
            return;
        }

        $voucher = \InterfaceDB::fetchOne(
            'SELECT id, director_id FROM dividend_vouchers WHERE company_id = :company_id AND journal_id = :journal_id LIMIT 1',
            ['company_id' => $companyId, 'journal_id' => (int)$line['journal_id']]
        );
        if (is_array($voucher)) {
            \InterfaceDB::prepareExecute(
                'UPDATE dividend_vouchers SET director_id = :director_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['director_id' => $directorId, 'id' => (int)$voucher['id']]
            );
            $this->recordChange($companyId, 'dividend_voucher', (int)$voucher['id'], (int)($voucher['director_id'] ?? 0) ?: null, $directorId, $changedBy, $reason);
        }
    }
}
