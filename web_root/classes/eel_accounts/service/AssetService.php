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
    private const MANUAL_ASSET_OFFSET_SUBTYPE_CODES = [
        'bank',
        'director_loan_asset',
        'director_loan_liability',
        'expense_payable',
        'trade_creditor',
    ];
    private const MANUAL_ASSET_REASON_SUPPLIER_PENDING = 'supplier_invoice_pending_payment';
    private const MANUAL_ASSET_REASON_PERSONAL_PENDING = 'personal_payment_or_expense_claim_pending';
    private const MANUAL_ASSET_REASON_DELAYED_BANK_CSV = 'delayed_bank_csv';
    private const MANUAL_ASSET_REASON_OPENING_HISTORICAL = 'opening_or_historical_asset';
    private const MANUAL_ASSET_RECONCILE_DAYS_BEFORE = 7;
    private const MANUAL_ASSET_RECONCILE_DAYS_AFTER = 45;
    private const MANUAL_ASSET_LEGAL_WARNING_VERSION = 'manual-asset-phantom-warning-2026-07-02';
    private const DISPOSAL_CLEARING_NOMINAL_CODE = '1490';
    private const DISPOSAL_EVENT_SALE_RECEIPT = 'sale_receipt';
    private const DISPOSAL_EVENT_OTHER_NIL_VALUE = 'other_nil_value';
    private const DISPOSAL_SEARCH_DAYS_BEFORE = 1;
    private const DISPOSAL_SEARCH_DAYS_AFTER = 3;
    private const POTENTIAL_ASSET_THRESHOLD_OPTIONS = [50, 100, 250, 500, 750, 1000];

    private \eel_accounts\Service\TransactionCategorisationService $categorisationService;
    private \eel_accounts\Service\TransactionJournalService $transactionJournalService;
    private ?bool $schemaReady = null;
    private ?bool $manualSchemaReady = null;

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
            'motor_vehicle' => 'Motor Vehicle',
            'van' => 'Van',
            'car' => 'Car',
        ];
    }

    public static function assetCreateCategoryOptions(): array {
        $options = self::assetCategoryOptions();
        unset($options['van'], $options['car']);

        return $options;
    }

    public static function assetNominalCodesForCategory(string $category): array {
        return match ($category) {
            'tools_equipment' => ['cost' => '1300', 'accum' => '1330'],
            'plant_machinery' => ['cost' => '1310', 'accum' => '1340'],
            'car' => ['cost' => '1321', 'accum' => '1350'],
            'van' => ['cost' => '1322', 'accum' => '1350'],
            default => ['cost' => '1320', 'accum' => '1350'],
        };
    }

    public static function manualAdditionReasonOptions(): array
    {
        return [
            self::MANUAL_ASSET_REASON_SUPPLIER_PENDING => 'Supplier invoice pending payment',
            self::MANUAL_ASSET_REASON_PERSONAL_PENDING => 'Personal payment / expense claim pending',
            self::MANUAL_ASSET_REASON_DELAYED_BANK_CSV => 'Delayed bank CSV',
            self::MANUAL_ASSET_REASON_OPENING_HISTORICAL => 'Opening / historical asset',
        ];
    }

    public static function nilDisposalEventOptions(): array
    {
        return [
            'scrapped_no_proceeds' => 'Scrapped with no proceeds',
            'broken_beyond_economical_repair' => 'Broken; Beyond economical repair',
            'abandoned_no_value' => 'Abandoned with no value',
            'stolen_no_compensation' => 'Stolen',
            'lost_or_destroyed_no_compensation' => 'Lost or destroyed with no compensation',
            'ceased_use_no_value' => 'Ceased qualifying use with no value',
            self::DISPOSAL_EVENT_OTHER_NIL_VALUE => 'Other nil-value disposal',
        ];
    }

    public static function potentialAssetThresholdOptions(): array
    {
        return self::POTENTIAL_ASSET_THRESHOLD_OPTIONS;
    }

    public static function normalisePotentialAssetThreshold(mixed $threshold): int
    {
        $value = (int)$threshold;

        return in_array($value, self::POTENTIAL_ASSET_THRESHOLD_OPTIONS, true) ? $value : 250;
    }

    public static function isManualAssetOffsetNominalCandidate(array $nominal): bool
    {
        return in_array((string)($nominal['subtype_code'] ?? ''), self::MANUAL_ASSET_OFFSET_SUBTYPE_CODES, true);
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
                'manual_schema_ready' => false,
            ];
        }

        return $this->fetchRegisterData(
            $companyId,
            $accountingPeriodId,
            $defaultBankNominalId,
            $disposalSearchDate,
            $disposalSearchAssetId
        ) + [
            'accounting_periods' => $this->fetchAccountingPeriods($companyId),
            'accounting_period_id' => $accountingPeriodId,
            'tax_view' => $accountingPeriodId > 0 ? $this->fetchTaxView($companyId, $accountingPeriodId) : null,
            'prefill_transaction' => $prefillTransactionId > 0 ? $this->fetchTransactionPrefill($companyId, $prefillTransactionId) : null,
            'asset_categories' => self::assetCategoryOptions(),
            'manual_schema_ready' => $this->hasManualAssetSchema(),
        ];
    }

    public function fetchRegisterData(
        int $companyId,
        int $accountingPeriodId,
        int|string $defaultBankNominalId = 0,
        string $disposalSearchDate = '',
        int $disposalSearchAssetId = 0
    ): array {
        $defaultBankNominalId = max(0, (int)$defaultBankNominalId);

        if (!$this->hasRequiredSchema()) {
            return [
                'assets' => [],
                'accounting_period_id' => $accountingPeriodId,
                'default_bank_nominal_id' => $defaultBankNominalId,
                'disposal_search' => $this->emptyDisposalSearch($disposalSearchDate, $disposalSearchAssetId),
                'schema_ready' => false,
            ];
        }

        return [
            'assets' => $this->fetchRegisterAssets($companyId, $accountingPeriodId),
            'accounting_period_id' => $accountingPeriodId,
            'default_bank_nominal_id' => $defaultBankNominalId,
            'disposal_search' => $this->fetchDisposalSearch($companyId, $disposalSearchDate, $disposalSearchAssetId),
            'schema_ready' => true,
        ];
    }

    public function fetchCreateData(
        int $companyId,
        int $accountingPeriodId,
        int|string $defaultBankNominalId = 0,
        int $prefillTransactionId = 0,
        int $prefillTransactionSplitLineId = 0
    ): array {
        $defaultBankNominalId = max(0, (int)$defaultBankNominalId);

        if (!$this->hasRequiredSchema()) {
            return [
                'accounting_period_id' => $accountingPeriodId,
                'prefill_transaction' => null,
                'default_bank_nominal_id' => $defaultBankNominalId,
                'asset_categories' => self::assetCreateCategoryOptions(),
                'schema_ready' => false,
            ];
        }

        return [
            'accounting_period_id' => $accountingPeriodId,
            'prefill_transaction' => $prefillTransactionSplitLineId > 0
                ? $this->fetchTransactionSplitLinePrefill($companyId, $prefillTransactionSplitLineId)
                : ($prefillTransactionId > 0 ? $this->fetchTransactionPrefill($companyId, $prefillTransactionId) : null),
            'default_bank_nominal_id' => $defaultBankNominalId,
            'asset_categories' => self::assetCreateCategoryOptions(),
            'schema_ready' => true,
        ];
    }

    public function fetchTaxData(int $companyId, int $accountingPeriodId): array
    {
        if (!$this->hasRequiredSchema()) {
            return [
                'accounting_period_id' => $accountingPeriodId,
                'tax_view' => null,
                'schema_ready' => false,
            ];
        }

        return [
            'accounting_period_id' => $accountingPeriodId,
            'tax_view' => $accountingPeriodId > 0 ? $this->fetchTaxView($companyId, $accountingPeriodId) : null,
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

    public function fetchAssets(int $companyId, int $accountingPeriodId = 0): array {
        if (!$this->hasRequiredSchema()) {
            return [];
        }

        if ($companyId <= 0) {
            return [];
        }

        $assets = \InterfaceDB::fetchAll( 'SELECT ar.*,
                    COALESCE(ar.cost, 0) - COALESCE(dep.accumulated_depreciation, 0) AS nbv,
                    COALESCE(dep.accumulated_depreciation, 0) AS accumulated_depreciation,
                    na.code AS nominal_code,
                    na.name AS nominal_name
             FROM asset_register ar
             LEFT JOIN nominal_accounts na ON na.id = ar.nominal_account_id
             LEFT JOIN (
                SELECT ade.asset_id, SUM(ade.amount) AS accumulated_depreciation
                FROM asset_depreciation_entries ade
                INNER JOIN asset_register dep_ar ON dep_ar.id = ade.asset_id
                WHERE dep_ar.company_id = :depreciation_company_id
                GROUP BY ade.asset_id
             ) dep ON dep.asset_id = ar.id
             WHERE ar.company_id = :company_id
             ORDER BY ar.purchase_date DESC, ar.id DESC', [
                'company_id' => $companyId,
                'depreciation_company_id' => $companyId,
             ]);

        return $this->assetsWithPeriodDepreciation($assets ?: [], $companyId, $accountingPeriodId);
    }

    private function fetchRegisterAssets(int $companyId, int $accountingPeriodId = 0): array {
        if (!$this->hasRequiredSchema()) {
            return [];
        }

        if ($companyId <= 0) {
            return [];
        }

        $cutoffDate = $this->registerCutoffDate($companyId, $accountingPeriodId);
        $assets = \InterfaceDB::fetchAll(
            'SELECT ar.id,
                    ar.asset_code,
                    ar.description,
                    ar.nominal_account_id,
                    ar.purchase_date,
                    ar.cost,
                    ar.useful_life_years,
                    ar.depreciation_method,
                    ar.residual_value,
                    ar.status,
                    ar.disposal_date,
                    ar.disposal_event_type,
                    ar.disposal_reason,
                    COALESCE(ar.cost, 0) - COALESCE(dep.accumulated_depreciation, 0) AS nbv,
                    COALESCE(dep.accumulated_depreciation, 0) AS accumulated_depreciation,
                    na.code AS nominal_code,
                    na.name AS nominal_name
             FROM asset_register ar
             LEFT JOIN nominal_accounts na ON na.id = ar.nominal_account_id
             LEFT JOIN (
                SELECT ade.asset_id, SUM(ade.amount) AS accumulated_depreciation
                FROM asset_depreciation_entries ade
                INNER JOIN asset_register dep_ar ON dep_ar.id = ade.asset_id
                WHERE dep_ar.company_id = :depreciation_company_id
                GROUP BY ade.asset_id
             ) dep ON dep.asset_id = ar.id
             WHERE ar.company_id = :company_id
               AND ar.purchase_date <= :cutoff_date
             ORDER BY ar.purchase_date DESC, ar.id DESC',
            [
                'company_id' => $companyId,
                'depreciation_company_id' => $companyId,
                'cutoff_date' => $cutoffDate,
            ]
        );

        return $this->assetsWithPeriodDepreciation($assets ?: [], $companyId, $accountingPeriodId);
    }

    private function registerCutoffDate(int $companyId, int $accountingPeriodId): string
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        $periodEnd = trim((string)($accountingPeriod['period_end'] ?? ''));

        if (!$this->isIsoDate($periodEnd)) {
            return $today;
        }

        return min($today, $periodEnd);
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

    public function fetchManualAssetReconciliationData(int $companyId): array
    {
        $assets = $this->fetchManualAssetsNeedingReconciliation($companyId);

        foreach ($assets as $index => $asset) {
            $assets[$index]['candidates'] = $this->fetchManualAssetReconciliationCandidates($companyId, $asset);
        }

        return [
            'assets' => $assets,
            'manual_addition_reasons' => self::manualAdditionReasonOptions(),
        ];
    }

    public function fetchTransactionSplitLinePrefill(int $companyId, int $lineId): ?array {
        $line = (new \eel_accounts\Service\TransactionSplitService())->fetchSplitLineForAsset($companyId, $lineId);
        if ($line === null || round((float)($line['amount'] ?? 0), 2) <= 0.0) {
            return null;
        }

        $description = trim((string)($line['description'] ?? ''));

        return [
            'transaction_split_line_id' => (int)$line['id'],
            'transaction_id' => (int)$line['transaction_id'],
            'description' => $description !== '' ? $description : (string)($line['transaction_description'] ?? ''),
            'purchase_date' => (string)$line['txn_date'],
            'cost' => number_format((float)$line['amount'], 2, '.', ''),
            'accounting_period_id' => (int)$line['accounting_period_id'],
            'nominal_account_id' => (int)($line['nominal_account_id'] ?? 0),
        ];
    }

    public function fetchNonAssetCandidates(
        int $companyId,
        int $accountingPeriodId,
        int|string $toolsSmallEquipmentNominalId,
        int|string $threshold
    ): array {
        $nominalId = max(0, (int)$toolsSmallEquipmentNominalId);
        $threshold = self::normalisePotentialAssetThreshold($threshold);

        if ($companyId <= 0 || $accountingPeriodId <= 0 || $nominalId <= 0 || !$this->hasRequiredSchema()) {
            return [
                'available' => $nominalId > 0,
                'threshold' => $threshold,
                'threshold_options' => self::potentialAssetThresholdOptions(),
                'rows' => [],
                'count' => 0,
            ];
        }

        $rows = array_merge(
            $this->fetchNonAssetTransactionCandidates($companyId, $accountingPeriodId, $nominalId, $threshold),
            $this->fetchNonAssetExpenseClaimCandidates($companyId, $accountingPeriodId, $nominalId, $threshold)
        );

        usort($rows, static function (array $left, array $right): int {
            return [
                (string)($right['date'] ?? ''),
                (string)($right['source'] ?? ''),
                (int)($right['source_id'] ?? 0),
            ] <=> [
                (string)($left['date'] ?? ''),
                (string)($left['source'] ?? ''),
                (int)($left['source_id'] ?? 0),
            ];
        });

        return [
            'available' => true,
            'threshold' => $threshold,
            'threshold_options' => self::potentialAssetThresholdOptions(),
            'rows' => $rows,
            'count' => count($rows),
        ];
    }

    public function potentialAssetCandidateCount(
        int $companyId,
        int $accountingPeriodId,
        int|string $toolsSmallEquipmentNominalId,
        int|string $threshold
    ): int {
        return (int)$this->fetchNonAssetCandidates($companyId, $accountingPeriodId, $toolsSmallEquipmentNominalId, $threshold)['count'];
    }

    public function savePotentialAssetThreshold(int $companyId, mixed $threshold): array
    {
        if ($companyId <= 0) {
            return [
                'success' => false,
                'errors' => ['Select a company before saving the potential asset threshold.'],
            ];
        }

        $rawThreshold = (int)$threshold;
        if (!in_array($rawThreshold, self::POTENTIAL_ASSET_THRESHOLD_OPTIONS, true)) {
            return [
                'success' => false,
                'errors' => ['Choose a valid potential asset threshold.'],
            ];
        }

        $settingsStore = new \eel_accounts\Store\CompanySettingsStore($companyId);
        $settingsStore->set('potential_asset_threshold', $rawThreshold, 'int');
        $settingsStore->flush();

        return [
            'success' => true,
            'messages' => ['Potential asset threshold saved.'],
        ];
    }

    public function convertNonAssetToAsset(int $companyId, string $sourceType, int $sourceId, array $payload, int $defaultBankNominalId = 0): array
    {
        $sourceType = strtolower(trim($sourceType));
        if ($companyId <= 0 || $sourceId <= 0) {
            return ['success' => false, 'errors' => ['Select a valid non-asset source before converting.']];
        }

        $assetPayload = $this->normaliseNonAssetConversionPayload($payload);

        if ($sourceType === 'transaction') {
            return $this->createAssetFromTransaction($companyId, $sourceId, $assetPayload, $defaultBankNominalId);
        }

        if ($sourceType === 'expense_claim') {
            return (new \eel_accounts\Service\ExpenseClaimService())->convertPostedLineToAsset($companyId, $sourceId, $assetPayload);
        }

        return ['success' => false, 'errors' => ['Choose a valid non-asset source type.']];
    }

    private function fetchNonAssetTransactionCandidates(int $companyId, int $accountingPeriodId, int $nominalId, int $threshold): array
    {
        if (!$this->tableExists('transactions')) {
            return [];
        }

        return array_map(
            static function (array $row): array {
                return [
                    'source_type' => 'transaction',
                    'source_id' => (int)$row['id'],
                    'date' => (string)$row['txn_date'],
                    'source' => 'Transaction',
                    'description' => (string)$row['description'],
                    'reference' => (string)($row['reference'] ?? ''),
                    'amount' => round(abs((float)$row['amount']), 2),
                ];
            },
            \InterfaceDB::fetchAll(
                'SELECT t.id,
                        t.txn_date,
                        t.description,
                        t.reference,
                        t.amount
                 FROM transactions t
                 WHERE t.company_id = :company_id
                   AND t.accounting_period_id = :accounting_period_id
                   AND t.nominal_account_id = :nominal_account_id
                   AND CAST(ABS(t.amount) AS DECIMAL(18, 2)) > CAST(:threshold AS DECIMAL(18, 2))
                   AND COALESCE(t.is_internal_transfer, 0) = 0
                   AND t.transfer_account_id IS NULL
                   AND NOT EXISTS (
                       SELECT 1
                       FROM asset_register linked_asset
                       WHERE linked_asset.linked_transaction_id = t.id
                   )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'nominal_account_id' => $nominalId,
                    'threshold' => $threshold,
                ]
            ) ?: []
        );
    }

    private function normaliseNonAssetConversionPayload(array $payload): array
    {
        return [
            'description' => trim((string)($payload['description'] ?? '')),
            'category' => trim((string)($payload['category'] ?? $payload['asset_category'] ?? 'tools_equipment')),
            'purchase_date' => trim((string)($payload['purchase_date'] ?? '')),
            'cost' => $payload['cost'] ?? '',
            'useful_life_years' => (int)($payload['useful_life_years'] ?? $payload['asset_useful_life_years'] ?? 3),
            'depreciation_method' => trim((string)($payload['depreciation_method'] ?? $payload['asset_depreciation_method'] ?? 'straight_line')),
            'residual_value' => $payload['residual_value'] ?? $payload['asset_residual_value'] ?? '0.00',
            'accounting_period_id' => (int)($payload['accounting_period_id'] ?? 0),
        ];
    }

    private function linkedTransactionAssetExists(int $transactionId): bool
    {
        if ($transactionId <= 0 || !$this->tableExists('asset_register')) {
            return false;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT EXISTS(
                SELECT 1
                FROM asset_register
                WHERE linked_transaction_id = :transaction_id
            )',
            ['transaction_id' => $transactionId]
        ) === 1;
    }

    private function fetchNonAssetExpenseClaimCandidates(int $companyId, int $accountingPeriodId, int $nominalId, int $threshold): array
    {
        if (!$this->tableExists('expense_claims') || !$this->tableExists('expense_claim_lines')) {
            return [];
        }

        return array_map(
            static function (array $row): array {
                return [
                    'source_type' => 'expense_claim',
                    'source_id' => (int)$row['id'],
                    'source_claim_id' => (int)$row['expense_claim_id'],
                    'date' => (string)$row['expense_date'],
                    'source' => 'Expense claim',
                    'description' => (string)$row['description'],
                    'reference' => (string)(trim((string)($row['receipt_reference'] ?? '')) !== ''
                        ? $row['receipt_reference']
                        : ($row['claim_reference_code'] ?? '')),
                    'amount' => round((float)$row['amount'], 2),
                ];
            },
            \InterfaceDB::fetchAll(
                'SELECT ecl.id,
                        ecl.expense_claim_id,
                        ecl.expense_date,
                        ecl.description,
                        ecl.receipt_reference,
                        ecl.amount,
                        ec.claim_reference_code
                 FROM expense_claim_lines ecl
                 INNER JOIN expense_claims ec ON ec.id = ecl.expense_claim_id
                 WHERE ec.company_id = :company_id
                   AND ec.accounting_period_id = :accounting_period_id
                   AND ecl.nominal_account_id = :nominal_account_id
                   AND CAST(ecl.amount AS DECIMAL(18, 2)) > CAST(:threshold AS DECIMAL(18, 2))
                   AND NOT EXISTS (
                       SELECT 1
                       FROM asset_register linked_asset
                       WHERE linked_asset.linked_expense_claim_line_id = ecl.id
                   )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'nominal_account_id' => $nominalId,
                    'threshold' => $threshold,
                ]
            ) ?: []
        );
    }

    public function fetchManualAssetsNeedingReconciliation(int $companyId): array
    {
        if ($companyId <= 0 || !$this->hasManualAssetSchema()) {
            return [];
        }

        $reasons = $this->manualAssetReconciliationReasons();
        if ($reasons === []) {
            return [];
        }

        $placeholders = implode(', ', array_map(static fn(int $index): string => ':reason_' . $index, array_keys($reasons)));
        $params = ['company_id' => $companyId];
        foreach (array_values($reasons) as $index => $reason) {
            $params['reason_' . $index] = $reason;
        }

        return array_map(
            function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'asset_code' => (string)$row['asset_code'],
                    'description' => (string)$row['description'],
                    'purchase_date' => (string)$row['purchase_date'],
                    'cost' => round((float)$row['cost'], 2),
                    'manual_addition_reason' => (string)$row['manual_addition_reason'],
                    'manual_addition_reason_label' => $this->manualAdditionReasonLabel((string)$row['manual_addition_reason']),
                    'manual_offset_nominal_id' => (int)$row['manual_offset_nominal_id'],
                    'manual_offset_nominal_label' => FormattingFramework::nominalLabel([
                        'code' => (string)($row['offset_nominal_code'] ?? ''),
                        'name' => (string)($row['offset_nominal_name'] ?? ''),
                    ], ' '),
                    'linked_journal_id' => (int)($row['linked_journal_id'] ?? 0),
                ];
            },
            \InterfaceDB::fetchAll(
                'SELECT ar.id,
                        ar.asset_code,
                        ar.description,
                        ar.purchase_date,
                        ar.cost,
                        ar.manual_addition_reason,
                        ar.manual_offset_nominal_id,
                        ar.linked_journal_id,
                        mno.code AS offset_nominal_code,
                        mno.name AS offset_nominal_name
                 FROM asset_register ar
                 INNER JOIN nominal_accounts mno ON mno.id = ar.manual_offset_nominal_id
                 INNER JOIN journals j ON j.id = ar.linked_journal_id
                 WHERE ar.company_id = :company_id
                   AND ar.status = \'active\'
                   AND ar.linked_transaction_id IS NULL
                   AND ar.linked_expense_claim_line_id IS NULL
                   AND ar.manual_offset_nominal_id IS NOT NULL
                   AND ar.manual_addition_reason IN (' . $placeholders . ')
                   AND j.source_type = \'asset_register\'
                 ORDER BY ar.purchase_date DESC, ar.id DESC',
                $params
            ) ?: []
        );
    }

    public function fetchManualAssetReconciliationCandidates(int $companyId, array $asset): array
    {
        $assetId = (int)($asset['id'] ?? 0);
        $purchaseDate = (string)($asset['purchase_date'] ?? '');
        $cost = round((float)($asset['cost'] ?? 0), 2);
        if ($companyId <= 0 || $assetId <= 0 || $cost <= 0 || !$this->isIsoDate($purchaseDate) || !$this->hasManualAssetSchema()) {
            return [];
        }

        $window = $this->manualAssetReconciliationWindow($purchaseDate);
        $rows = \InterfaceDB::fetchAll(
            'SELECT t.id,
                    t.accounting_period_id,
                    t.txn_date,
                    t.description,
                    t.reference,
                    t.amount,
                    t.category_status,
                    t.nominal_account_id,
                    n.code AS nominal_code,
                    n.name AS nominal_name
             FROM transactions t
             LEFT JOIN nominal_accounts n ON n.id = t.nominal_account_id
             WHERE t.company_id = :company_id
               AND t.amount < 0
               AND ABS(ABS(t.amount) - :cost) <= 0.01
               AND t.txn_date BETWEEN :window_start AND :window_end
               AND COALESCE(t.is_internal_transfer, 0) = 0
               AND t.transfer_account_id IS NULL
               AND NOT EXISTS (
                   SELECT 1
                   FROM asset_register linked_asset
                   WHERE linked_asset.linked_transaction_id = t.id
               )
             ORDER BY t.txn_date ASC, t.id ASC
             LIMIT 80',
            [
                'company_id' => $companyId,
                'cost' => $cost,
                'window_start' => $window['start'],
                'window_end' => $window['end'],
            ]
        ) ?: [];

        $lockService = new \eel_accounts\Service\YearEndLockService();
        $candidates = [];
        foreach ($rows as $row) {
            $accountingPeriodId = (int)($row['accounting_period_id'] ?? 0);
            if ($lockService->isLocked($companyId, $accountingPeriodId)) {
                continue;
            }

            $candidates[] = [
                'id' => (int)$row['id'],
                'txn_date' => (string)$row['txn_date'],
                'description' => (string)$row['description'],
                'reference' => (string)($row['reference'] ?? ''),
                'amount' => round(abs((float)$row['amount']), 2),
                'category_status' => (string)$row['category_status'],
                'nominal_account_id' => (int)($row['nominal_account_id'] ?? 0),
                'nominal_label' => trim((string)($row['nominal_code'] ?? '')) !== ''
                    ? (string)$row['nominal_code'] . ' - ' . (string)($row['nominal_name'] ?? '')
                    : '',
                'has_derived_journal' => $this->transactionJournalService->transactionHasDerivedJournal((int)$row['id']) ? 1 : 0,
            ];
        }

        return $candidates;
    }

    public function createAssetFromTransaction(int $companyId, int $transactionId, array $payload, int $defaultBankNominalId): array {
        if (!$this->hasRequiredSchema()) {
            return ['success' => false, 'errors' => ['Run the fixed asset migration before using the asset register.']];
        }

        $transaction = $this->categorisationService->fetchTransaction($transactionId);
        if ($transaction === null || (int)($transaction['company_id'] ?? 0) !== $companyId) {
            return ['success' => false, 'errors' => ['The selected transaction could not be found for this company.']];
        }
        (new \eel_accounts\Service\AccountingPeriodAccessService())->assertDataEntryPermitted(
            $companyId,
            (int)($transaction['accounting_period_id'] ?? 0),
            'create assets from transactions in this period'
        );

        if ($this->linkedTransactionAssetExists($transactionId)) {
            return ['success' => false, 'errors' => ['This transaction is already linked to an asset.']];
        }

        if ($this->transactionHasInterAccountMarker($transactionId)) {
            return ['success' => false, 'errors' => ['Cancel the inter-account match before creating an asset from this transaction.']];
        }

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

    public function createManualAsset(int $companyId, int $accountingPeriodId, array $payload, int $offsetNominalId, array $evidenceFile = []): array {
        if (!$this->hasManualAssetSchema()) {
            return ['success' => false, 'errors' => ['Run the manual asset evidence migration before posting manual assets.']];
        }

        $manualAdditionReason = $this->normaliseManualAdditionReason((string)($payload['manual_addition_reason'] ?? ''));
        if ($manualAdditionReason === '') {
            return ['success' => false, 'errors' => ['Choose a manual addition reason before posting a manual asset.']];
        }
        if ($offsetNominalId <= 0) {
            return ['success' => false, 'errors' => ['Choose an offset nominal before posting a manual asset.']];
        }
        if (!$this->isManualAssetOffsetNominal($offsetNominalId)) {
            return ['success' => false, 'errors' => ['Choose a funding or clearing nominal before posting a manual asset.']];
        }
        if (!$this->truthy($payload['manual_asset_legal_acknowledged'] ?? '0')) {
            return ['success' => false, 'errors' => ['Acknowledge the manual asset legal warning before posting this asset.']];
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

        $assetCode = $this->generateAssetCode($companyId);
        $evidenceStorage = new \eel_accounts\Service\ManualAssetEvidenceStorageService();
        $storedEvidence = $evidenceStorage->storeEvidence($companyId, $assetCode, $evidenceFile);
        if (empty($storedEvidence['success'])) {
            return ['success' => false, 'errors' => array_values(array_map('strval', (array)($storedEvidence['errors'] ?? ['Upload evidence that the manual asset exists.'])))];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
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
                'manual_addition_reason' => $manualAdditionReason,
                'manual_offset_nominal_id' => $offsetNominalId,
                'manual_evidence_path' => (string)($storedEvidence['path'] ?? ''),
                'manual_evidence_sha256' => (string)($storedEvidence['sha256'] ?? ''),
                'manual_evidence_original_filename' => (string)($storedEvidence['original_filename'] ?? ''),
                'manual_evidence_content_type' => (string)($storedEvidence['content_type'] ?? ''),
                'manual_evidence_size_bytes' => (int)($storedEvidence['size_bytes'] ?? 0),
                'manual_legal_warning_version' => self::MANUAL_ASSET_LEGAL_WARNING_VERSION,
                'manual_legal_acknowledged_at' => date('Y-m-d H:i:s'),
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
            $evidenceStorage->deleteStoredEvidence($storedEvidence);

            return ['success' => false, 'errors' => ['The manual asset could not be posted: ' . $exception->getMessage()]];
        }
    }

    public function createAssetFromTransactionSplitLine(int $companyId, int $lineId, array $payload, int $defaultBankNominalId): array {
        if (!$this->hasRequiredSchema()) {
            return ['success' => false, 'errors' => ['Run the fixed asset migration before using the asset register.']];
        }

        if (!\InterfaceDB::columnExists('asset_register', 'linked_transaction_split_line_id')) {
            return ['success' => false, 'errors' => ['Run the transaction split migration before creating assets from split lines.']];
        }

        $splitService = new \eel_accounts\Service\TransactionSplitService();
        $line = $splitService->fetchSplitLineForAsset($companyId, $lineId);
        if ($line === null) {
            return ['success' => false, 'errors' => ['The selected transaction split line could not be found for this company.']];
        }

        $transactionId = (int)$line['transaction_id'];
        $accountingPeriodId = (int)$line['accounting_period_id'];
        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'create assets from transaction split lines in this period');

        if ($splitService->splitLineHasAsset($lineId)) {
            return ['success' => false, 'errors' => ['This split line is already linked to an asset.']];
        }

        if ($defaultBankNominalId <= 0) {
            return ['success' => false, 'errors' => ['Set the default bank nominal before creating an asset from a transaction split line.']];
        }

        $description = trim((string)($line['description'] ?? ''));
        $normalised = $this->normaliseAssetPayload($companyId, $payload, [
            'purchase_date' => (string)$line['txn_date'],
            'cost' => (float)$line['amount'],
            'description' => $description !== '' ? $description : (string)($line['transaction_description'] ?? ''),
            'accounting_period_id' => $accountingPeriodId,
        ]);
        if ($normalised['errors'] !== []) {
            return ['success' => false, 'errors' => $normalised['errors']];
        }

        $lineAmount = round((float)($line['amount'] ?? 0), 2);
        if ($lineAmount <= 0.0) {
            return ['success' => false, 'errors' => ['Choose a split line with a positive amount before creating an asset.']];
        }

        if (abs(round((float)$normalised['values']['cost'], 2) - $lineAmount) >= 0.005) {
            return ['success' => false, 'errors' => ['Asset cost must match the selected split line amount.']];
        }

        $assetNominalId = (int)$normalised['values']['nominal_account_id'];
        $lineNominalId = (int)($line['nominal_account_id'] ?? 0);
        if ($lineNominalId > 0 && $assetNominalId !== $lineNominalId) {
            return ['success' => false, 'errors' => ['Choose an asset category whose cost nominal matches the split line nominal.']];
        }

        $split = $splitService->fetchSplitForTransaction($transactionId);
        if ($split === null || empty($split['is_balanced']) || !$this->splitWouldBeReadyWithAssetLine($split, $lineId, $assetNominalId)) {
            return ['success' => false, 'errors' => ['Complete and balance the split before creating an asset from one of its lines.']];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            if ($lineNominalId <= 0) {
                $lineSave = $splitService->saveLine($companyId, $lineId, [
                    'split_line_description' => (string)$normalised['values']['description'],
                    'split_line_amount' => number_format($lineAmount, 2, '.', ''),
                    'nominal_account_id' => $assetNominalId,
                    'split_line_notes' => (string)($line['notes'] ?? ''),
                ]);
                if (empty($lineSave['success'])) {
                    throw new \RuntimeException(implode(' ', array_map('strval', $lineSave['errors'] ?? ['The split line could not be assigned to the asset nominal.'])));
                }
            }

            $readySplit = $splitService->fetchReadySplitForPosting($transactionId);
            if ($readySplit === null) {
                throw new \RuntimeException('Complete and balance the split before creating an asset from one of its lines.');
            }

            $journalResult = $this->transactionJournalService->syncJournalForTransaction(
                $transactionId,
                $defaultBankNominalId,
                'asset_register_split_line',
                true
            );
            if (empty($journalResult['success'])) {
                throw new \RuntimeException(implode(' ', array_map('strval', $journalResult['errors'] ?? ['The split transaction journal could not be posted.'])));
            }

            $assetCode = $this->generateAssetCode($companyId);
            $this->insertAssetRecord($normalised['values'], [
                'asset_code' => $assetCode,
                'linked_transaction_id' => $transactionId,
                'linked_transaction_split_line_id' => $lineId,
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
                'messages' => ['Asset created from the selected split line and linked to the split journal.'],
            ];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['The split-line asset could not be created: ' . $exception->getMessage()]];
        }
    }

    private function splitWouldBeReadyWithAssetLine(array $split, int $lineId, int $assetNominalId): bool
    {
        if (empty($split['is_balanced']) || $assetNominalId <= 0) {
            return false;
        }

        $lineCount = 0;
        foreach ((array)($split['lines'] ?? []) as $line) {
            if ((int)($line['is_deferred'] ?? 0) === 1) {
                return false;
            }

            $lineCount++;
            $amount = $line['amount'] === null || $line['amount'] === '' ? null : round((float)$line['amount'], 2);
            $nominalAccountId = (int)($line['nominal_account_id'] ?? 0);
            if ((int)($line['id'] ?? 0) === $lineId && $nominalAccountId <= 0) {
                $nominalAccountId = $assetNominalId;
            }

            if ($amount === null || $amount <= 0.0 || $nominalAccountId <= 0) {
                return false;
            }
        }

        return $lineCount >= 2;
    }

    public function reconcileManualAssetWithTransaction(
        int $companyId,
        int $assetId,
        int $transactionId,
        int $defaultBankNominalId,
        bool $confirmedJournalRebuild = false
    ): array {
        if (!$this->hasManualAssetSchema()) {
            return ['success' => false, 'errors' => ['Run the manual asset reconciliation migration before reconciling manual assets.']];
        }

        $asset = $this->fetchAsset($companyId, $assetId);
        if ($asset === null) {
            return ['success' => false, 'errors' => ['The selected manual asset could not be found.']];
        }

        $lockService = new \eel_accounts\Service\YearEndLockService();
        $assetAccountingPeriodId = (int)($asset['accounting_period_id'] ?? 0);
        if ($assetAccountingPeriodId > 0) {
            $lockService->assertUnlocked($companyId, $assetAccountingPeriodId, 'reconcile a manual asset in this period');
        }

        $candidateTransaction = $this->categorisationService->fetchTransaction($transactionId);
        if (is_array($candidateTransaction) && (int)($candidateTransaction['company_id'] ?? 0) === $companyId) {
            $candidateAccountingPeriodId = (int)($candidateTransaction['accounting_period_id'] ?? 0);
            if ($candidateAccountingPeriodId > 0) {
                $lockService->assertUnlocked($companyId, $candidateAccountingPeriodId, 'reconcile a transaction in this period');
            }
        }

        $manualOffsetNominalId = (int)($asset['manual_offset_nominal_id'] ?? 0);
        if (!$this->manualAssetRequiresReconciliation((string)($asset['manual_addition_reason'] ?? ''))) {
            return ['success' => false, 'errors' => ['This asset does not require manual reconciliation.']];
        }
        if ((int)($asset['linked_transaction_id'] ?? 0) > 0) {
            return ['success' => false, 'errors' => ['This asset is already linked to an imported transaction.']];
        }
        if ($manualOffsetNominalId <= 0 || !$this->isManualAssetOffsetNominal($manualOffsetNominalId)) {
            return ['success' => false, 'errors' => ['The manual asset offset nominal is missing or inactive.']];
        }

        $candidateIds = array_fill_keys(array_map(
            static fn(array $candidate): int => (int)$candidate['id'],
            $this->fetchManualAssetReconciliationCandidates($companyId, $asset)
        ), true);
        if (!isset($candidateIds[$transactionId])) {
            return ['success' => false, 'errors' => ['Choose a matching unreconciled outgoing transaction for this manual asset.']];
        }
        if ($this->transactionHasInterAccountMarker($transactionId)) {
            return ['success' => false, 'errors' => ['Cancel the inter-account match before reconciling this asset transaction.']];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $saveResult = $this->categorisationService->saveManualCategorisation(
                $transactionId,
                $manualOffsetNominalId,
                null,
                false,
                'manual_asset_reconciliation',
                $confirmedJournalRebuild
            );
            if (!empty($saveResult['requires_confirmation'])) {
                if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }
                return [
                    'success' => false,
                    'requires_confirmation' => true,
                    'errors' => ['Confirm journal rebuild before reconciling this manual asset.'],
                ];
            }
            if (!empty($saveResult['errors'])) {
                throw new \RuntimeException(implode(' ', array_map('strval', $saveResult['errors'])));
            }

            $journalResult = $this->transactionJournalService->syncJournalForTransaction(
                $transactionId,
                $defaultBankNominalId,
                'manual_asset_reconciliation',
                $confirmedJournalRebuild
            );
            if (!empty($journalResult['requires_confirmation'])) {
                if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }
                return [
                    'success' => false,
                    'requires_confirmation' => true,
                    'errors' => ['Confirm journal rebuild before reconciling this manual asset.'],
                ];
            }
            if (!empty($journalResult['errors'])) {
                throw new \RuntimeException(implode(' ', array_map('strval', $journalResult['errors'])));
            }

            \InterfaceDB::prepareExecute(
                'UPDATE asset_register
                 SET linked_transaction_id = :linked_transaction_id,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND company_id = :company_id',
                [
                    'linked_transaction_id' => $transactionId,
                    'id' => $assetId,
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

            return ['success' => false, 'errors' => ['The manual asset could not be reconciled: ' . $exception->getMessage()]];
        }

        return [
            'success' => true,
            'asset' => $this->fetchAsset($companyId, $assetId),
            'messages' => ['Manual asset reconciled to the imported transaction.'],
        ];
    }

    public function runDepreciation(int $companyId, int $accountingPeriodId): array {
        $scopeBlock = (new VatSupportScopeService())->mutationBlockResult($companyId, 'post Year End depreciation');
        if ($scopeBlock !== null) {
            return $scopeBlock;
        }

        if (!$this->hasRequiredSchema()) {
            return ['success' => false, 'errors' => ['Run the fixed asset migration before posting depreciation.']];
        }

        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return ['success' => false, 'errors' => ['The selected accounting period could not be found.']];
        }
        $periodEnd = trim((string)($accountingPeriod['period_end'] ?? ''));
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if ($periodEnd === '' || $today <= $periodEnd) {
            return [
                'success' => false,
                'errors' => [
                    sprintf(
                        'Depreciation can only be posted after the accounting period end date. Period ends %s; today is %s.',
                        $periodEnd !== '' ? $periodEnd : 'unknown',
                        $today
                    ),
                ],
            ];
        }
        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'post depreciation in this period');

        $assets = $this->fetchDepreciableAssets($companyId, (string)$accountingPeriod['period_start'], (string)$accountingPeriod['period_end']);
        $summary = ['success' => true, 'created' => 0, 'skipped' => 0, 'errors' => []];
        $transaction = $this->beginAssetMutationTransaction('asset_depreciation');

        try {
            foreach ($assets as $asset) {
                $periodStart = max((string)$accountingPeriod['period_start'], (string)$asset['purchase_date']);
                $periodEnd = (string)$accountingPeriod['period_end'];
                if ((string)($asset['status'] ?? 'active') === 'disposed' && trim((string)($asset['disposal_date'] ?? '')) !== '') {
                    $periodEnd = min($periodEnd, (string)$asset['disposal_date']);
                }
                $periodEnd = min($periodEnd, $this->usefulLifeEndDate((string)$asset['purchase_date'], (int)($asset['useful_life_years'] ?? 1)));

                if ($periodEnd < $periodStart) {
                    $summary['skipped']++;
                    continue;
                }

                $expectedPeriodAmount = $this->calculateExpectedDepreciationForInterval(
                    $asset,
                    $periodStart,
                    $periodEnd
                );
                $postedPeriodAmount = $this->sumDepreciationForAccountingPeriod(
                    (int)$asset['id'],
                    $accountingPeriodId
                );
                $amount = round(max(0.0, $expectedPeriodAmount - $postedPeriodAmount), 2);
                if ($amount <= 0) {
                    $summary['skipped']++;
                    continue;
                }

                $this->postDepreciationCharge(
                    $companyId,
                    $asset,
                    $accountingPeriodId,
                    $periodStart,
                    $periodEnd,
                    $amount
                );

                $summary['created']++;
            }

            $capitalAllowances = (new \eel_accounts\Service\CapitalAllowanceService())
                ->persistForAccountingPeriod($companyId, $accountingPeriodId);
            if (empty($capitalAllowances['success'])) {
                throw new \RuntimeException(implode(
                    ' ',
                    array_map(
                        'strval',
                        (array)($capitalAllowances['errors']
                            ?? ['Capital allowances could not be persisted for this accounting period.'])
                    )
                ));
            }

            $this->commitAssetMutationTransaction($transaction);
        } catch (\Throwable $exception) {
            $this->rollBackAssetMutationTransaction($transaction);

            return ['success' => false, 'errors' => ['Depreciation could not be posted: ' . $exception->getMessage()]];
        }

        return $summary;
    }

    public function previewDepreciationRun(int $companyId, int $accountingPeriodId): array {
        $requestCacheKey = $companyId . ':' . $accountingPeriodId;
        if (\eel_accounts\Support\RequestCache::has('asset.depreciation-preview', $requestCacheKey)) {
            return (array)\eel_accounts\Support\RequestCache::get('asset.depreciation-preview', $requestCacheKey);
        }

        if (!$this->hasRequiredSchema()) {
            return ['success' => false, 'errors' => ['Run the fixed asset migration before posting depreciation.']];
        }

        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return ['success' => false, 'errors' => ['The selected accounting period could not be found.']];
        }

        $periodEnd = trim((string)($accountingPeriod['period_end'] ?? ''));
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if ($periodEnd === '' || $today <= $periodEnd) {
            return [
                'success' => false,
                'errors' => [
                    sprintf(
                        'Depreciation can only be posted after the accounting period end date. Period ends %s; today is %s.',
                        $periodEnd !== '' ? $periodEnd : 'unknown',
                        $today
                    ),
                ],
            ];
        }

        $assets = $this->fetchDepreciableAssets($companyId, (string)$accountingPeriod['period_start'], (string)$accountingPeriod['period_end']);
        $postedDepreciationByAsset = $this->fetchDepreciationByAsset($companyId, $accountingPeriodId);
        $rows = [];
        $total = 0.0;
        $skipped = 0;

        foreach ($assets as $asset) {
            $depreciationPeriodStart = max((string)$accountingPeriod['period_start'], (string)$asset['purchase_date']);
            $depreciationPeriodEnd = (string)$accountingPeriod['period_end'];
            if ((string)($asset['status'] ?? 'active') === 'disposed' && trim((string)($asset['disposal_date'] ?? '')) !== '') {
                $depreciationPeriodEnd = min($depreciationPeriodEnd, (string)$asset['disposal_date']);
            }
            $depreciationPeriodEnd = min(
                $depreciationPeriodEnd,
                $this->usefulLifeEndDate((string)$asset['purchase_date'], (int)($asset['useful_life_years'] ?? 1))
            );

            if ($depreciationPeriodEnd < $depreciationPeriodStart) {
                $skipped++;
                continue;
            }

            $expectedPeriodAmount = $this->calculateExpectedDepreciationForInterval(
                $asset,
                $depreciationPeriodStart,
                $depreciationPeriodEnd
            );
            $postedPeriodAmount = (float)($postedDepreciationByAsset[(int)$asset['id']] ?? 0.0);
            $amount = round(max(0.0, $expectedPeriodAmount - $postedPeriodAmount), 2);
            if ($amount <= 0) {
                $skipped++;
                continue;
            }

            $amount = round($amount, 2);
            $total = round($total + $amount, 2);
            $rows[] = [
                'asset_id' => (int)$asset['id'],
                'asset_code' => (string)($asset['asset_code'] ?? ''),
                'period_start' => $depreciationPeriodStart,
                'period_end' => $depreciationPeriodEnd,
                'amount' => $amount,
            ];
        }

        $result = [
            'success' => true,
            'created' => count($rows),
            'skipped' => $skipped,
            'total_amount' => $total,
            'rows' => $rows,
        ];

        return (array)\eel_accounts\Support\RequestCache::put(
            'asset.depreciation-preview',
            $requestCacheKey,
            $result
        );
    }

    /**
     * Return the accounting charge belonging to this interval independently
     * of whether another accounting period has posted its journal yet. Both
     * preview and posting use this basis so out-of-order runs remain assigned
     * to their proper accounting periods.
     */
    private function calculateExpectedDepreciationForInterval(
        array $asset,
        string $periodStart,
        string $periodEnd
    ): float {
        $boundedPeriodEnd = $this->boundedDepreciationPeriodEnd($asset, $periodStart, $periodEnd);
        $purchaseDate = trim((string)($asset['purchase_date'] ?? ''));
        if ($boundedPeriodEnd === null || !$this->isIsoDate($purchaseDate)) {
            return 0.0;
        }

        $closingTarget = $this->calculateDepreciationToDateAmount($asset, $boundedPeriodEnd);
        $openingDate = (new \DateTimeImmutable($periodStart))->modify('-1 day')->format('Y-m-d');
        $openingTarget = $openingDate >= $purchaseDate
            ? $this->calculateDepreciationToDateAmount($asset, $openingDate)
            : 0.0;

        return round(max(0.0, $closingTarget - $openingTarget), 2);
    }

    private function sumDepreciationForAccountingPeriod(int $assetId, int $accountingPeriodId): float
    {
        return round((float)\InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(amount), 0)
             FROM asset_depreciation_entries
             WHERE asset_id = :asset_id
               AND accounting_period_id = :accounting_period_id',
            [
                'asset_id' => $assetId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        ), 2);
    }

    public function disposeAsset(int $companyId, int $assetId, string $disposalDate, float $proceeds, int $bankNominalId): array {
        if (round($proceeds, 2) > 0.0) {
            return ['success' => false, 'errors' => ['Select a receipt transaction before disposing an asset with proceeds.']];
        }

        return $this->disposeAssetAtNilValue($companyId, $assetId, $disposalDate, '', '');
    }

    public function disposeAssetAtNilValue(int $companyId, int $assetId, string $disposalDate, string $disposalEventType = '', string $disposalReason = ''): array {
        if (!$this->hasRequiredSchema()) {
            return ['success' => false, 'errors' => ['Run the fixed asset migration before posting disposals.']];
        }

        $asset = $this->fetchAsset($companyId, $assetId);
        $validation = $this->validateDisposalAssetAndDate($asset, $disposalDate);
        if ($validation !== []) {
            return ['success' => false, 'errors' => $validation];
        }
        $metadata = $this->validateNilDisposalMetadata($disposalEventType, $disposalReason);
        if ($metadata['errors'] !== []) {
            return ['success' => false, 'errors' => $metadata['errors']];
        }

        $accountingPeriodId = $this->resolveAccountingPeriodIdForDate($companyId, $disposalDate);
        if ($accountingPeriodId <= 0) {
            return ['success' => false, 'errors' => ['No accounting period exists for the disposal date.']];
        }
        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'dispose assets in this period');

        $transaction = $this->beginAssetMutationTransaction('asset_nil_disposal');

        try {
            $summary = $this->postAssetDisposalJournalAndStatus(
                $companyId,
                $asset,
                $accountingPeriodId,
                $disposalDate,
                0.0,
                null,
                (string)$metadata['event_type'],
                (string)$metadata['reason']
            );

            $capitalAllowances = (new \eel_accounts\Service\CapitalAllowanceService())
                ->persistForAccountingPeriod($companyId, $accountingPeriodId);
            if (empty($capitalAllowances['success'])) {
                throw new \RuntimeException(implode(
                    ' ',
                    array_map(
                        'strval',
                        (array)($capitalAllowances['errors']
                            ?? ['Capital allowances could not be persisted for the disposal period.'])
                    )
                ));
            }

            $this->commitAssetMutationTransaction($transaction);
        } catch (\Throwable $exception) {
            $this->rollBackAssetMutationTransaction($transaction);

            return ['success' => false, 'errors' => ['The asset disposal could not be posted: ' . $exception->getMessage()]];
        }

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
        if ($this->transactionHasInterAccountMarker($transactionId)) {
            return ['success' => false, 'errors' => ['Cancel the inter-account match before posting an asset disposal from this transaction.']];
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
        $transaction = $this->beginAssetMutationTransaction('asset_receipt_disposal');

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

            $summary = $this->postAssetDisposalJournalAndStatus(
                $companyId,
                $asset,
                $accountingPeriodId,
                $disposalDate,
                $proceeds,
                $clearingNominalId,
                self::DISPOSAL_EVENT_SALE_RECEIPT,
                'Disposed on receipt of linked sale proceeds transaction #' . $transactionId
            );
            $this->insertDisposalTransactionLink($assetId, $transactionId, $proceeds);

            $capitalAllowances = (new \eel_accounts\Service\CapitalAllowanceService())
                ->persistForAccountingPeriod($companyId, $accountingPeriodId);
            if (empty($capitalAllowances['success'])) {
                throw new \RuntimeException(implode(
                    ' ',
                    array_map(
                        'strval',
                        (array)($capitalAllowances['errors']
                            ?? ['Capital allowances could not be persisted for the disposal period.'])
                    )
                ));
            }

            $this->commitAssetMutationTransaction($transaction);
        } catch (\Throwable $exception) {
            $this->rollBackAssetMutationTransaction($transaction);

            return ['success' => false, 'errors' => ['The asset disposal could not be posted: ' . $exception->getMessage()]];
        }

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

        $summary = (new \eel_accounts\Service\YearEndTaxReadinessService())->fetchAccountingPeriodCtSummary($companyId, $accountingPeriodId);
        if (!empty($summary['available'])) {
            return $summary;
        }

        return [
            'accounting_period' => $accountingPeriod,
            'accounting_profit' => 0.0,
            'disallowable_add_backs' => 0.0,
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

    private function validateNilDisposalMetadata(string $eventType, string $reason): array
    {
        $eventType = trim($eventType);
        $reason = trim($reason);
        $options = self::nilDisposalEventOptions();
        $errors = [];

        if (!array_key_exists($eventType, $options)) {
            $errors[] = 'Select a nil-value disposal reason.';
        }
        if ($eventType === self::DISPOSAL_EVENT_OTHER_NIL_VALUE && $reason === '') {
            $errors[] = 'Enter the reason for the other nil-value disposal.';
        }

        return [
            'errors' => $errors,
            'event_type' => $eventType,
            'reason' => $reason !== '' ? $reason : (string)($options[$eventType] ?? ''),
        ];
    }

    public function refreshTaxData(int $companyId): array
    {
        return (new \eel_accounts\Service\CapitalAllowanceService())->rebuildForCompany($companyId);
    }

    /** @return array{owns_transaction: bool, savepoint: string} */
    private function beginAssetMutationTransaction(string $prefix): array
    {
        if (!\InterfaceDB::inTransaction()) {
            \InterfaceDB::beginTransaction();

            return ['owns_transaction' => true, 'savepoint' => ''];
        }

        $savepoint = preg_replace('/[^a-z0-9_]/i', '_', $prefix)
            . '_' . bin2hex(random_bytes(6));
        \InterfaceDB::execute('SAVEPOINT ' . $savepoint);

        return ['owns_transaction' => false, 'savepoint' => $savepoint];
    }

    /** @param array{owns_transaction: bool, savepoint: string} $transaction */
    private function commitAssetMutationTransaction(array $transaction): void
    {
        if (!empty($transaction['owns_transaction'])) {
            \InterfaceDB::commit();
            return;
        }

        $savepoint = trim((string)($transaction['savepoint'] ?? ''));
        if ($savepoint !== '') {
            \InterfaceDB::execute('RELEASE SAVEPOINT ' . $savepoint);
        }
    }

    /** @param array{owns_transaction: bool, savepoint: string} $transaction */
    private function rollBackAssetMutationTransaction(array $transaction): void
    {
        if (!empty($transaction['owns_transaction'])) {
            if (\InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            return;
        }

        $savepoint = trim((string)($transaction['savepoint'] ?? ''));
        if ($savepoint !== '' && \InterfaceDB::inTransaction()) {
            \InterfaceDB::execute('ROLLBACK TO SAVEPOINT ' . $savepoint);
            \InterfaceDB::execute('RELEASE SAVEPOINT ' . $savepoint);
        }
    }

    private function postAssetDisposalJournalAndStatus(
        int $companyId,
        array $asset,
        int $accountingPeriodId,
        string $disposalDate,
        float $proceeds,
        ?int $clearingNominalId,
        string $disposalEventType,
        string $disposalReason
    ): array {
        $assetId = (int)$asset['id'];
        $proceeds = round(max(0.0, $proceeds), 2);
        $this->postPendingDepreciationThroughDisposalDate(
            $companyId,
            $asset,
            $accountingPeriodId,
            $disposalDate
        );
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

        $this->markAssetDisposed($companyId, $assetId, $disposalDate, $proceeds, $disposalEventType, $disposalReason);

        return [
            'nbv' => $nbv,
            'proceeds' => $proceeds,
            'profit' => $profit,
        ];
    }

    private function postPendingDepreciationThroughDisposalDate(
        int $companyId,
        array $asset,
        int $accountingPeriodId,
        string $disposalDate
    ): void {
        if ((string)($asset['depreciation_method'] ?? 'straight_line') === 'none') {
            return;
        }

        $assetId = (int)($asset['id'] ?? 0);
        $purchaseDate = trim((string)($asset['purchase_date'] ?? ''));
        if ($assetId <= 0 || !$this->isIsoDate($purchaseDate) || !$this->isIsoDate($disposalDate)) {
            return;
        }

        $depreciationEnd = min(
            $disposalDate,
            $this->usefulLifeEndDate($purchaseDate, (int)($asset['useful_life_years'] ?? 1))
        );
        if ($depreciationEnd < $purchaseDate) {
            return;
        }

        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            throw new \RuntimeException('The disposal accounting period could not be loaded for depreciation.');
        }

        $accountingPeriodStart = trim((string)($accountingPeriod['period_start'] ?? ''));
        if (!$this->isIsoDate($accountingPeriodStart)) {
            throw new \RuntimeException('The disposal accounting period has an invalid start date.');
        }

        $this->assertPriorPeriodDepreciationPosted(
            $companyId,
            $asset,
            $accountingPeriodStart
        );

        $periodStart = max($purchaseDate, $accountingPeriodStart);
        if ($periodStart > $depreciationEnd) {
            return;
        }

        $expectedPeriodAmount = $this->calculateExpectedDepreciationForInterval(
            $asset,
            $periodStart,
            $depreciationEnd
        );
        $postDisposalDepreciation = round((float)\InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(amount), 0)
             FROM asset_depreciation_entries
             WHERE asset_id = :asset_id
               AND accounting_period_id = :accounting_period_id
               AND period_end > :depreciation_end',
            [
                'asset_id' => $assetId,
                'accounting_period_id' => $accountingPeriodId,
                'depreciation_end' => $depreciationEnd,
            ]
        ), 2);
        if ($postDisposalDepreciation >= 0.005) {
            throw new \RuntimeException(
                'Depreciation is already posted after the disposal date in the disposal accounting period. '
                . 'Correct that depreciation before disposing the asset.'
            );
        }
        $postedPeriodAmount = $this->sumDepreciationForAccountingPeriod(
            $assetId,
            $accountingPeriodId
        );
        if ($postedPeriodAmount - $expectedPeriodAmount >= 0.005) {
            throw new \RuntimeException(
                'Depreciation already posted in the disposal accounting period exceeds the exact charge through '
                . $disposalDate
                . '. Correct that depreciation before disposing the asset.'
            );
        }

        $pendingAmount = round(max(0.0, $expectedPeriodAmount - $postedPeriodAmount), 2);
        if ($pendingAmount < 0.005) {
            return;
        }

        $this->postDepreciationCharge(
            $companyId,
            $asset,
            $accountingPeriodId,
            $periodStart,
            $depreciationEnd,
            $pendingAmount,
            true
        );
    }

    private function assertPriorPeriodDepreciationPosted(
        int $companyId,
        array $asset,
        string $currentPeriodStart
    ): void {
        $assetId = (int)($asset['id'] ?? 0);
        $purchaseDate = trim((string)($asset['purchase_date'] ?? ''));
        if ($assetId <= 0 || !$this->isIsoDate($purchaseDate) || !$this->isIsoDate($currentPeriodStart)) {
            return;
        }

        $priorPeriodEnd = min(
            (new \DateTimeImmutable($currentPeriodStart))->modify('-1 day')->format('Y-m-d'),
            $this->usefulLifeEndDate($purchaseDate, (int)($asset['useful_life_years'] ?? 1))
        );
        if ($priorPeriodEnd < $purchaseDate) {
            return;
        }

        $priorPeriods = \InterfaceDB::fetchAll(
            'SELECT id, label, period_start, period_end
             FROM accounting_periods
             WHERE company_id = :company_id
               AND period_start <= :prior_period_end
               AND period_end >= :purchase_date
               AND period_end < :current_period_start
             ORDER BY period_start ASC, id ASC',
            [
                'company_id' => $companyId,
                'prior_period_end' => $priorPeriodEnd,
                'purchase_date' => $purchaseDate,
                'current_period_start' => $currentPeriodStart,
            ]
        ) ?: [];
        foreach ($priorPeriods as $priorPeriod) {
            $periodStart = max($purchaseDate, (string)($priorPeriod['period_start'] ?? ''));
            $periodEnd = min($priorPeriodEnd, (string)($priorPeriod['period_end'] ?? ''));
            if ($periodEnd < $periodStart) {
                continue;
            }

            $expectedPeriodAmount = $this->calculateExpectedDepreciationForInterval(
                $asset,
                $periodStart,
                $periodEnd
            );
            $postedPeriodAmount = $this->sumDepreciationForAccountingPeriod(
                $assetId,
                (int)($priorPeriod['id'] ?? 0)
            );
            $variance = round($expectedPeriodAmount - $postedPeriodAmount, 2);
            if (abs($variance) < 0.005) {
                continue;
            }

            $periodLabel = trim((string)($priorPeriod['label'] ?? ''));
            if ($periodLabel === '') {
                $periodLabel = $periodStart . ' to ' . $periodEnd;
            }
            if ($variance > 0) {
                throw new \RuntimeException(
                    'Depreciation of £'
                    . number_format($variance, 2, '.', ',')
                    . ' remains unposted in prior accounting period '
                    . $periodLabel
                    . '. Post or correct prior-period depreciation before disposing this asset; '
                    . 'it will not be moved into the disposal period.'
                );
            }

            throw new \RuntimeException(
                'Depreciation in prior accounting period '
                . $periodLabel
                . ' exceeds the exact charge by £'
                . number_format(abs($variance), 2, '.', ',')
                . '. Correct prior-period depreciation before disposing this asset.'
            );
        }

        $expectedPriorAmount = $this->calculateDepreciationToDateAmount($asset, $priorPeriodEnd);
        $postedPriorAmount = round((float)\InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(ade.amount), 0)
             FROM asset_depreciation_entries ade
             INNER JOIN accounting_periods ap ON ap.id = ade.accounting_period_id
             WHERE ade.asset_id = :asset_id
               AND ap.company_id = :company_id
               AND ap.period_end < :current_period_start
               AND ade.period_end <= :prior_period_end',
            [
                'asset_id' => $assetId,
                'company_id' => $companyId,
                'current_period_start' => $currentPeriodStart,
                'prior_period_end' => $priorPeriodEnd,
            ]
        ), 2);
        $unallocatedPriorAmount = round($expectedPriorAmount - $postedPriorAmount, 2);
        if (abs($unallocatedPriorAmount) < 0.005) {
            return;
        }

        $direction = $unallocatedPriorAmount > 0
            ? 'remains unposted before the disposal accounting period'
            : 'is over-posted before the disposal accounting period';
        throw new \RuntimeException(
            'Depreciation of £'
            . number_format(abs($unallocatedPriorAmount), 2, '.', ',')
            . ' '
            . $direction
            . '. Create or correct the prior accounting-period allocation before disposing this asset; '
            . 'prior-period depreciation will not be moved into the disposal period.'
        );
    }

    private function postDepreciationCharge(
        int $companyId,
        array $asset,
        int $accountingPeriodId,
        string $periodStart,
        string $periodEnd,
        float $amount,
        bool $throughDisposal = false
    ): void {
        $assetId = (int)($asset['id'] ?? 0);
        $amount = round($amount, 2);
        if ($assetId <= 0 || $amount < 0.005) {
            return;
        }

        $isAdjustment = $this->sumDepreciationForAccountingPeriod($assetId, $accountingPeriodId) >= 0.005;
        $entryPeriodStart = $periodStart;
        $entryPeriodEnd = $periodEnd;
        if ($isAdjustment) {
            $entryPeriodStart = $this->availableDepreciationAdjustmentDate(
                $assetId,
                $accountingPeriodId,
                $periodStart,
                $periodEnd
            );
            $entryPeriodEnd = $entryPeriodStart;
        }

        $sourceRef = 'asset:' . $assetId
            . ':depreciation:' . $accountingPeriodId
            . ':' . $periodStart
            . ':' . $periodEnd;
        if ($isAdjustment) {
            $sourceRef .= ':topup:' . $entryPeriodStart;
        }
        if ($throughDisposal) {
            $sourceRef .= ':disposal';
        }

        $assetCode = (string)($asset['asset_code'] ?? '');
        $journalId = $this->insertJournal([
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'asset_depreciation',
            'source_ref' => $sourceRef,
            'journal_date' => $periodEnd,
            'description' => ($throughDisposal
                ? 'Depreciation to disposal '
                : ($isAdjustment ? 'Depreciation top-up ' : 'Depreciation ')
            ) . $assetCode,
        ]);
        $lineDescription = 'Depreciation ' . $assetCode . ' ' . $periodStart . ' to ' . $periodEnd;
        if ($isAdjustment) {
            $lineDescription .= ' (top-up adjustment)';
        }
        $this->insertJournalLine(
            $journalId,
            $this->findNominalIdByCode('6200'),
            $amount,
            0.0,
            $lineDescription
        );
        $this->insertJournalLine(
            $journalId,
            (int)$asset['accum_dep_nominal_id'],
            0.0,
            $amount,
            $lineDescription
        );

        \InterfaceDB::prepareExecute(
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
             )',
            [
                'asset_id' => $assetId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $entryPeriodStart,
                'period_end' => $entryPeriodEnd,
                'amount' => $amount,
                'journal_id' => $journalId,
            ]
        );
    }

    private function availableDepreciationAdjustmentDate(
        int $assetId,
        int $accountingPeriodId,
        string $periodStart,
        string $periodEnd
    ): string {
        $candidate = $periodEnd;
        while ($candidate >= $periodStart && $this->depreciationEntryExists(
            $assetId,
            $accountingPeriodId,
            $candidate,
            $candidate
        )) {
            $candidate = (new \DateTimeImmutable($candidate))->modify('-1 day')->format('Y-m-d');
        }

        if ($candidate >= $periodStart) {
            return $candidate;
        }

        throw new \RuntimeException(
            'The depreciation residual could not be recorded as a distinct adjustment. '
            . 'Correct the existing depreciation entries before posting again.'
        );
    }

    private function markAssetDisposed(int $companyId, int $assetId, string $disposalDate, float $proceeds, string $disposalEventType, string $disposalReason): void
    {
        $stmt = \InterfaceDB::prepare(
            'UPDATE asset_register
             SET status = :status,
                 disposal_date = :disposal_date,
                 disposal_proceeds = :disposal_proceeds,
                 disposal_event_type = :disposal_event_type,
                 disposal_reason = :disposal_reason,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND company_id = :company_id'
        );
        $stmt->execute([
            'status' => 'disposed',
            'disposal_date' => $disposalDate,
            'disposal_proceeds' => round($proceeds, 2),
            'disposal_event_type' => trim($disposalEventType) !== '' ? trim($disposalEventType) : null,
            'disposal_reason' => trim($disposalReason) !== '' ? trim($disposalReason) : null,
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

    private function assetsWithPeriodDepreciation(array $assets, int $companyId, int $accountingPeriodId): array {
        if ($assets === [] || $companyId <= 0 || $accountingPeriodId <= 0) {
            return $assets;
        }

        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return $assets;
        }

        $openingDepreciationByAssetId = $this->openingDepreciationByAsset($assets, $accountingPeriod);

        return array_map(
            function (array $asset) use ($accountingPeriod, $openingDepreciationByAssetId): array {
                $assetId = (int)($asset['id'] ?? 0);
                $asset['period_depreciation'] = $this->calculatePeriodDepreciationAmount(
                    $asset,
                    $accountingPeriod,
                    $openingDepreciationByAssetId[$assetId] ?? null
                );
                $asset['resale_value'] = $this->calculateResaleValue($asset, $accountingPeriod);

                return $asset;
            },
            $assets
        );
    }

    private function openingDepreciationByAsset(array $assets, array $accountingPeriod): array {
        $periodStart = trim((string)($accountingPeriod['period_start'] ?? ''));
        if (!$this->isIsoDate($periodStart)) {
            return [];
        }

        $cutoffByAssetId = [];
        $maxCutoff = '';
        foreach ($assets as $asset) {
            $asset = is_array($asset) ? $asset : [];
            $assetId = (int)($asset['id'] ?? 0);
            $purchaseDate = trim((string)($asset['purchase_date'] ?? ''));
            if ($assetId <= 0 || !$this->isIsoDate($purchaseDate)) {
                continue;
            }

            $depreciationPeriodStart = max($periodStart, $purchaseDate);
            $cutoff = (new \DateTimeImmutable($depreciationPeriodStart))->modify('-1 day')->format('Y-m-d');
            $cutoffByAssetId[$assetId] = $cutoff;
            $maxCutoff = $maxCutoff === '' ? $cutoff : max($maxCutoff, $cutoff);
        }

        if ($cutoffByAssetId === [] || $maxCutoff === '') {
            return [];
        }

        $totals = array_fill_keys(array_keys($cutoffByAssetId), 0.0);
        foreach (array_chunk(array_keys($cutoffByAssetId), 500) as $chunkIndex => $assetIds) {
            $params = ['max_period_end' => $maxCutoff];
            $placeholders = [];
            foreach ($assetIds as $index => $assetId) {
                $placeholder = 'asset_id_' . $chunkIndex . '_' . $index;
                $placeholders[] = ':' . $placeholder;
                $params[$placeholder] = $assetId;
            }

            foreach (\InterfaceDB::fetchAll(
                'SELECT asset_id, period_end, amount
                 FROM asset_depreciation_entries
                 WHERE asset_id IN (' . implode(', ', $placeholders) . ')
                   AND period_end <= :max_period_end',
                $params
            ) ?: [] as $row) {
                $assetId = (int)($row['asset_id'] ?? 0);
                $periodEnd = trim((string)($row['period_end'] ?? ''));
                if ($assetId <= 0 || $periodEnd === '' || $periodEnd > (string)($cutoffByAssetId[$assetId] ?? '')) {
                    continue;
                }

                $totals[$assetId] = round((float)($totals[$assetId] ?? 0.0) + (float)($row['amount'] ?? 0), 2);
            }
        }

        return $totals;
    }

    private function calculatePeriodDepreciationAmount(array $asset, array $accountingPeriod, ?float $openingDepreciation = null): float {
        $periodStart = trim((string)($accountingPeriod['period_start'] ?? ''));
        $periodEnd = trim((string)($accountingPeriod['period_end'] ?? ''));
        $purchaseDate = trim((string)($asset['purchase_date'] ?? ''));
        if (!$this->isIsoDate($periodStart) || !$this->isIsoDate($periodEnd) || !$this->isIsoDate($purchaseDate)) {
            return 0.0;
        }

        $depreciationPeriodStart = max($periodStart, $purchaseDate);
        $depreciationPeriodEnd = $this->periodDepreciationReferenceEnd($periodEnd);
        $disposalDate = trim((string)($asset['disposal_date'] ?? ''));
        if ((string)($asset['status'] ?? 'active') === 'disposed' && $this->isIsoDate($disposalDate)) {
            $depreciationPeriodEnd = min($depreciationPeriodEnd, $disposalDate);
        }

        if ($depreciationPeriodEnd < $depreciationPeriodStart) {
            return 0.0;
        }

        if ($openingDepreciation === null) {
            $openingDepreciation = $this->sumDepreciationToDate(
                (int)($asset['id'] ?? 0),
                (new \DateTimeImmutable($depreciationPeriodStart))->modify('-1 day')->format('Y-m-d')
            );
        }

        return $this->calculateDepreciationAmountFromOpening(
            $asset,
            $depreciationPeriodStart,
            $depreciationPeriodEnd,
            $openingDepreciation
        );
    }

    private function periodDepreciationReferenceEnd(string $periodEnd): string {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        return $periodEnd < $today ? $periodEnd : $today;
    }

    private function calculateResaleValue(array $asset, array $accountingPeriod): float {
        $periodEnd = trim((string)($accountingPeriod['period_end'] ?? ''));
        $purchaseDate = trim((string)($asset['purchase_date'] ?? ''));
        if (!$this->isIsoDate($periodEnd) || !$this->isIsoDate($purchaseDate)) {
            return round((float)($asset['cost'] ?? 0), 2);
        }

        $referenceEnd = $this->periodDepreciationReferenceEnd($periodEnd);
        $disposalDate = trim((string)($asset['disposal_date'] ?? ''));
        if ((string)($asset['status'] ?? 'active') === 'disposed' && $this->isIsoDate($disposalDate)) {
            $referenceEnd = min($referenceEnd, $disposalDate);
        }

        $depreciationToDate = $this->calculateDepreciationToDateAmount($asset, $referenceEnd);
        $cost = round((float)($asset['cost'] ?? 0), 2);
        $residual = round((float)($asset['residual_value'] ?? 0), 2);

        return round(max($residual, $cost - $depreciationToDate), 2);
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
        if ((string)($asset['depreciation_method'] ?? 'straight_line') === 'straight_line') {
            $boundedPeriodEnd = $this->boundedDepreciationPeriodEnd($asset, $periodStart, $periodEnd);
            if ($boundedPeriodEnd === null) {
                return 0.0;
            }

            $targetDepreciation = $this->calculateDepreciationToDateAmount($asset, $boundedPeriodEnd);
            $postedDepreciation = $this->sumDepreciationToDate((int)$asset['id'], $boundedPeriodEnd);

            return round(max(0.0, $targetDepreciation - $postedDepreciation), 2);
        }

        $openingDepreciation = $this->sumDepreciationToDate((int)$asset['id'], (new \DateTimeImmutable($periodStart))->modify('-1 day')->format('Y-m-d'));

        return $this->calculateDepreciationAmountFromOpening($asset, $periodStart, $periodEnd, $openingDepreciation);
    }

    private function calculateDepreciationAmountFromOpening(array $asset, string $periodStart, string $periodEnd, float $openingDepreciation): float {
        $method = (string)($asset['depreciation_method'] ?? 'straight_line');
        if ($method === 'none') {
            return 0.0;
        }

        $boundedPeriodEnd = $this->boundedDepreciationPeriodEnd($asset, $periodStart, $periodEnd);
        if ($boundedPeriodEnd === null) {
            return 0.0;
        }
        $periodEnd = $boundedPeriodEnd;

        $cost = round((float)($asset['cost'] ?? 0), 2);
        $residual = round((float)($asset['residual_value'] ?? 0), 2);
        $lifeYears = max(1, (int)($asset['useful_life_years'] ?? 1));

        if ($method === 'straight_line') {
            $purchaseDate = trim((string)($asset['purchase_date'] ?? ''));
            if (!$this->isIsoDate($purchaseDate)) {
                return 0.0;
            }

            $depreciableAmount = max(0.0, $cost - $residual);
            $lifeEnd = $this->usefulLifeEndDate($purchaseDate, $lifeYears);
            $lifeDays = max(1, $this->dateDiffDaysInclusive($purchaseDate, $lifeEnd));
            $elapsedDays = max(0, $this->dateDiffDaysInclusive($purchaseDate, $periodEnd));
            $cumulativeTarget = round(
                min($depreciableAmount, $depreciableAmount * ($elapsedDays / $lifeDays)),
                2
            );
            $remainingCap = max(0.0, $depreciableAmount - $openingDepreciation);

            return round(max(0.0, min($remainingCap, $cumulativeTarget - $openingDepreciation)), 2);
        }

        $daysInPeriod = max(1, $this->dateDiffDaysInclusive($periodStart, $periodEnd));
        $yearDays = max(365, $this->dateDiffDaysInclusive(
            (new \DateTimeImmutable($periodStart))->format('Y-01-01'),
            (new \DateTimeImmutable($periodStart))->format('Y-12-31')
        ));

        if ($method === 'reducing_balance') {
            $openingNbv = max($residual, $cost - $openingDepreciation);
            $annualAmount = $openingNbv * (1 / $lifeYears);
        }

        $remainingCap = max(0.0, ($cost - $residual) - $openingDepreciation);
        return round(min($remainingCap, $annualAmount * ($daysInPeriod / $yearDays)), 2);
    }

    private function calculateDepreciationToDateAmount(array $asset, string $referenceEnd): float {
        $method = (string)($asset['depreciation_method'] ?? 'straight_line');
        $purchaseDate = trim((string)($asset['purchase_date'] ?? ''));
        if ($method === 'none' || !$this->isIsoDate($purchaseDate) || !$this->isIsoDate($referenceEnd) || $referenceEnd < $purchaseDate) {
            return 0.0;
        }

        $boundedReferenceEnd = $this->boundedDepreciationPeriodEnd($asset, $purchaseDate, $referenceEnd);
        if ($boundedReferenceEnd === null) {
            return 0.0;
        }

        $cost = round((float)($asset['cost'] ?? 0), 2);
        $residual = round((float)($asset['residual_value'] ?? 0), 2);
        $lifeYears = max(1, (int)($asset['useful_life_years'] ?? 1));
        $depreciableAmount = max(0.0, $cost - $residual);

        if ($method === 'straight_line') {
            $lifeDays = max(1, $this->dateDiffDaysInclusive($purchaseDate, $this->usefulLifeEndDate($purchaseDate, $lifeYears)));
            $elapsedDays = max(0, $this->dateDiffDaysInclusive($purchaseDate, $boundedReferenceEnd));

            return round(min($depreciableAmount, $depreciableAmount * ($elapsedDays / $lifeDays)), 2);
        }

        $total = 0.0;
        $periodStart = $purchaseDate;
        while ($periodStart <= $boundedReferenceEnd) {
            $yearEnd = (new \DateTimeImmutable($periodStart))->format('Y-12-31');
            $periodEnd = min($boundedReferenceEnd, $yearEnd);
            $amount = $this->calculateDepreciationAmountFromOpening($asset, $periodStart, $periodEnd, $total);
            if ($amount <= 0.0) {
                break;
            }

            $total = round(min($depreciableAmount, $total + $amount), 2);
            $periodStart = (new \DateTimeImmutable($periodEnd))->modify('+1 day')->format('Y-m-d');
        }

        return $total;
    }

    private function boundedDepreciationPeriodEnd(array $asset, string $periodStart, string $periodEnd): ?string {
        $purchaseDate = trim((string)($asset['purchase_date'] ?? ''));
        if (!$this->isIsoDate($purchaseDate) || !$this->isIsoDate($periodStart) || !$this->isIsoDate($periodEnd)) {
            return null;
        }

        $usefulLifeEnd = $this->usefulLifeEndDate($purchaseDate, (int)($asset['useful_life_years'] ?? 1));
        if ($usefulLifeEnd < $periodStart) {
            return null;
        }

        return min($periodEnd, $usefulLifeEnd);
    }

    private function usefulLifeEndDate(string $purchaseDate, int $lifeYears): string {
        $lifeYears = max(1, $lifeYears);

        return (new \DateTimeImmutable($purchaseDate))
            ->modify('+' . $lifeYears . ' years')
            ->modify('-1 day')
            ->format('Y-m-d');
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

        $settings = $companyId > 0 ? (new \eel_accounts\Store\CompanySettingsStore($companyId))->all() : [];
        $nominalAccountId = (int)($settings[$category . '_asset_cost_nominal_id'] ?? 0);
        $accumDepNominalId = (int)($settings[$category . '_accum_dep_nominal_id'] ?? 0);
        if (!$this->activeNominalExists($nominalAccountId) || !$this->activeNominalExists($accumDepNominalId)) {
            $errors[] = 'Configure active cost and accumulated depreciation nominals for ' . (self::assetCategoryOptions()[$category] ?? $category) . ' in Company Nominals.';
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

    private function activeNominalExists(int $nominalId): bool
    {
        return $nominalId > 0 && (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM nominal_accounts WHERE id = :id AND is_active = 1',
            ['id' => $nominalId]
        ) > 0;
    }

    private function normaliseManualAdditionReason(string $reason): string
    {
        $reason = trim($reason);

        return array_key_exists($reason, self::manualAdditionReasonOptions()) ? $reason : '';
    }

    private function manualAdditionReasonLabel(string $reason): string
    {
        $options = self::manualAdditionReasonOptions();

        return (string)($options[$reason] ?? $reason);
    }

    private function manualAssetReconciliationReasons(): array
    {
        return [
            self::MANUAL_ASSET_REASON_SUPPLIER_PENDING,
            self::MANUAL_ASSET_REASON_PERSONAL_PENDING,
            self::MANUAL_ASSET_REASON_DELAYED_BANK_CSV,
        ];
    }

    private function manualAssetRequiresReconciliation(string $reason): bool
    {
        return in_array($reason, $this->manualAssetReconciliationReasons(), true);
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function manualAssetReconciliationWindow(string $purchaseDate): array
    {
        $date = new \DateTimeImmutable($purchaseDate);

        return [
            'start' => $date->modify('-' . self::MANUAL_ASSET_RECONCILE_DAYS_BEFORE . ' days')->format('Y-m-d'),
            'end' => $date->modify('+' . self::MANUAL_ASSET_RECONCILE_DAYS_AFTER . ' days')->format('Y-m-d'),
        ];
    }

    private function insertAssetRecord(array $values, array $links): void {
        $columns = [
            'company_id',
            'asset_code',
            'description',
            'category',
            'nominal_account_id',
            'accum_dep_nominal_id',
            'purchase_date',
            'cost',
            'useful_life_years',
            'depreciation_method',
            'residual_value',
            'status',
            'linked_journal_id',
            'linked_transaction_id',
            'linked_expense_claim_line_id',
            'disposal_date',
            'disposal_proceeds',
            'disposal_event_type',
            'disposal_reason',
            'created_at',
            'updated_at',
        ];
        $placeholders = [
            ':company_id',
            ':asset_code',
            ':description',
            ':category',
            ':nominal_account_id',
            ':accum_dep_nominal_id',
            ':purchase_date',
            ':cost',
            ':useful_life_years',
            ':depreciation_method',
            ':residual_value',
            ':status',
            ':linked_journal_id',
            ':linked_transaction_id',
            ':linked_expense_claim_line_id',
            'NULL',
            'NULL',
            'NULL',
            'NULL',
            'CURRENT_TIMESTAMP',
            'CURRENT_TIMESTAMP',
        ];
        $params = [
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
        ];

        if (\InterfaceDB::columnExists('asset_register', 'linked_transaction_split_line_id')) {
            $insertAt = array_search('disposal_date', $columns, true);
            $insertAt = $insertAt === false ? count($columns) : (int)$insertAt;
            array_splice($columns, $insertAt, 0, ['linked_transaction_split_line_id']);
            array_splice($placeholders, $insertAt, 0, [':linked_transaction_split_line_id']);
            $params['linked_transaction_split_line_id'] = $links['linked_transaction_split_line_id'] ?? null;
        }

        if ($this->hasManualAssetSchema()) {
            $insertAt = array_search('disposal_date', $columns, true);
            $insertAt = $insertAt === false ? count($columns) : (int)$insertAt;
            array_splice($columns, $insertAt, 0, [
                'manual_addition_reason',
                'manual_offset_nominal_id',
                'manual_evidence_path',
                'manual_evidence_sha256',
                'manual_evidence_original_filename',
                'manual_evidence_content_type',
                'manual_evidence_size_bytes',
                'manual_legal_warning_version',
                'manual_legal_acknowledged_at',
            ]);
            array_splice($placeholders, $insertAt, 0, [
                ':manual_addition_reason',
                ':manual_offset_nominal_id',
                ':manual_evidence_path',
                ':manual_evidence_sha256',
                ':manual_evidence_original_filename',
                ':manual_evidence_content_type',
                ':manual_evidence_size_bytes',
                ':manual_legal_warning_version',
                ':manual_legal_acknowledged_at',
            ]);
            $params['manual_addition_reason'] = $links['manual_addition_reason'] ?? null;
            $params['manual_offset_nominal_id'] = $links['manual_offset_nominal_id'] ?? null;
            $params['manual_evidence_path'] = $links['manual_evidence_path'] ?? null;
            $params['manual_evidence_sha256'] = $links['manual_evidence_sha256'] ?? null;
            $params['manual_evidence_original_filename'] = $links['manual_evidence_original_filename'] ?? null;
            $params['manual_evidence_content_type'] = $links['manual_evidence_content_type'] ?? null;
            $params['manual_evidence_size_bytes'] = $links['manual_evidence_size_bytes'] ?? null;
            $params['manual_legal_warning_version'] = $links['manual_legal_warning_version'] ?? null;
            $params['manual_legal_acknowledged_at'] = $links['manual_legal_acknowledged_at'] ?? null;
        }

        $stmt = \InterfaceDB::prepare(
            'INSERT INTO asset_register (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
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
        $subtypeCodes = self::MANUAL_ASSET_OFFSET_SUBTYPE_CODES;
        $placeholders = implode(', ', array_map(static fn(int $index): string => ':subtype_' . $index, array_keys($subtypeCodes)));
        $params = ['id' => $nominalAccountId];
        foreach ($subtypeCodes as $index => $subtypeCode) {
            $params['subtype_' . $index] = $subtypeCode;
        }

        $value = \InterfaceDB::fetchColumn(
            'SELECT na.id
             FROM nominal_accounts na
             INNER JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.id = :id
               AND na.is_active = 1
               AND nas.code IN (' . $placeholders . ')
             LIMIT 1',
            $params
        );

        return $value !== false;
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
                && \InterfaceDB::tableExists('tax_loss_carryforwards')
                && \InterfaceDB::columnExists('asset_register', 'disposal_event_type')
                && \InterfaceDB::columnExists('asset_register', 'disposal_reason');
        } catch (\Throwable) {
            $this->schemaReady = false;
        }

        return $this->schemaReady;
    }

    private function hasManualAssetSchema(): bool
    {
        if ($this->manualSchemaReady !== null) {
            return $this->manualSchemaReady;
        }

        try {
            $this->manualSchemaReady = $this->hasRequiredSchema()
                && \InterfaceDB::columnExists('asset_register', 'manual_addition_reason')
                && \InterfaceDB::columnExists('asset_register', 'manual_offset_nominal_id')
                && \InterfaceDB::columnExists('asset_register', 'manual_evidence_path')
                && \InterfaceDB::columnExists('asset_register', 'manual_evidence_sha256')
                && \InterfaceDB::columnExists('asset_register', 'manual_evidence_original_filename')
                && \InterfaceDB::columnExists('asset_register', 'manual_evidence_content_type')
                && \InterfaceDB::columnExists('asset_register', 'manual_evidence_size_bytes')
                && \InterfaceDB::columnExists('asset_register', 'manual_legal_warning_version')
                && \InterfaceDB::columnExists('asset_register', 'manual_legal_acknowledged_at');
        } catch (\Throwable) {
            $this->manualSchemaReady = false;
        }

        return $this->manualSchemaReady;
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $cache[$table] = \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }

    private function transactionHasInterAccountMarker(int $transactionId): bool
    {
        return (new TransactionInterAccountMarkerService())->fetchMarkerForTransaction($transactionId) !== null;
    }
}


