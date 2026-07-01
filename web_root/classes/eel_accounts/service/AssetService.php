<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class AssetService
{
    private const MANUAL_ASSET_OFFSET_ACCOUNT_TYPES = ['asset', 'liability', 'equity'];
    private const DISPOSAL_CLEARING_NOMINAL_CODE = '1490';
    private const DISPOSAL_SEARCH_DAYS_BEFORE = 1;
    private const DISPOSAL_SEARCH_DAYS_AFTER = 3;

    private \eel_accounts\Service\TransactionCategorisationService $categorisationService;
    private \eel_accounts\Service\TransactionJournalService $transactionJournalService;
    private ?bool $schemaReady = null;

    public function __construct(
        ?\eel_accounts\Service\TransactionCategorisationService $categorisationService = null,
        ?\eel_accounts\Service\TransactionJournalService $transactionJournalService = null
    ) {
        $this->categorisationService = $categorisationService ?? new \eel_accounts\Service\TransactionCategorisationService();
        $this->transactionJournalService = $transactionJournalService ?? new \eel_accounts\Service\TransactionJournalService();
    }

    public static function assetCategoryOptions(): array {
        return [
            'tools_equipment' => 'Tools & Equipment',
            'plant_machinery' => 'Plant & Machinery',
            'van' => 'Van',
            'car' => 'Car',
        ];
    }

    public static function assetNominalCodesForCategory(string $category): array {
        return match ($category) {
            'tools_equipment' => ['cost' => '1300', 'accum' => '1330'],
            'plant_machinery' => ['cost' => '1310', 'accum' => '1340'],
            default => ['cost' => '1320', 'accum' => '1350'],
        };
    }

    public function normaliseAssetValues(int $companyId, array $payload, array $defaults = []): array
    {
        return $this->normaliseAssetPayload($companyId, $payload, $defaults);
    }

    public function createAssetRecordFromValues(array $values, array $links): ?array
    {
        $assetCode = trim((string)($links['asset_code'] ?? ''));
        if ($assetCode === '') {
            $assetCode = $this->generateAssetCode((int)($values['company_id'] ?? 0));
            $links['asset_code'] = $assetCode;
        }

        $this->insertAssetRecord($values, $links);

        return $this->fetchAssetByCode((int)($values['company_id'] ?? 0), $assetCode);
    }

    public function fetchPageData(
        int $companyId,
        int $accountingPeriodId,
        int|string $defaultBankNominalId = 0,
        int $prefillTransactionId = 0,
        string $disposalSearchDate = '',
        int $disposalSearchAssetId = 0
    ): array {
        $defaultBankNominalId = max(0, (int)$defaultBankNominalId);

        if (!$this->hasRequiredSchema()) {
            return [
                'assets' => [],
                'accounting_periods' => $this->fetchAccountingPeriods($companyId),
                'accounting_period_id' => $accountingPeriodId,
                'tax_view' => null,
                'prefill_transaction' => null,
                'default_bank_nominal_id' => $defaultBankNominalId,
                'asset_categories' => self::assetCategoryOptions(),
                'disposal_search' => $this->emptyDisposalSearch($disposalSearchDate, $disposalSearchAssetId),
                'schema_ready' => false,
            ];
        }

        return [
            'assets' => $this->fetchAssets($companyId),
            'accounting_periods' => $this->fetchAccountingPeriods($companyId),
            'accounting_period_id' => $accountingPeriodId,
            'tax_view' => $accountingPeriodId > 0 ? $this->fetchTaxView($companyId, $accountingPeriodId) : null,
            'prefill_transaction' => $prefillTransactionId > 0 ? $this->fetchTransactionPrefill($companyId, $prefillTransactionId) : null,
            'default_bank_nominal_id' => $defaultBankNominalId,
            'asset_categories' => self::assetCategoryOptions(),
            'disposal_search' => $this->fetchDisposalSearch($companyId, $disposalSearchDate, $disposalSearchAssetId),
            'schema_ready' => true,
        ];
    }

    public function fetchTransactionPrefill(int $companyId, int $transactionId): ?array {
        $transaction = $this->categorisationService->fetchTransaction($transactionId);
        if ($transaction === null || (int)($transaction['company_id'] ?? 0) !== $companyId) {
            return null;
        }

        return [
            'transaction_id' => (int)$transaction['id'],
            'description' => (string)$transaction['description'],
            'purchase_date' => (string)$transaction['txn_date'],
            'cost' => round(abs((float)$transaction['amount']), 2),
            'accounting_period_id' => (int)$transaction['accounting_period_id'],
        ];
    }

    public function fetchAssets(int $companyId): array {
        if (!$this->hasRequiredSchema()) {
            return [];
        }

        if ($companyId <= 0) {
            return [];
        }

        return \InterfaceDB::fetchAll( 'SELECT ar.*,
                    COALESCE(cost, 0) - COALESCE((
                        SELECT SUM(ade.amount)
                        FROM asset_depreciation_entries ade
                        WHERE ade.asset_id = ar.id
                    ), 0) AS nbv,
                    COALESCE((
                        SELECT SUM(ade.amount)
                        FROM asset_depreciation_entries ade
                        WHERE ade.asset_id = ar.id
                    ), 0) AS accumulated_depreciation,
                    na.code AS nominal_code,
                    na.name AS nominal_name
             FROM asset_register ar
             LEFT JOIN nominal_accounts na ON na.id = ar.nominal_account_id
             WHERE ar.company_id = :company_id
             ORDER BY ar.purchase_date DESC, ar.id DESC', ['company_id' => $companyId]);
    }

    public function fetchDisposalSearch(int $companyId, string $searchDate, int $assetId = 0): array {
        $searchDate = trim($searchDate);
        $search = $this->emptyDisposalSearch($searchDate, $assetId);

        if ($companyId <= 0 || !$this->hasRequiredSchema()) {
            return $search;
        }
        if ($searchDate === '') {
            return $search;
        }
        if (!$this->isIsoDate($searchDate)) {
            $search['errors'][] = 'Enter a valid disposal receipt search date.';
            return $search;
        }

        $window = $this->disposalSearchWindow($searchDate);
        $search['window_start'] = $window['start'];
        $search['window_end'] = $window['end'];
        $search['candidates'] = $this->fetchDisposalTransactionCandidates($companyId, $window['start'], $window['end']);

        return $search;
    }

    public function fetchDisposalTransactionCandidates(int $companyId, string $windowStart, string $windowEnd): array {
        if ($companyId <= 0 || !$this->hasRequiredSchema() || !$this->isIsoDate($windowStart) || !$this->isIsoDate($windowEnd)) {
            return [];
        }

        return array_map(
            static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'txn_date' => (string)$row['txn_date'],
                    'description' => (string)$row['description'],
                    'reference' => (string)($row['reference'] ?? ''),
                    'amount' => round(abs((float)$row['amount']), 2),
                    'category_status' => (string)$row['category_status'],
                    'nominal_label' => trim((string)($row['nominal_code'] ?? '')) !== ''
                        ? (string)$row['nominal_code'] . ' - ' . (string)($row['nominal_name'] ?? '')
                        : (string)($row['nominal_name'] ?? ''),
                ];
            },
            \InterfaceDB::fetchAll(
                'SELECT t.id,
                        t.txn_date,
                        t.description,
                        t.reference,
                        t.amount,
                        t.category_status,
                        n.code AS nominal_code,
                        n.name AS nominal_name
                 FROM transactions t
                 LEFT JOIN nominal_accounts n ON n.id = t.nominal_account_id
                 LEFT JOIN asset_disposal_transaction_links adtl ON adtl.transaction_id = t.id
                 WHERE t.company_id = :company_id
                   AND t.amount > 0
                   AND t.txn_date BETWEEN :window_start AND :window_end
                   AND COALESCE(t.is_internal_transfer, 0) = 0
                   AND t.transfer_account_id IS NULL
                   AND adtl.id IS NULL
                 ORDER BY t.txn_date ASC, t.id ASC
                 LIMIT 80',
                [
                    'company_id' => $companyId,
                    'window_start' => $windowStart,
                    'window_end' => $windowEnd,
                ]
            ) ?: []
        );
    }

    public function createAssetFromTransaction(int $companyId, int $transactionId, array $payload, int $defaultBankNominalId): array {
        if (!$this->hasRequiredSchema()) {
            return ['success' => false, 'errors' => ['Run the fixed asset migration before using the asset register.']];
        }

        $transaction = $this->categorisationService->fetchTransaction($transactionId);
        if ($transaction === null || (int)($transaction['company_id'] ?? 0) !== $companyId) {
            return ['success' => false, 'errors' => ['The selected transaction could not be found for this company.']];
        }
        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, (int)($transaction['accounting_period_id'] ?? 0), 'create assets from transactions in this period');

        if ($defaultBankNominalId <= 0) {
            return ['success' => false, 'errors' => ['Set the default bank nominal before creating an asset from a transaction.']];
        }

        $normalised = $this->normaliseAssetPayload($companyId, $payload, [
            'purchase_date' => (string)$transaction['txn_date'],
            'cost' => abs((float)$transaction['amount']),
            'description' => (string)$transaction['description'],
            'accounting_period_id' => (int)$transaction['accounting_period_id'],
        ]);
        if ($normalised['errors'] !== []) {
            return ['success' => false, 'errors' => $normalised['errors']];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $saveResult = $this->categorisationService->saveManualCategorisation(
                $transactionId,
                (int)$normalised['values']['nominal_account_id'],
                null,
                false,
                'asset_register',
                true
            );

            if (empty($saveResult['success'])) {
                throw new \RuntimeException(implode(' ', array_map('strval', $saveResult['errors'] ?? ['The transaction could not be categorised for asset posting.'])));
            }

            $journalResult = $this->transactionJournalService->syncJournalForTransaction(
                $transactionId,
                $defaultBankNominalId,
                'asset_register',
                true
            );
            if (empty($journalResult['success'])) {
                throw new \RuntimeException(implode(' ', array_map('strval', $journalResult['errors'] ?? ['The bank-derived journal could not be posted.'])));
            }

            $assetCode = $this->generateAssetCode($companyId);
            $this->insertAssetRecord($normalised['values'], [
                'asset_code' => $assetCode,
                'linked_transaction_id' => $transactionId,
                'linked_journal_id' => (int)($journalResult['journal_id'] ?? $this->findJournalIdBySourceRef($companyId, 'bank_csv', 'transaction:' . $transactionId) ?? 0),
            ]);
            $asset = $this->fetchAssetByCode($companyId, $assetCode);
            if ($asset === null) {
                throw new \RuntimeException('The asset could not be reloaded after save.');
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }

            return [
                'success' => true,
                'asset' => $asset,
                'messages' => ['Asset created from the selected transaction and linked to the derived journal.'],
            ];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['The asset could not be created: ' . $exception->getMessage()]];
        }
    }

    public function createManualAsset(int $companyId, int $accountingPeriodId, array $payload, int $offsetNominalId): array {
        if (!$this->hasRequiredSchema()) {
            return ['success' => false, 'errors' => ['Run the fixed asset migration before using the asset register.']];
        }

        if ($offsetNominalId <= 0) {
            return ['success' => false, 'errors' => ['Choose an offset nominal before posting a manual asset.']];
        }
        if (!$this->isManualAssetOffsetNominal($offsetNominalId)) {
            return ['success' => false, 'errors' => ['Choose a balance sheet nominal before posting a manual asset.']];
        }
        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'post manual assets in this period');

        $normalised = $this->normaliseAssetPayload($companyId, $payload, ['accounting_period_id' => $accountingPeriodId]);
        if ($normalised['errors'] !== []) {
            return ['success' => false, 'errors' => $normalised['errors']];
        }

        $journalDate = (string)$normalised['values']['purchase_date'];
        $resolvedAccountingPeriodId = $this->resolveAccountingPeriodIdForDate($companyId, $journalDate);
        if ($resolvedAccountingPeriodId <= 0) {
            return ['success' => false, 'errors' => ['No accounting period exists for the chosen purchase date.']];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $assetCode = $this->generateAssetCode($companyId);
            $journalId = $this->insertJournal([
                'company_id' => $companyId,
                'accounting_period_id' => $resolvedAccountingPeriodId,
                'source_type' => 'asset_register',
                'source_ref' => 'asset:' . $assetCode . ':opening',
                'journal_date' => $journalDate,
                'description' => 'Asset purchase ' . (string)$normalised['values']['description'],
            ]);
            $cost = (float)$normalised['values']['cost'];
            $description = (string)$normalised['values']['description'];

            $this->insertJournalLine($journalId, (int)$normalised['values']['nominal_account_id'], $cost, 0.0, $description);
            $this->insertJournalLine($journalId, $offsetNominalId, 0.0, $cost, 'Funding / settlement');

            $this->insertAssetRecord($normalised['values'], [
                'asset_code' => $assetCode,
                'linked_transaction_id' => null,
                'linked_journal_id' => $journalId,
            ]);
            $asset = $this->fetchAssetByCode($companyId, $assetCode);
            if ($asset === null) {
                throw new \RuntimeException('The asset could not be reloaded after save.');
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }

            return ['success' => true, 'asset' => $asset, 'messages' => ['Manual asset posted and added to the register.']];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['The manual asset could not be posted: ' . $exception->getMessage()]];
        }
    }

    public function runDepreciation(int $companyId, int $accountingPeriodId): array {
        if (!$this->hasRequiredSchema()) {
            return ['success' => false, 'errors' => ['Run the fixed asset migration before posting depreciation.']];
        }

        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return ['success' => false, 'errors' => ['The selected accounting period could not be found.']];
        }
        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'post depreciation in this period');

        $assets = $this->fetchDepreciableAssets($companyId, (string)$accountingPeriod['period_start'], (string)$accountingPeriod['period_end']);
        $summary = ['success' => true, 'created' => 0, 'skipped' => 0, 'errors' => []];
        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            foreach ($assets as $asset) {
                $periodStart = max((string)$accountingPeriod['period_start'], (string)$asset['purchase_date']);
                $periodEnd = (string)$accountingPeriod['period_end'];
                if ((string)($asset['status'] ?? 'active') === 'disposed' && trim((string)($asset['disposal_date'] ?? '')) !== '') {
                    $periodEnd = min($periodEnd, (string)$asset['disposal_date']);
                }

                if ($periodEnd < $periodStart) {
                    $summary['skipped']++;
                    continue;
                }

                if ($this->depreciationEntryExists((int)$asset['id'], $accountingPeriodId, $periodStart, $periodEnd)) {
                    $summary['skipped']++;
                    continue;
                }

                $amount = $this->calculateDepreciationAmount($asset, $periodStart, $periodEnd);
                if ($amount <= 0) {
                    $summary['skipped']++;
                    continue;
                }

                $journalId = $this->insertJournal([
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'source_type' => 'asset_depreciation',
                    'source_ref' => 'asset:' . (int)$asset['id'] . ':depreciation:' . $accountingPeriodId . ':' . $periodStart . ':' . $periodEnd,
                    'journal_date' => $periodEnd,
                    'description' => 'Depreciation ' . (string)$asset['asset_code'],
                ]);
                $lineDescription = 'Depreciation ' . (string)$asset['asset_code'] . ' ' . $periodStart . ' to ' . $periodEnd;
                $this->insertJournalLine($journalId, $this->findNominalIdByCode('6200'), $amount, 0.0, $lineDescription);
                $this->insertJournalLine($journalId, (int)$asset['accum_dep_nominal_id'], 0.0, $amount, $lineDescription);

                $stmt = \InterfaceDB::prepare(
                    'INSERT INTO asset_depreciation_entries (
                        asset_id,
                        accounting_period_id,
                        period_start,
                        period_end,
                        amount,
                        journal_id,
                        created_at
                    ) VALUES (
                        :asset_id,
                        :accounting_period_id,
                        :period_start,
                        :period_end,
                        :amount,
                        :journal_id,
                        CURRENT_TIMESTAMP
                    )'
                );
                $stmt->execute([
                    'asset_id' => (int)$asset['id'],
                    'accounting_period_id' => $accountingPeriodId,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'amount' => $amount,
                    'journal_id' => $journalId,
                ]);

                $summary['created']++;
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['Depreciation could not be posted: ' . $exception->getMessage()]];
        }

        $this->refreshDerivedTaxData($companyId);

        return $summary;
    }

    public function disposeAsset(int $companyId, int $assetId, string $disposalDate, float $proceeds, int $bankNominalId): array {
        if (round($proceeds, 2) > 0.0) {
            return ['success' => false, 'errors' => ['Select a receipt transaction before disposing an asset with proceeds.']];
        }

        return $this->disposeAssetAtNilValue($companyId, $assetId, $disposalDate);
    }

    public function disposeAssetAtNilValue(int $companyId, int $assetId, string $disposalDate): array {
        if (!$this->hasRequiredSchema()) {
            return ['success' => false, 'errors' => ['Run the fixed asset migration before posting disposals.']];
        }

        $asset = $this->fetchAsset($companyId, $assetId);
        $validation = $this->validateDisposalAssetAndDate($asset, $disposalDate);
        if ($validation !== []) {
            return ['success' => false, 'errors' => $validation];
        }

        $accountingPeriodId = $this->resolveAccountingPeriodIdForDate($companyId, $disposalDate);
        if ($accountingPeriodId <= 0) {
            return ['success' => false, 'errors' => ['No accounting period exists for the disposal date.']];
        }
        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'dispose assets in this period');

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $summary = $this->postAssetDisposalJournalAndStatus($companyId, $asset, $accountingPeriodId, $disposalDate, 0.0, null);

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['The asset disposal could not be posted: ' . $exception->getMessage()]];
        }

        $this->refreshDerivedTaxData($companyId);

        return $this->disposalSuccessResponse($summary);
    }

    public function disposeAssetWithTransaction(int $companyId, int $assetId, int $transactionId, int $defaultBankNominalId): array {
        if (!$this->hasRequiredSchema()) {
            return ['success' => false, 'errors' => ['Run the fixed asset migration before posting disposals.']];
        }
        if ($defaultBankNominalId <= 0) {
            return ['success' => false, 'errors' => ['Set the default bank nominal before posting a disposal receipt.']];
        }

        $asset = $this->fetchAsset($companyId, $assetId);
        $transaction = $this->categorisationService->fetchTransaction($transactionId);
        $transactionValidation = $this->validateDisposalTransaction($companyId, $transaction);
        if ($asset === null) {
            return ['success' => false, 'errors' => ['The selected asset could not be found.']];
        }
        if ($transactionValidation !== []) {
            return ['success' => false, 'errors' => $transactionValidation];
        }

        $disposalDate = (string)$transaction['txn_date'];
        $validation = $this->validateDisposalAssetAndDate($asset, $disposalDate);
        if ($validation !== []) {
            return ['success' => false, 'errors' => $validation];
        }

        $accountingPeriodId = $this->resolveAccountingPeriodIdForDate($companyId, $disposalDate);
        if ($accountingPeriodId <= 0) {
            return ['success' => false, 'errors' => ['No accounting period exists for the receipt transaction date.']];
        }

        $clearingNominalId = $this->findNominalIdByCode(self::DISPOSAL_CLEARING_NOMINAL_CODE);
        if ($clearingNominalId <= 0) {
            return ['success' => false, 'errors' => ['The asset disposal clearing nominal is missing. Run the asset disposal migration.']];
        }

        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'dispose assets in this period');

        $proceeds = round(abs((float)$transaction['amount']), 2);
        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $saveResult = $this->categorisationService->saveManualCategorisation(
                $transactionId,
                $clearingNominalId,
                null,
                false,
                'asset_disposal',
                true
            );
            if (empty($saveResult['success'])) {
                throw new \RuntimeException(implode(' ', array_map('strval', $saveResult['errors'] ?? ['The receipt transaction could not be categorised.'])));
            }

            $journalResult = $this->transactionJournalService->syncJournalForTransaction(
                $transactionId,
                $defaultBankNominalId,
                'asset_disposal',
                true
            );
            if (empty($journalResult['success'])) {
                throw new \RuntimeException(implode(' ', array_map('strval', $journalResult['errors'] ?? ['The receipt transaction journal could not be posted.'])));
            }

            $summary = $this->postAssetDisposalJournalAndStatus($companyId, $asset, $accountingPeriodId, $disposalDate, $proceeds, $clearingNominalId);
            $this->insertDisposalTransactionLink($assetId, $transactionId, $proceeds);

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['The asset disposal could not be posted: ' . $exception->getMessage()]];
        }

        $this->refreshDerivedTaxData($companyId);

        return $this->disposalSuccessResponse($summary);
    }

    public function fetchTaxView(int $companyId, int $accountingPeriodId): ?array {
        if (!$this->hasRequiredSchema()) {
            return null;
        }

        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return null;
        }

        $metrics = $this->refreshDerivedTaxData($companyId);
        return $metrics[$accountingPeriodId] ?? [
            'accounting_period' => $accountingPeriod,
            'accounting_profit' => 0.0,
            'depreciation_add_back' => 0.0,
            'capital_allowances' => 0.0,
            'taxable_before_losses' => 0.0,
            'losses_brought_forward' => 0.0,
            'losses_used' => 0.0,
            'losses_carried_forward' => 0.0,
            'taxable_profit' => 0.0,
        ];
    }

    private function emptyDisposalSearch(string $searchDate, int $assetId): array
    {
        $searchDate = trim($searchDate) !== '' ? trim($searchDate) : (new \DateTimeImmutable('now'))->format('Y-m-d');
        $window = $this->isIsoDate($searchDate)
            ? $this->disposalSearchWindow($searchDate)
            : ['start' => '', 'end' => ''];

        return [
            'asset_id' => max(0, $assetId),
            'search_date' => $searchDate,
            'window_start' => $window['start'],
            'window_end' => $window['end'],
            'candidates' => [],
            'errors' => [],
        ];
    }

    private function disposalSearchWindow(string $searchDate): array
    {
        $date = new \DateTimeImmutable($searchDate);

        return [
            'start' => $date->modify('-' . self::DISPOSAL_SEARCH_DAYS_BEFORE . ' day')->format('Y-m-d'),
            'end' => $date->modify('+' . self::DISPOSAL_SEARCH_DAYS_AFTER . ' days')->format('Y-m-d'),
        ];
    }

    private function validateDisposalAssetAndDate(?array $asset, string $disposalDate): array
    {
        if ($asset === null) {
            return ['The selected asset could not be found.'];
        }
        if ((string)($asset['status'] ?? 'active') === 'disposed') {
            return ['This asset has already been disposed.'];
        }
        if (!$this->isIsoDate($disposalDate)) {
            return ['Enter a valid disposal date.'];
        }

        return [];
    }

    private function validateDisposalTransaction(int $companyId, ?array $transaction): array
    {
        if ($transaction === null || (int)($transaction['company_id'] ?? 0) !== $companyId) {
            return ['The selected receipt transaction could not be found for this company.'];
        }
        if (round((float)($transaction['amount'] ?? 0), 2) <= 0.0) {
            return ['Select an incoming receipt transaction for disposal proceeds.'];
        }
        if ((int)($transaction['is_internal_transfer'] ?? 0) === 1 || (int)($transaction['transfer_account_id'] ?? 0) > 0) {
            return ['Transfer rows cannot be used as asset disposal proceeds.'];
        }
        if ($this->transactionHasAssetDisposalLink((int)$transaction['id'])) {
            return ['This receipt transaction is already linked to an asset disposal.'];
        }

        return [];
    }

    private function postAssetDisposalJournalAndStatus(
        int $companyId,
        array $asset,
        int $accountingPeriodId,
        string $disposalDate,
        float $proceeds,
        ?int $clearingNominalId
    ): array {
        $assetId = (int)$asset['id'];
        $proceeds = round(max(0.0, $proceeds), 2);
        $accumulatedDepreciation = $this->sumDepreciationToDate($assetId, $disposalDate);
        $nbv = round(max(0.0, (float)$asset['cost'] - $accumulatedDepreciation), 2);
        $profit = round($proceeds - $nbv, 2);

        $journalId = $this->insertJournal([
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'asset_disposal',
            'source_ref' => 'asset:' . $assetId . ':disposal',
            'journal_date' => $disposalDate,
            'description' => 'Asset disposal ' . (string)$asset['asset_code'],
        ]);

        if ($proceeds > 0) {
            if ($clearingNominalId === null || $clearingNominalId <= 0) {
                throw new \RuntimeException('The asset disposal clearing nominal is missing.');
            }
            $this->insertJournalLine($journalId, $clearingNominalId, $proceeds, 0.0, 'Clear disposal proceeds');
        }
        if ($accumulatedDepreciation > 0) {
            $this->insertJournalLine($journalId, (int)$asset['accum_dep_nominal_id'], $accumulatedDepreciation, 0.0, 'Remove accumulated depreciation');
        }
        if ($profit < 0) {
            $this->insertJournalLine($journalId, $this->findNominalIdByCode('6210'), abs($profit), 0.0, 'Loss on disposal');
        }

        $this->insertJournalLine($journalId, (int)$asset['nominal_account_id'], 0.0, (float)$asset['cost'], 'Derecognise asset cost');

        if ($profit > 0) {
            $this->insertJournalLine($journalId, $this->findNominalIdByCode('4200'), 0.0, $profit, 'Profit on disposal');
        }

        $this->markAssetDisposed($companyId, $assetId, $disposalDate, $proceeds);

        return [
            'nbv' => $nbv,
            'proceeds' => $proceeds,
            'profit' => $profit,
        ];
    }

    private function markAssetDisposed(int $companyId, int $assetId, string $disposalDate, float $proceeds): void
    {
        $stmt = \InterfaceDB::prepare(
            'UPDATE asset_register
             SET status = :status,
                 disposal_date = :disposal_date,
                 disposal_proceeds = :disposal_proceeds,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'status' => 'disposed',
            'disposal_date' => $disposalDate,
            'disposal_proceeds' => round($proceeds, 2),
            'id' => $assetId,
            'company_id' => $companyId,
        ]);
    }

    private function insertDisposalTransactionLink(int $assetId, int $transactionId, float $amount): void
    {
        $stmt = \InterfaceDB::prepare(
            'INSERT INTO asset_disposal_transaction_links (
                asset_id,
                transaction_id,
                linked_amount,
                created_at
             ) VALUES (
                :asset_id,
                :transaction_id,
                :linked_amount,
                CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'asset_id' => $assetId,
            'transaction_id' => $transactionId,
            'linked_amount' => round($amount, 2),
        ]);
    }

    private function transactionHasAssetDisposalLink(int $transactionId): bool
    {
        if ($transactionId <= 0 || !\InterfaceDB::tableExists('asset_disposal_transaction_links')) {
            return false;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT EXISTS(
                SELECT 1
                FROM asset_disposal_transaction_links
                WHERE transaction_id = :transaction_id
            )',
            ['transaction_id' => $transactionId]
        ) === 1;
    }

    private function disposalSuccessResponse(array $summary): array
    {
        $profit = round((float)($summary['profit'] ?? 0), 2);

        return [
            'success' => true,
            'messages' => [
                sprintf(
                    'Asset disposed. Net book value %s, proceeds %s, %s %s.',
                    number_format((float)($summary['nbv'] ?? 0), 2, '.', ''),
                    number_format((float)($summary['proceeds'] ?? 0), 2, '.', ''),
                    $profit >= 0 ? 'profit' : 'loss',
                    number_format(abs($profit), 2, '.', '')
                ),
            ],
        ];
    }

    private function refreshDerivedTaxData(int $companyId): array {
        $accountingPeriods = $this->fetchAccountingPeriods($companyId);
        if ($accountingPeriods === []) {
            return [];
        }

        $this->deleteDerivedTaxRows($companyId);
        $metrics = [];
        $lossPool = [];

        foreach (array_reverse($accountingPeriods) as $accountingPeriod) {
            $accountingPeriodId = (int)$accountingPeriod['id'];
            $depreciationByAsset = $this->fetchDepreciationByAsset($companyId, $accountingPeriodId);
            $allowancesByAsset = $this->calculateCapitalAllowancesByAsset($companyId, $accountingPeriod);

            foreach ($depreciationByAsset as $assetId => $amount) {
                $this->insertAccountingPeriodAdjustment($companyId, $accountingPeriodId, 'add_back_depreciation', 'add', $amount, $assetId);
            }
            foreach ($allowancesByAsset as $assetId => $amount) {
                $this->insertAccountingPeriodAdjustment($companyId, $accountingPeriodId, 'capital_allowances', 'deduct', $amount, $assetId);
            }

            $accountingProfit = $this->calculateAccountingProfit($companyId, $accountingPeriodId);
            $depreciationAddBack = round(array_sum($depreciationByAsset), 2);
            $capitalAllowances = round(array_sum($allowancesByAsset), 2);
            $taxableBeforeLosses = round($accountingProfit + $depreciationAddBack - $capitalAllowances, 2);
            $lossesBf = round(array_sum(array_column($lossPool, 'remaining')), 2);
            $lossesUsed = 0.0;

            if ($taxableBeforeLosses > 0 && $lossesBf > 0) {
                $remainingTaxable = $taxableBeforeLosses;
                foreach ($lossPool as &$lossRow) {
                    if ($remainingTaxable <= 0) {
                        break;
                    }
                    $usage = min($lossRow['remaining'], $remainingTaxable);
                    $lossRow['remaining'] = round($lossRow['remaining'] - $usage, 2);
                    $lossRow['used'] = round($lossRow['used'] + $usage, 2);
                    $remainingTaxable = round($remainingTaxable - $usage, 2);
                    $lossesUsed = round($lossesUsed + $usage, 2);
                }
                unset($lossRow);
            }

            if ($taxableBeforeLosses < 0) {
                $lossPool[] = [
                    'origin_accounting_period_id' => $accountingPeriodId,
                    'originated' => abs($taxableBeforeLosses),
                    'used' => 0.0,
                    'remaining' => abs($taxableBeforeLosses),
                ];
            }

            $lossesCf = round(array_sum(array_column($lossPool, 'remaining')), 2);
            $metrics[$accountingPeriodId] = [
                'accounting_period' => $accountingPeriod,
                'accounting_profit' => round($accountingProfit, 2),
                'depreciation_add_back' => $depreciationAddBack,
                'capital_allowances' => $capitalAllowances,
                'taxable_before_losses' => $taxableBeforeLosses,
                'losses_brought_forward' => $lossesBf,
                'losses_used' => $lossesUsed,
                'losses_carried_forward' => $lossesCf,
                'taxable_profit' => max(0.0, round($taxableBeforeLosses - $lossesUsed, 2)),
            ];
        }

        foreach ($lossPool as $lossRow) {
            $stmt = \InterfaceDB::prepare(
                'INSERT INTO tax_loss_carryforwards (
                    company_id,
                    origin_accounting_period_id,
                    amount_originated,
                    amount_used,
                    amount_remaining,
                    status,
                    created_at,
                    updated_at
                 ) VALUES (
                    :company_id,
                    :origin_accounting_period_id,
                    :amount_originated,
                    :amount_used,
                    :amount_remaining,
                    :status,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                 )'
            );
            $stmt->execute([
                'company_id' => $companyId,
                'origin_accounting_period_id' => $lossRow['origin_accounting_period_id'],
                'amount_originated' => round($lossRow['originated'], 2),
                'amount_used' => round($lossRow['used'], 2),
                'amount_remaining' => round($lossRow['remaining'], 2),
                'status' => $lossRow['remaining'] > 0 ? 'open' : 'used',
            ]);
        }

        return $metrics;
    }

    private function deleteDerivedTaxRows(int $companyId): void {
        \InterfaceDB::prepare('DELETE FROM accounting_period_adjustments WHERE company_id = :company_id')
            ->execute(['company_id' => $companyId]);
        \InterfaceDB::prepare('DELETE FROM tax_loss_carryforwards WHERE company_id = :company_id')
            ->execute(['company_id' => $companyId]);
    }

    private function fetchDepreciationByAsset(int $companyId, int $accountingPeriodId): array {
        $stmt = \InterfaceDB::prepare(
            'SELECT ar.id AS asset_id, COALESCE(SUM(ade.amount), 0) AS amount
             FROM asset_register ar
             INNER JOIN asset_depreciation_entries ade ON ade.asset_id = ar.id
             WHERE ar.company_id = :company_id
               AND ade.accounting_period_id = :accounting_period_id
             GROUP BY ar.id'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]);

        $rows = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $rows[(int)$row['asset_id']] = round((float)$row['amount'], 2);
        }

        return $rows;
    }

    private function calculateCapitalAllowancesByAsset(int $companyId, array $accountingPeriod): array {
        $stmt = \InterfaceDB::prepare(
            'SELECT id, category, cost
             FROM asset_register
             WHERE company_id = :company_id
               AND purchase_date BETWEEN :period_start AND :period_end'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'period_start' => (string)$accountingPeriod['period_start'],
            'period_end' => (string)$accountingPeriod['period_end'],
        ]);

        $allowances = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $category = (string)($row['category'] ?? '');
            $amount = match ($category) {
                'tools_equipment', 'plant_machinery', 'van' => round((float)$row['cost'], 2),
                default => 0.0,
            };

            if ($amount > 0) {
                $allowances[(int)$row['id']] = $amount;
            }
        }

        return $allowances;
    }

    private function calculateAccountingProfit(int $companyId, int $accountingPeriodId): float {
        $stmt = \InterfaceDB::prepare(
            'SELECT na.account_type,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
             GROUP BY na.account_type'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]);

        $profit = 0.0;
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $type = (string)($row['account_type'] ?? '');
            $debit = (float)($row['total_debit'] ?? 0);
            $credit = (float)($row['total_credit'] ?? 0);
            if ($type === 'income') {
                $profit += $credit - $debit;
            } elseif (in_array($type, ['expense', 'cost_of_sales'], true)) {
                $profit -= ($debit - $credit);
            }
        }

        return round($profit, 2);
    }

    private function insertAccountingPeriodAdjustment(int $companyId, int $accountingPeriodId, string $type, string $direction, float $amount, int $assetId): void {
        $stmt = \InterfaceDB::prepare(
            'INSERT INTO accounting_period_adjustments (
                company_id,
                accounting_period_id,
                type,
                direction,
                amount,
                source_asset_id,
                created_at,
                updated_at
             ) VALUES (
                :company_id,
                :accounting_period_id,
                :type,
                :direction,
                :amount,
                :source_asset_id,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'type' => $type,
            'direction' => $direction,
            'amount' => round($amount, 2),
            'source_asset_id' => $assetId,
        ]);
    }

    private function fetchDepreciableAssets(int $companyId, string $periodStart, string $periodEnd): array {
        $stmt = \InterfaceDB::prepare(
            'SELECT *
             FROM asset_register
             WHERE company_id = :company_id
               AND status IN (\'active\', \'disposed\')
               AND depreciation_method <> \'none\'
               AND purchase_date <= :period_end
             ORDER BY purchase_date ASC, id ASC'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'period_end' => $periodEnd,
        ]);
        return $stmt->fetchAll() ?: [];
    }

    private function depreciationEntryExists(int $assetId, int $accountingPeriodId, string $periodStart, string $periodEnd): bool {
        return \InterfaceDB::countWhere('asset_depreciation_entries', [
            'asset_id' => $assetId,
            'accounting_period_id' => $accountingPeriodId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]) > 0;
    }

    private function calculateDepreciationAmount(array $asset, string $periodStart, string $periodEnd): float {
        $method = (string)($asset['depreciation_method'] ?? 'straight_line');
        if ($method === 'none') {
            return 0.0;
        }

        $cost = round((float)($asset['cost'] ?? 0), 2);
        $residual = round((float)($asset['residual_value'] ?? 0), 2);
        $lifeYears = max(1, (int)($asset['useful_life_years'] ?? 1));
        $openingDepreciation = $this->sumDepreciationToDate((int)$asset['id'], (new \DateTimeImmutable($periodStart))->modify('-1 day')->format('Y-m-d'));

        $daysInPeriod = max(1, $this->dateDiffDaysInclusive($periodStart, $periodEnd));
        $yearDays = max(365, $this->dateDiffDaysInclusive(
            (new \DateTimeImmutable($periodStart))->format('Y-01-01'),
            (new \DateTimeImmutable($periodStart))->format('Y-12-31')
        ));

        if ($method === 'reducing_balance') {
            $openingNbv = max($residual, $cost - $openingDepreciation);
            $annualAmount = $openingNbv * (1 / $lifeYears);
        } else {
            $annualAmount = max(0.0, ($cost - $residual) / $lifeYears);
        }

        $remainingCap = max(0.0, ($cost - $residual) - $openingDepreciation);
        return round(min($remainingCap, $annualAmount * ($daysInPeriod / $yearDays)), 2);
    }

    private function sumDepreciationToDate(int $assetId, string $toDate): float {
        $stmt = \InterfaceDB::prepare(
            'SELECT COALESCE(SUM(amount), 0)
             FROM asset_depreciation_entries
             WHERE asset_id = :asset_id
               AND period_end <= :to_date'
        );
        $stmt->execute([
            'asset_id' => $assetId,
            'to_date' => $toDate,
        ]);
        return round((float)$stmt->fetchColumn(), 2);
    }

    private function normaliseAssetPayload(int $companyId, array $payload, array $defaults = []): array {
        $description = trim((string)($payload['description'] ?? $defaults['description'] ?? ''));
        $category = trim((string)($payload['category'] ?? 'tools_equipment'));
        $purchaseDate = trim((string)($payload['purchase_date'] ?? $defaults['purchase_date'] ?? ''));
        $cost = round((float)($payload['cost'] ?? $defaults['cost'] ?? 0), 2);
        $lifeYears = (int)($payload['useful_life_years'] ?? 3);
        $method = trim((string)($payload['depreciation_method'] ?? 'straight_line'));
        $residualValue = round((float)($payload['residual_value'] ?? 0), 2);
        $accountingPeriodId = (int)($payload['accounting_period_id'] ?? $defaults['accounting_period_id'] ?? 0);
        $status = trim((string)($payload['status'] ?? 'active'));
        $errors = [];

        if ($description === '') {
            $errors[] = 'Enter an asset description.';
        }
        if (!array_key_exists($category, self::assetCategoryOptions())) {
            $errors[] = 'Choose a valid asset category.';
        }
        if (!$this->isIsoDate($purchaseDate)) {
            $errors[] = 'Enter a valid purchase date.';
        }
        if ($cost <= 0) {
            $errors[] = 'Asset cost must be greater than zero.';
        }
        if (!in_array($method, ['straight_line', 'reducing_balance', 'none'], true)) {
            $errors[] = 'Choose a valid depreciation method.';
        }
        if ($lifeYears <= 0) {
            $errors[] = 'Useful life must be at least one year.';
        }
        if ($residualValue < 0 || $residualValue >= $cost) {
            $errors[] = 'Residual value must be zero or less than cost.';
        }
        if ($accountingPeriodId <= 0 && $purchaseDate !== '') {
            $accountingPeriodId = $this->resolveAccountingPeriodIdForDate($companyId, $purchaseDate);
        }
        if ($accountingPeriodId <= 0) {
            $errors[] = 'No accounting period exists for the chosen purchase date.';
        }

        $nominalCodes = $this->nominalCodesForCategory($category);
        $nominalAccountId = $this->findNominalIdByCode($nominalCodes['cost']);
        $accumDepNominalId = $this->findNominalIdByCode($nominalCodes['accum']);
        if ($nominalAccountId <= 0 || $accumDepNominalId <= 0) {
            $errors[] = 'The required fixed asset nominal accounts are missing.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'description' => $description,
                'category' => $category,
                'nominal_account_id' => $nominalAccountId,
                'accum_dep_nominal_id' => $accumDepNominalId,
                'purchase_date' => $purchaseDate,
                'cost' => $cost,
                'useful_life_years' => $lifeYears,
                'depreciation_method' => $method,
                'residual_value' => $residualValue,
                'status' => $status !== '' ? $status : 'active',
            ],
        ];
    }

    private function nominalCodesForCategory(string $category): array {
        return self::assetNominalCodesForCategory($category);
    }

    private function insertAssetRecord(array $values, array $links): void {
        $stmt = \InterfaceDB::prepare(
            'INSERT INTO asset_register (
                company_id,
                asset_code,
                description,
                category,
                nominal_account_id,
                accum_dep_nominal_id,
                purchase_date,
                cost,
                useful_life_years,
                depreciation_method,
                residual_value,
                status,
                linked_journal_id,
                linked_transaction_id,
                linked_expense_claim_line_id,
                disposal_date,
                disposal_proceeds,
                created_at,
                updated_at
             ) VALUES (
                :company_id,
                :asset_code,
                :description,
                :category,
                :nominal_account_id,
                :accum_dep_nominal_id,
                :purchase_date,
                :cost,
                :useful_life_years,
                :depreciation_method,
                :residual_value,
                :status,
                :linked_journal_id,
                :linked_transaction_id,
                :linked_expense_claim_line_id,
                NULL,
                NULL,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'company_id' => $values['company_id'],
            'asset_code' => $links['asset_code'],
            'description' => $values['description'],
            'category' => $values['category'],
            'nominal_account_id' => $values['nominal_account_id'],
            'accum_dep_nominal_id' => $values['accum_dep_nominal_id'],
            'purchase_date' => $values['purchase_date'],
            'cost' => $values['cost'],
            'useful_life_years' => $values['useful_life_years'],
            'depreciation_method' => $values['depreciation_method'],
            'residual_value' => $values['residual_value'],
            'status' => $values['status'],
            'linked_journal_id' => $links['linked_journal_id'] ?: null,
            'linked_transaction_id' => $links['linked_transaction_id'] ?? null,
            'linked_expense_claim_line_id' => $links['linked_expense_claim_line_id'] ?? null,
        ]);
    }

    private function fetchAccountingPeriods(int $companyId): array {
        if ($companyId <= 0) {
            return [];
        }

        return (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriods($companyId);
    }

    private function fetchAccountingPeriod(int $companyId, int $accountingPeriodId): ?array {
        return (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
    }

    private function resolveAccountingPeriodIdForDate(int $companyId, string $date): int {
        if ($companyId <= 0 || !$this->isIsoDate($date)) {
            return 0;
        }

        $value = \InterfaceDB::fetchColumn( 'SELECT id
             FROM accounting_periods
             WHERE company_id = :company_id
               AND period_start <= :date_value
               AND period_end >= :date_value
             ORDER BY period_start DESC, id DESC
             LIMIT 1', [
            'company_id' => $companyId,
            'date_value' => $date,
        ]);
        return $value !== false ? (int)$value : 0;
    }

    private function findNominalIdByCode(string $code): int {
        $value = \InterfaceDB::fetchColumn( 'SELECT id
             FROM nominal_accounts
             WHERE code = :code
             LIMIT 1', ['code' => $code]);
        return $value !== false ? (int)$value : 0;
    }

    private function isManualAssetOffsetNominal(int $nominalAccountId): bool
    {
        $accountType = \InterfaceDB::fetchColumn(
            'SELECT account_type
             FROM nominal_accounts
             WHERE id = :id
               AND is_active = 1
             LIMIT 1',
            ['id' => $nominalAccountId]
        );

        return in_array((string)$accountType, self::MANUAL_ASSET_OFFSET_ACCOUNT_TYPES, true);
    }

    private function insertJournal(array $journal): int {
        $stmt = \InterfaceDB::prepare(
            'INSERT INTO journals (
                company_id,
                accounting_period_id,
                source_type,
                source_ref,
                journal_date,
                description,
                is_posted,
                created_at,
                updated_at
             ) VALUES (
                :company_id,
                :accounting_period_id,
                :source_type,
                :source_ref,
                :journal_date,
                :description,
                1,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'company_id' => $journal['company_id'],
            'accounting_period_id' => $journal['accounting_period_id'],
            'source_type' => $journal['source_type'],
            'source_ref' => $journal['source_ref'],
            'journal_date' => $journal['journal_date'],
            'description' => $journal['description'],
        ]);

        $journalId = $this->findJournalIdBySourceRef((int)$journal['company_id'], (string)$journal['source_type'], (string)$journal['source_ref']);
        if ($journalId === null) {
            throw new \RuntimeException('The journal could not be reloaded after insert.');
        }

        return $journalId;
    }

    private function insertJournalLine(int $journalId, int $nominalAccountId, float $debit, float $credit, string $description): void {
        $stmt = \InterfaceDB::prepare(
            'INSERT INTO journal_lines (
                journal_id,
                nominal_account_id,
                debit,
                credit,
                line_description
             ) VALUES (
                :journal_id,
                :nominal_account_id,
                :debit,
                :credit,
                :line_description
             )'
        );
        $stmt->execute([
            'journal_id' => $journalId,
            'nominal_account_id' => $nominalAccountId,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'line_description' => trim($description) !== '' ? trim($description) : null,
        ]);
    }

    private function findJournalIdBySourceRef(int $companyId, string $sourceType, string $sourceRef): ?int {
        $value = \InterfaceDB::fetchColumn( 'SELECT id
             FROM journals
             WHERE company_id = :company_id
               AND source_type = :source_type
               AND source_ref = :source_ref
             LIMIT 1', [
            'company_id' => $companyId,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
        ]);
        return $value !== false ? (int)$value : null;
    }

    private function fetchAsset(int $companyId, int $assetId): ?array {
        $row = \InterfaceDB::fetchOne( 'SELECT *
             FROM asset_register
             WHERE company_id = :company_id
               AND id = :id
             LIMIT 1', [
            'company_id' => $companyId,
            'id' => $assetId,
        ]);
        return is_array($row) ? $row : null;
    }

    private function fetchAssetByCode(int $companyId, string $assetCode): ?array {
        $row = \InterfaceDB::fetchOne( 'SELECT *
             FROM asset_register
             WHERE company_id = :company_id
               AND asset_code = :asset_code
             LIMIT 1', [
            'company_id' => $companyId,
            'asset_code' => $assetCode,
        ]);
        return is_array($row) ? $row : null;
    }

    private function generateAssetCode(int $companyId): string {
        for ($attempt = 1; $attempt <= 25; $attempt++) {
            $candidate = sprintf('FA-%d-%s-%02d', $companyId, (new \DateTimeImmutable('now'))->format('YmdHis'), $attempt);
            if ($this->fetchAssetByCode($companyId, $candidate) === null) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to generate a unique asset code.');
    }

    private function isIsoDate(string $value): bool {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    private function dateDiffDaysInclusive(string $start, string $end): int {
        $startDate = new \DateTimeImmutable($start);
        $endDate = new \DateTimeImmutable($end);
        return (int)$startDate->diff($endDate)->days + 1;
    }

    private function hasRequiredSchema(): bool {
        if ($this->schemaReady !== null) {
            return $this->schemaReady;
        }

        try {
            $this->schemaReady = \InterfaceDB::tableExists('asset_register')
                && \InterfaceDB::tableExists('asset_depreciation_entries')
                && \InterfaceDB::tableExists('asset_disposal_transaction_links')
                && \InterfaceDB::tableExists('accounting_period_adjustments')
                && \InterfaceDB::tableExists('tax_loss_carryforwards');
        } catch (\Throwable) {
            $this->schemaReady = false;
        }

        return $this->schemaReady;
    }
}


