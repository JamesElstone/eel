<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class AssetService
{
    private TransactionCategorisationService $categorisationService;
    private TransactionJournalService $transactionJournalService;
    private ?bool $schemaReady = null;

    public function __construct(
        ?TransactionCategorisationService $categorisationService = null,
        ?TransactionJournalService $transactionJournalService = null
    ) {
        $this->categorisationService = $categorisationService ?? new TransactionCategorisationService();
        $this->transactionJournalService = $transactionJournalService ?? new TransactionJournalService();
    }

    public static function assetCategoryOptions(): array {
        return [
            'tools_equipment' => 'Tools & Equipment',
            'plant_machinery' => 'Plant & Machinery',
            'van' => 'Van',
            'car' => 'Car',
        ];
    }

    public function fetchPageData(int $companyId, int $taxYearId, int $defaultBankNominalId = 0, int $prefillTransactionId = 0): array {
        if (!$this->hasRequiredSchema()) {
            return [
                'assets' => [],
                'tax_years' => $this->fetchTaxYears($companyId),
                'selected_tax_year_id' => $taxYearId,
                'tax_view' => null,
                'prefill_transaction' => null,
                'default_bank_nominal_id' => $defaultBankNominalId,
                'asset_categories' => self::assetCategoryOptions(),
                'schema_ready' => false,
            ];
        }

        return [
            'assets' => $this->fetchAssets($companyId),
            'tax_years' => $this->fetchTaxYears($companyId),
            'selected_tax_year_id' => $taxYearId,
            'tax_view' => $taxYearId > 0 ? $this->fetchTaxView($companyId, $taxYearId) : null,
            'prefill_transaction' => $prefillTransactionId > 0 ? $this->fetchTransactionPrefill($companyId, $prefillTransactionId) : null,
            'default_bank_nominal_id' => $defaultBankNominalId,
            'asset_categories' => self::assetCategoryOptions(),
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
            'tax_year_id' => (int)$transaction['tax_year_id'],
        ];
    }

    public function fetchAssets(int $companyId): array {
        if (!$this->hasRequiredSchema()) {
            return [];
        }

        if ($companyId <= 0) {
            return [];
        }

        return InterfaceDB::fetchAll( 'SELECT ar.*,
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

    public function createAssetFromTransaction(int $companyId, int $transactionId, array $payload, int $defaultBankNominalId): array {
        if (!$this->hasRequiredSchema()) {
            return ['success' => false, 'errors' => ['Run the fixed asset migration before using the asset register.']];
        }

        $transaction = $this->categorisationService->fetchTransaction($transactionId);
        if ($transaction === null || (int)($transaction['company_id'] ?? 0) !== $companyId) {
            return ['success' => false, 'errors' => ['The selected transaction could not be found for this company.']];
        }
        (new YearEndLockService())->assertUnlocked($companyId, (int)($transaction['tax_year_id'] ?? 0), 'create assets from transactions in this period');

        if ($defaultBankNominalId <= 0) {
            return ['success' => false, 'errors' => ['Set the default bank nominal before creating an asset from a transaction.']];
        }

        $normalised = $this->normaliseAssetPayload($companyId, $payload, [
            'purchase_date' => (string)$transaction['txn_date'],
            'cost' => abs((float)$transaction['amount']),
            'description' => (string)$transaction['description'],
            'tax_year_id' => (int)$transaction['tax_year_id'],
        ]);
        if ($normalised['errors'] !== []) {
            return ['success' => false, 'errors' => $normalised['errors']];
        }

        $ownsTransaction = !InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            InterfaceDB::beginTransaction();
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
                throw new RuntimeException(implode(' ', array_map('strval', $saveResult['errors'] ?? ['The transaction could not be categorised for asset posting.'])));
            }

            $journalResult = $this->transactionJournalService->syncJournalForTransaction(
                $transactionId,
                $defaultBankNominalId,
                'asset_register',
                true
            );
            if (empty($journalResult['success'])) {
                throw new RuntimeException(implode(' ', array_map('strval', $journalResult['errors'] ?? ['The bank-derived journal could not be posted.'])));
            }

            $assetCode = $this->generateAssetCode($companyId);
            $this->insertAssetRecord($normalised['values'], [
                'asset_code' => $assetCode,
                'linked_transaction_id' => $transactionId,
                'linked_journal_id' => (int)($journalResult['journal_id'] ?? $this->findJournalIdBySourceRef($companyId, 'bank_csv', 'transaction:' . $transactionId) ?? 0),
            ]);
            $asset = $this->fetchAssetByCode($companyId, $assetCode);
            if ($asset === null) {
                throw new RuntimeException('The asset could not be reloaded after save.');
            }

            if ($ownsTransaction) {
                InterfaceDB::commit();
            }

            return [
                'success' => true,
                'asset' => $asset,
                'messages' => ['Asset created from the selected transaction and linked to the derived journal.'],
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['The asset could not be created: ' . $exception->getMessage()]];
        }
    }

    public function createManualAsset(int $companyId, int $taxYearId, array $payload, int $offsetNominalId): array {
        if (!$this->hasRequiredSchema()) {
            return ['success' => false, 'errors' => ['Run the fixed asset migration before using the asset register.']];
        }

        if ($offsetNominalId <= 0) {
            return ['success' => false, 'errors' => ['Choose an offset nominal before posting a manual asset.']];
        }
        (new YearEndLockService())->assertUnlocked($companyId, $taxYearId, 'post manual assets in this period');

        $normalised = $this->normaliseAssetPayload($companyId, $payload, ['tax_year_id' => $taxYearId]);
        if ($normalised['errors'] !== []) {
            return ['success' => false, 'errors' => $normalised['errors']];
        }

        $journalDate = (string)$normalised['values']['purchase_date'];
        $resolvedTaxYearId = $this->resolveTaxYearIdForDate($companyId, $journalDate);
        if ($resolvedTaxYearId <= 0) {
            return ['success' => false, 'errors' => ['No accounting period exists for the chosen purchase date.']];
        }

        $ownsTransaction = !InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            InterfaceDB::beginTransaction();
        }

        try {
            $assetCode = $this->generateAssetCode($companyId);
            $journalId = $this->insertJournal([
                'company_id' => $companyId,
                'tax_year_id' => $resolvedTaxYearId,
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
                throw new RuntimeException('The asset could not be reloaded after save.');
            }

            if ($ownsTransaction) {
                InterfaceDB::commit();
            }

            return ['success' => true, 'asset' => $asset, 'messages' => ['Manual asset posted and added to the register.']];
        } catch (Throwable $exception) {
            if ($ownsTransaction && InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['The manual asset could not be posted: ' . $exception->getMessage()]];
        }
    }

    public function runDepreciation(int $companyId, int $taxYearId): array {
        if (!$this->hasRequiredSchema()) {
            return ['success' => false, 'errors' => ['Run the fixed asset migration before posting depreciation.']];
        }

        $taxYear = $this->fetchTaxYear($companyId, $taxYearId);
        if ($taxYear === null) {
            return ['success' => false, 'errors' => ['The selected accounting period could not be found.']];
        }
        (new YearEndLockService())->assertUnlocked($companyId, $taxYearId, 'post depreciation in this period');

        $assets = $this->fetchDepreciableAssets($companyId, (string)$taxYear['period_start'], (string)$taxYear['period_end']);
        $summary = ['success' => true, 'created' => 0, 'skipped' => 0, 'errors' => []];
        $ownsTransaction = !InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            InterfaceDB::beginTransaction();
        }

        try {
            foreach ($assets as $asset) {
                $periodStart = max((string)$taxYear['period_start'], (string)$asset['purchase_date']);
                $periodEnd = (string)$taxYear['period_end'];
                if ((string)($asset['status'] ?? 'active') === 'disposed' && trim((string)($asset['disposal_date'] ?? '')) !== '') {
                    $periodEnd = min($periodEnd, (string)$asset['disposal_date']);
                }

                if ($periodEnd < $periodStart) {
                    $summary['skipped']++;
                    continue;
                }

                if ($this->depreciationEntryExists((int)$asset['id'], $taxYearId, $periodStart, $periodEnd)) {
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
                    'tax_year_id' => $taxYearId,
                    'source_type' => 'asset_depreciation',
                    'source_ref' => 'asset:' . (int)$asset['id'] . ':depreciation:' . $taxYearId . ':' . $periodStart . ':' . $periodEnd,
                    'journal_date' => $periodEnd,
                    'description' => 'Depreciation ' . (string)$asset['asset_code'],
                ]);
                $lineDescription = 'Depreciation ' . (string)$asset['asset_code'] . ' ' . $periodStart . ' to ' . $periodEnd;
                $this->insertJournalLine($journalId, $this->findNominalIdByCode('6200'), $amount, 0.0, $lineDescription);
                $this->insertJournalLine($journalId, (int)$asset['accum_dep_nominal_id'], 0.0, $amount, $lineDescription);

                $stmt = InterfaceDB::prepare(
                    'INSERT INTO asset_depreciation_entries (
                        asset_id,
                        tax_year_id,
                        period_start,
                        period_end,
                        amount,
                        journal_id,
                        created_at
                    ) VALUES (
                        :asset_id,
                        :tax_year_id,
                        :period_start,
                        :period_end,
                        :amount,
                        :journal_id,
                        CURRENT_TIMESTAMP
                    )'
                );
                $stmt->execute([
                    'asset_id' => (int)$asset['id'],
                    'tax_year_id' => $taxYearId,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'amount' => $amount,
                    'journal_id' => $journalId,
                ]);

                $summary['created']++;
            }

            if ($ownsTransaction) {
                InterfaceDB::commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['Depreciation could not be posted: ' . $exception->getMessage()]];
        }

        $this->refreshDerivedTaxData($companyId);

        return $summary;
    }

    public function disposeAsset(int $companyId, int $assetId, string $disposalDate, float $proceeds, int $bankNominalId): array {
        if (!$this->hasRequiredSchema()) {
            return ['success' => false, 'errors' => ['Run the fixed asset migration before posting disposals.']];
        }

        $asset = $this->fetchAsset($companyId, $assetId);
        if ($asset === null) {
            return ['success' => false, 'errors' => ['The selected asset could not be found.']];
        }
        if ((string)($asset['status'] ?? 'active') === 'disposed') {
            return ['success' => false, 'errors' => ['This asset has already been disposed.']];
        }
        if (!$this->isIsoDate($disposalDate)) {
            return ['success' => false, 'errors' => ['Enter a valid disposal date.']];
        }
        if ($bankNominalId <= 0) {
            return ['success' => false, 'errors' => ['Set the default bank nominal before posting a disposal.']];
        }

        $taxYearId = $this->resolveTaxYearIdForDate($companyId, $disposalDate);
        if ($taxYearId <= 0) {
            return ['success' => false, 'errors' => ['No accounting period exists for the disposal date.']];
        }
        (new YearEndLockService())->assertUnlocked($companyId, $taxYearId, 'dispose assets in this period');

        $accumulatedDepreciation = $this->sumDepreciationToDate($assetId, $disposalDate);
        $nbv = round(max(0.0, (float)$asset['cost'] - $accumulatedDepreciation), 2);
        $profit = round($proceeds - $nbv, 2);

        $ownsTransaction = !InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            InterfaceDB::beginTransaction();
        }

        try {
            $journalId = $this->insertJournal([
                'company_id' => $companyId,
                'tax_year_id' => $taxYearId,
                'source_type' => 'asset_disposal',
                'source_ref' => 'asset:' . $assetId . ':disposal',
                'journal_date' => $disposalDate,
                'description' => 'Asset disposal ' . (string)$asset['asset_code'],
            ]);

            if ($proceeds > 0) {
                $this->insertJournalLine($journalId, $bankNominalId, $proceeds, 0.0, 'Disposal proceeds');
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

            $stmt = InterfaceDB::prepare(
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

            if ($ownsTransaction) {
                InterfaceDB::commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['The asset disposal could not be posted: ' . $exception->getMessage()]];
        }

        $this->refreshDerivedTaxData($companyId);

        return [
            'success' => true,
            'messages' => [
                sprintf(
                    'Asset disposed. Net book value %s, proceeds %s, %s %s.',
                    number_format($nbv, 2, '.', ''),
                    number_format($proceeds, 2, '.', ''),
                    $profit >= 0 ? 'profit' : 'loss',
                    number_format(abs($profit), 2, '.', '')
                ),
            ],
        ];
    }

    public function fetchTaxView(int $companyId, int $taxYearId): ?array {
        if (!$this->hasRequiredSchema()) {
            return null;
        }

        $taxYear = $this->fetchTaxYear($companyId, $taxYearId);
        if ($taxYear === null) {
            return null;
        }

        $metrics = $this->refreshDerivedTaxData($companyId);
        return $metrics[$taxYearId] ?? [
            'tax_year' => $taxYear,
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

    private function refreshDerivedTaxData(int $companyId): array {
        $taxYears = $this->fetchTaxYears($companyId);
        if ($taxYears === []) {
            return [];
        }

        $this->deleteDerivedTaxRows($companyId);
        $metrics = [];
        $lossPool = [];

        foreach (array_reverse($taxYears) as $taxYear) {
            $taxYearId = (int)$taxYear['id'];
            $depreciationByAsset = $this->fetchDepreciationByAsset($companyId, $taxYearId);
            $allowancesByAsset = $this->calculateCapitalAllowancesByAsset($companyId, $taxYear);

            foreach ($depreciationByAsset as $assetId => $amount) {
                $this->insertTaxYearAdjustment($companyId, $taxYearId, 'add_back_depreciation', 'add', $amount, $assetId);
            }
            foreach ($allowancesByAsset as $assetId => $amount) {
                $this->insertTaxYearAdjustment($companyId, $taxYearId, 'capital_allowances', 'deduct', $amount, $assetId);
            }

            $accountingProfit = $this->calculateAccountingProfit($companyId, $taxYearId);
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
                    'origin_tax_year_id' => $taxYearId,
                    'originated' => abs($taxableBeforeLosses),
                    'used' => 0.0,
                    'remaining' => abs($taxableBeforeLosses),
                ];
            }

            $lossesCf = round(array_sum(array_column($lossPool, 'remaining')), 2);
            $metrics[$taxYearId] = [
                'tax_year' => $taxYear,
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
            $stmt = InterfaceDB::prepare(
                'INSERT INTO tax_loss_carryforwards (
                    company_id,
                    origin_tax_year_id,
                    amount_originated,
                    amount_used,
                    amount_remaining,
                    status,
                    created_at,
                    updated_at
                 ) VALUES (
                    :company_id,
                    :origin_tax_year_id,
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
                'origin_tax_year_id' => $lossRow['origin_tax_year_id'],
                'amount_originated' => round($lossRow['originated'], 2),
                'amount_used' => round($lossRow['used'], 2),
                'amount_remaining' => round($lossRow['remaining'], 2),
                'status' => $lossRow['remaining'] > 0 ? 'open' : 'used',
            ]);
        }

        return $metrics;
    }

    private function deleteDerivedTaxRows(int $companyId): void {
        InterfaceDB::prepare('DELETE FROM tax_year_adjustments WHERE company_id = :company_id')
            ->execute(['company_id' => $companyId]);
        InterfaceDB::prepare('DELETE FROM tax_loss_carryforwards WHERE company_id = :company_id')
            ->execute(['company_id' => $companyId]);
    }

    private function fetchDepreciationByAsset(int $companyId, int $taxYearId): array {
        $stmt = InterfaceDB::prepare(
            'SELECT ar.id AS asset_id, COALESCE(SUM(ade.amount), 0) AS amount
             FROM asset_register ar
             INNER JOIN asset_depreciation_entries ade ON ade.asset_id = ar.id
             WHERE ar.company_id = :company_id
               AND ade.tax_year_id = :tax_year_id
             GROUP BY ar.id'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
        ]);

        $rows = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $rows[(int)$row['asset_id']] = round((float)$row['amount'], 2);
        }

        return $rows;
    }

    private function calculateCapitalAllowancesByAsset(int $companyId, array $taxYear): array {
        $stmt = InterfaceDB::prepare(
            'SELECT id, category, cost
             FROM asset_register
             WHERE company_id = :company_id
               AND purchase_date BETWEEN :period_start AND :period_end'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'period_start' => (string)$taxYear['period_start'],
            'period_end' => (string)$taxYear['period_end'],
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

    private function calculateAccountingProfit(int $companyId, int $taxYearId): float {
        $stmt = InterfaceDB::prepare(
            'SELECT na.account_type,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             WHERE j.company_id = :company_id
               AND j.tax_year_id = :tax_year_id
               AND j.is_posted = 1
             GROUP BY na.account_type'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
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

    private function insertTaxYearAdjustment(int $companyId, int $taxYearId, string $type, string $direction, float $amount, int $assetId): void {
        $stmt = InterfaceDB::prepare(
            'INSERT INTO tax_year_adjustments (
                company_id,
                tax_year_id,
                type,
                direction,
                amount,
                source_asset_id,
                created_at,
                updated_at
             ) VALUES (
                :company_id,
                :tax_year_id,
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
            'tax_year_id' => $taxYearId,
            'type' => $type,
            'direction' => $direction,
            'amount' => round($amount, 2),
            'source_asset_id' => $assetId,
        ]);
    }

    private function fetchDepreciableAssets(int $companyId, string $periodStart, string $periodEnd): array {
        $stmt = InterfaceDB::prepare(
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

    private function depreciationEntryExists(int $assetId, int $taxYearId, string $periodStart, string $periodEnd): bool {
        return InterfaceDB::countWhere('asset_depreciation_entries', [
            'asset_id' => $assetId,
            'tax_year_id' => $taxYearId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]) > 0;
    }

    private function calculateDepreciationAmount(array $asset, string $periodStart, string $periodEnd): float {
        $method = (string)($asset['depreciation_method'] ?? 'straight_line');
        $cost = round((float)($asset['cost'] ?? 0), 2);
        $residual = round((float)($asset['residual_value'] ?? 0), 2);
        $lifeYears = max(1, (int)($asset['useful_life_years'] ?? 1));
        $openingDepreciation = $this->sumDepreciationToDate((int)$asset['id'], (new DateTimeImmutable($periodStart))->modify('-1 day')->format('Y-m-d'));

        $daysInPeriod = max(1, $this->dateDiffDaysInclusive($periodStart, $periodEnd));
        $yearDays = max(365, $this->dateDiffDaysInclusive(
            (new DateTimeImmutable($periodStart))->format('Y-01-01'),
            (new DateTimeImmutable($periodStart))->format('Y-12-31')
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
        $stmt = InterfaceDB::prepare(
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
        $taxYearId = (int)($payload['tax_year_id'] ?? $defaults['tax_year_id'] ?? 0);
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
        if ($taxYearId <= 0 && $purchaseDate !== '') {
            $taxYearId = $this->resolveTaxYearIdForDate($companyId, $purchaseDate);
        }
        if ($taxYearId <= 0) {
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
                'tax_year_id' => $taxYearId,
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
        return match ($category) {
            'tools_equipment' => ['cost' => '1300', 'accum' => '1330'],
            'plant_machinery' => ['cost' => '1310', 'accum' => '1340'],
            default => ['cost' => '1320', 'accum' => '1350'],
        };
    }

    private function insertAssetRecord(array $values, array $links): void {
        $stmt = InterfaceDB::prepare(
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
            'linked_transaction_id' => $links['linked_transaction_id'],
        ]);
    }

    private function fetchTaxYears(int $companyId): array {
        if ($companyId <= 0) {
            return [];
        }

        return (new TaxYearRepository())->fetchTaxYears($companyId);
    }

    private function fetchTaxYear(int $companyId, int $taxYearId): ?array {
        return (new TaxYearRepository())->fetchTaxYear($companyId, $taxYearId);
    }

    private function resolveTaxYearIdForDate(int $companyId, string $date): int {
        if ($companyId <= 0 || !$this->isIsoDate($date)) {
            return 0;
        }

        $value = InterfaceDB::fetchColumn( 'SELECT id
             FROM tax_years
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
        $value = InterfaceDB::fetchColumn( 'SELECT id
             FROM nominal_accounts
             WHERE code = :code
             LIMIT 1', ['code' => $code]);
        return $value !== false ? (int)$value : 0;
    }

    private function insertJournal(array $journal): int {
        $stmt = InterfaceDB::prepare(
            'INSERT INTO journals (
                company_id,
                tax_year_id,
                source_type,
                source_ref,
                journal_date,
                description,
                is_posted,
                created_at,
                updated_at
             ) VALUES (
                :company_id,
                :tax_year_id,
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
            'tax_year_id' => $journal['tax_year_id'],
            'source_type' => $journal['source_type'],
            'source_ref' => $journal['source_ref'],
            'journal_date' => $journal['journal_date'],
            'description' => $journal['description'],
        ]);

        $journalId = $this->findJournalIdBySourceRef((int)$journal['company_id'], (string)$journal['source_type'], (string)$journal['source_ref']);
        if ($journalId === null) {
            throw new RuntimeException('The journal could not be reloaded after insert.');
        }

        return $journalId;
    }

    private function insertJournalLine(int $journalId, int $nominalAccountId, float $debit, float $credit, string $description): void {
        $stmt = InterfaceDB::prepare(
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
        $value = InterfaceDB::fetchColumn( 'SELECT id
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
        $row = InterfaceDB::fetchOne( 'SELECT *
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
        $row = InterfaceDB::fetchOne( 'SELECT *
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
            $candidate = sprintf('FA-%d-%s-%02d', $companyId, (new DateTimeImmutable('now'))->format('YmdHis'), $attempt);
            if ($this->fetchAssetByCode($companyId, $candidate) === null) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique asset code.');
    }

    private function isIsoDate(string $value): bool {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    private function dateDiffDaysInclusive(string $start, string $end): int {
        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);
        return (int)$startDate->diff($endDate)->days + 1;
    }

    private function hasRequiredSchema(): bool {
        if ($this->schemaReady !== null) {
            return $this->schemaReady;
        }

        try {
            $this->schemaReady = InterfaceDB::tableExists('asset_register')
                && InterfaceDB::tableExists('asset_depreciation_entries')
                && InterfaceDB::tableExists('tax_year_adjustments')
                && InterfaceDB::tableExists('tax_loss_carryforwards');
        } catch (Throwable) {
            $this->schemaReady = false;
        }

        return $this->schemaReady;
    }
}


