<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class ParticipatorLoanService
{
    public function controlNominalIds(int $companyId): array
    {
        $settings = $companyId > 0 ? (new \eel_accounts\Store\CompanySettingsStore($companyId))->all() : [];
        $asset = max(0, (int)($settings['participator_loan_asset_nominal_id'] ?? 0));
        $liability = max(0, (int)($settings['participator_loan_liability_nominal_id'] ?? 0));
        return ['asset' => $asset, 'liability' => $liability, 'all' => array_values(array_filter([$asset, $liability]))];
    }

    public function assignTransaction(int $companyId, int $accountingPeriodId, int $transactionId, int $partyId, string $changedBy = 'web_app'): array
    {
        $categorisation = new TransactionCategorisationService();
        $transaction = $categorisation->fetchTransaction($transactionId);
        if (!is_array($transaction)
            || (int)($transaction['company_id'] ?? 0) !== $companyId
            || (int)($transaction['accounting_period_id'] ?? 0) !== $accountingPeriodId) {
            return ['success' => false, 'errors' => ['Select a source transaction belonging to this company and accounting period.']];
        }
        if (!empty($transaction['is_internal_transfer']) || (int)($transaction['transfer_account_id'] ?? 0) > 0) {
            return ['success' => false, 'errors' => ['Transfer transactions cannot be marked as participator loans.']];
        }
        (new YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'change a participator-loan source payment');
        (new OwnershipPartyService())->requireEffectiveParty($companyId, $partyId, (string)$transaction['txn_date']);
        $controls = $this->controlNominalIds($companyId);
        $nominalId = (float)$transaction['amount'] < 0 ? (int)$controls['asset'] : (int)$controls['liability'];
        if ($nominalId <= 0) {
            return [
                'success' => false,
                'errors' => [(float)$transaction['amount'] < 0
                    ? 'Configure the Participator Loan Asset nominal first.'
                    : 'Configure the Participator Loan Liability nominal first.'],
            ];
        }
        $bankNominalId = (int)(new \eel_accounts\Store\CompanySettingsStore($companyId))->get('default_bank_nominal_id', 0);
        if ($bankNominalId <= 0) {
            return ['success' => false, 'errors' => ['Configure the default bank nominal before posting the source payment.']];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }
        try {
            $save = $categorisation->saveManualCategorisation(
                $transactionId,
                $nominalId,
                null,
                false,
                $changedBy,
                true
            );
            if (!empty($save['errors'])) {
                throw new \RuntimeException(implode(' ', array_map('strval', (array)$save['errors'])));
            }
            \InterfaceDB::prepareExecute(
                'UPDATE transactions SET party_id = :party_id, director_id = NULL WHERE id = :id AND company_id = :company_id',
                ['party_id' => $partyId, 'id' => $transactionId, 'company_id' => $companyId]
            );
            $journal = (new TransactionJournalService())->syncJournalForTransaction(
                $transactionId,
                $bankNominalId,
                $changedBy,
                true
            );
            if (!empty($journal['errors'])) {
                throw new \RuntimeException(implode(' ', array_map('strval', (array)$journal['errors'])));
            }
            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
            return ['success' => true, 'errors' => [], 'transaction_id' => $transactionId, 'party_id' => $partyId];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }
}
