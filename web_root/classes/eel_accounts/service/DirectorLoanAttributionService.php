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
        $asset = (int)($settings['participator_loan_asset_nominal_id'] ?? 0);
        $liability = (int)($settings['participator_loan_liability_nominal_id'] ?? 0);
        if ($asset <= 0) {
            $asset = $this->uniqueSubtypeNominalId('participator_loan_asset');
        }
        if ($liability <= 0) {
            $liability = $this->uniqueSubtypeNominalId('participator_loan_liability');
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

    public function normalisePartyId(int $companyId, ?int $nominalAccountId, ?int $partyId, string $date): ?int
    {
        if (!$this->isDirectorLoanNominal($companyId, $nominalAccountId)) {
            return null;
        }

        $partyId = (int)$partyId;
        if ($partyId <= 0) {
            throw new \RuntimeException('Select the participator loan account for this entry.');
        }
        (new OwnershipPartyService())->requireEffectiveParty($companyId, $partyId, $date);
        return $partyId;
    }

    public function assignJournalLine(
        int $companyId,
        int $journalLineId,
        ?int $partyId,
        string $changedBy = 'web_app',
        string $reason = 'Participator loan statement attribution.'
    ): array {
        if ($companyId <= 0 || $journalLineId <= 0) {
            return ['success' => false, 'errors' => ['Select a valid participator loan entry.']];
        }

        $line = \InterfaceDB::fetchOne(
            'SELECT jl.id,
                    jl.journal_id,
                    jl.nominal_account_id,
                    jl.party_id,
                    jl.debit,
                    jl.credit,
                    COALESCE(jl.line_description, \'\') AS line_description,
                    j.company_id,
                    j.journal_date,
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
            return ['success' => false, 'errors' => ['The selected journal line is not a Participator Loan control-account entry for this company.']];
        }

        $partyId = (int)$partyId > 0 ? (int)$partyId : null;
        if ($partyId === null) {
            return ['success' => false, 'changed' => false, 'errors' => ['Select the participator loan account for this entry.']];
        }
        (new OwnershipPartyService())->requireEffectiveParty($companyId, $partyId, (string)$line['journal_date']);

        $oldPartyId = (int)($line['party_id'] ?? 0) > 0 ? (int)$line['party_id'] : null;
        if ($oldPartyId === $partyId) {
            return ['success' => true, 'changed' => false, 'errors' => []];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            \InterfaceDB::prepareExecute(
                'UPDATE journal_lines SET party_id = :party_id WHERE id = :id',
                ['party_id' => $partyId, 'id' => $journalLineId]
            );
            $this->recordChange(
                $companyId,
                'journal_line',
                $journalLineId,
                $oldPartyId,
                $partyId,
                $changedBy,
                $reason
            );
            $this->propagateToSource($line, $partyId, $changedBy, $reason);

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
        ?int $oldPartyId,
        ?int $newPartyId,
        string $changedBy,
        string $reason
    ): void {
        $oldPartyId = (int)$oldPartyId > 0 ? (int)$oldPartyId : null;
        $newPartyId = (int)$newPartyId > 0 ? (int)$newPartyId : null;
        if (
            $companyId <= 0
            || $sourceId <= 0
            || $oldPartyId === $newPartyId
            || !$this->hasAuditSchema()
        ) {
            return;
        }

        \InterfaceDB::prepareExecute(
            'INSERT INTO participator_loan_attribution_audit (
                company_id, source_type, source_id, old_party_id,
                new_party_id, changed_by, reason, changed_at
             ) VALUES (
                :company_id, :source_type, :source_id, :old_party_id,
                :new_party_id, :changed_by, :reason, CURRENT_TIMESTAMP
             )',
            [
                'company_id' => $companyId,
                'source_type' => trim($sourceType),
                'source_id' => $sourceId,
                'old_party_id' => $oldPartyId,
                'new_party_id' => $newPartyId,
                'changed_by' => trim($changedBy) !== '' ? trim($changedBy) : 'web_app',
                'reason' => trim($reason) !== '' ? trim($reason) : 'Participator loan attribution changed.',
            ]
        );
    }

    public function mapControlNominalsIfUnambiguous(int $companyId): array
    {
        if ($companyId <= 0) {
            return ['mapped' => [], 'warnings' => ['Select a company before mapping participator loan control accounts.']];
        }

        $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
        $current = $settings->all();
        $mapped = [];
        $warnings = [];
        foreach (
            [
                'participator_loan_asset_nominal_id' => 'participator_loan_asset',
                'participator_loan_liability_nominal_id' => 'participator_loan_liability',
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
            return \InterfaceDB::tableExists('participator_loan_attribution_audit');
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

    private function propagateToSource(array $line, ?int $partyId, string $changedBy, string $reason): void
    {
        $companyId = (int)$line['company_id'];
        $sourceRef = trim((string)($line['source_ref'] ?? ''));
        $nominalId = (int)$line['nominal_account_id'];
        $amount = round((float)$line['debit'] + (float)$line['credit'], 2);
        $description = trim((string)($line['line_description'] ?? ''));

        if ((string)$line['source_type'] === 'bank_csv' && preg_match('/^transaction:(\d+)$/', $sourceRef, $matches) === 1) {
            $transactionId = (int)$matches[1];
            $splitRows = \InterfaceDB::fetchAll(
                'SELECT tsl.id
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
                    'UPDATE transactions SET party_id = :party_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                    ['party_id' => $partyId, 'id' => $transactionId]
                );
                $this->recordChange($companyId, 'transaction', $transactionId, null, $partyId, $changedBy, $reason);
                return;
            }

            $transaction = \InterfaceDB::fetchOne(
                'SELECT id, party_id FROM transactions WHERE id = :id AND company_id = :company_id LIMIT 1',
                ['id' => $transactionId, 'company_id' => $companyId]
            );
            if (is_array($transaction)) {
                \InterfaceDB::prepareExecute(
                    'UPDATE transactions SET party_id = :party_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                    ['party_id' => $partyId, 'id' => $transactionId]
                );
                $this->recordChange($companyId, 'transaction', $transactionId, (int)($transaction['party_id'] ?? 0) ?: null, $partyId, $changedBy, $reason);
            }
            return;
        }

        // Manual, expense, and dividend journals retain their source records.
        // The journal line is the party-attribution source of truth for them.
    }
}
