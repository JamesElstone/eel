<?php
declare(strict_types=1);

final class CorporationTaxComputationService
{
    private const DEFAULT_CORPORATION_TAX_RATE = 0.19;

    public function __construct(
        private readonly ?YearEndMetricsService $metricsService = null,
    ) {
    }

    public function fetchSummary(int $companyId, int $taxYearId): array {
        $metrics = $this->metricsService ?? new YearEndMetricsService();
        $taxYear = $metrics->fetchTaxYear($companyId, $taxYearId);
        if ($taxYear === null) {
            return [
                'available' => false,
                'errors' => ['The selected accounting period could not be found.'],
            ];
        }

        $schedule = $this->rebuildLossSchedule($companyId);
        $current = $schedule[$taxYearId] ?? null;
        if ($current === null) {
            return [
                'available' => false,
                'errors' => ['The corporation tax computation could not be built for the selected period.'],
            ];
        }

        $warnings = [];
        if ((int)($current['unknown_treatment_count'] ?? 0) > 0) {
            $warnings[] = 'Some nominal tax treatments are unknown and should be reviewed before relying on the estimate.';
        }
        if ((int)($current['other_treatment_count'] ?? 0) > 0) {
            $warnings[] = 'Some nominal tax treatments are marked as other and need manual review.';
        }
        if (!empty($current['asset_adjustment_warning'])) {
            $warnings[] = (string)$current['asset_adjustment_warning'];
        }

        return [
            'available' => true,
            'accounting_profit' => round((float)$current['accounting_profit'], 2),
            'disallowable_add_backs' => round((float)$current['disallowable_add_backs'], 2),
            'depreciation_add_back' => round((float)$current['depreciation_add_back'], 2),
            'capital_allowances' => round((float)$current['capital_allowances'], 2),
            'taxable_before_losses' => round((float)$current['taxable_before_losses'], 2),
            'taxable_profit' => round((float)$current['taxable_profit'], 2),
            'taxable_loss' => round((float)$current['loss_created'], 2),
            'estimated_corporation_tax' => round((float)$current['estimated_corporation_tax'], 2),
            'estimated_rate' => self::DEFAULT_CORPORATION_TAX_RATE,
            'loss_created_in_period' => round((float)$current['loss_created'], 2),
            'losses_brought_forward' => round((float)$current['loss_brought_forward'], 2),
            'losses_used' => round((float)$current['loss_utilised'], 2),
            'losses_carried_forward' => round((float)$current['loss_carried_forward'], 2),
            'other_treatment_count' => (int)$current['other_treatment_count'],
            'unknown_treatment_count' => (int)$current['unknown_treatment_count'],
            'warnings' => $warnings,
            'steps' => [
                ['label' => 'Accounting profit or loss', 'amount' => round((float)$current['accounting_profit'], 2)],
                ['label' => 'Add back disallowable expenses', 'amount' => round((float)$current['disallowable_add_backs'], 2)],
                ['label' => 'Add back depreciation', 'amount' => round((float)$current['depreciation_add_back'], 2)],
                ['label' => 'Deduct capital allowances', 'amount' => round(0 - (float)$current['capital_allowances'], 2)],
                ['label' => 'Taxable result before losses', 'amount' => round((float)$current['taxable_before_losses'], 2)],
                ['label' => 'Less losses brought forward utilised', 'amount' => round(0 - (float)$current['loss_utilised'], 2)],
                ['label' => 'Taxable profit after losses', 'amount' => round((float)$current['taxable_profit'], 2)],
                ['label' => 'Estimated corporation tax', 'amount' => round((float)$current['estimated_corporation_tax'], 2)],
            ],
            'schedule' => array_values(array_map(
                static fn(array $row): array => [
                    'tax_year_id' => (int)$row['tax_year_id'],
                    'label' => (string)$row['label'],
                    'loss_created' => round((float)$row['loss_created'], 2),
                    'loss_brought_forward' => round((float)$row['loss_brought_forward'], 2),
                    'loss_utilised' => round((float)$row['loss_utilised'], 2),
                    'loss_carried_forward' => round((float)$row['loss_carried_forward'], 2),
                    'taxable_before_losses' => round((float)$row['taxable_before_losses'], 2),
                    'taxable_profit' => round((float)$row['taxable_profit'], 2),
                ],
                $schedule
            )),
        ];
    }

    private function rebuildLossSchedule(int $companyId): array {
        $metrics = $this->metricsService ?? new YearEndMetricsService();
        $taxYears = array_reverse($metrics->fetchTaxYears($companyId));
        if ($taxYears === []) {
            return [];
        }

        $schedule = [];
        $lossPool = [];

        $ownsTransaction = !InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            InterfaceDB::beginTransaction();
        }

        try {
            if ($this->tableExists('tax_loss_carryforwards')) {
                InterfaceDB::prepare('DELETE FROM tax_loss_carryforwards WHERE company_id = :company_id')
                    ->execute(['company_id' => $companyId]);
            }

            foreach ($taxYears as $taxYear) {
                $taxYearId = (int)($taxYear['id'] ?? 0);
                $pnl = $metrics->profitAndLossSummary(
                    $companyId,
                    $taxYearId,
                    (string)($taxYear['period_start'] ?? ''),
                    (string)($taxYear['period_end'] ?? '')
                );
                $assetAdjustments = $this->fetchAssetAdjustments($companyId, $taxYearId);
                $taxableBeforeLosses = round(
                    (float)($pnl['profit_before_tax'] ?? 0)
                    + (float)($pnl['disallowable_add_backs'] ?? 0)
                    + (float)$assetAdjustments['depreciation_add_back']
                    - (float)$assetAdjustments['capital_allowances'],
                    2
                );

                $lossBf = round(array_sum(array_column($lossPool, 'amount_remaining')), 2);
                $lossUsed = 0.0;
                if ($taxableBeforeLosses > 0 && $lossBf > 0) {
                    $remainingTaxable = $taxableBeforeLosses;
                    foreach ($lossPool as &$lossRow) {
                        if ($remainingTaxable <= 0) {
                            break;
                        }

                        $usage = min((float)$lossRow['amount_remaining'], $remainingTaxable);
                        $lossRow['amount_remaining'] = round((float)$lossRow['amount_remaining'] - $usage, 2);
                        $lossRow['amount_used'] = round((float)$lossRow['amount_used'] + $usage, 2);
                        $remainingTaxable = round($remainingTaxable - $usage, 2);
                        $lossUsed = round($lossUsed + $usage, 2);
                    }
                    unset($lossRow);
                }

                $lossCreated = $taxableBeforeLosses < 0 ? abs($taxableBeforeLosses) : 0.0;
                if ($lossCreated > 0) {
                    $lossPool[] = [
                        'origin_tax_year_id' => $taxYearId,
                        'amount_originated' => $lossCreated,
                        'amount_used' => 0.0,
                        'amount_remaining' => $lossCreated,
                    ];
                }

                $lossCf = round(array_sum(array_column($lossPool, 'amount_remaining')), 2);
                $taxableProfit = max(0.0, round($taxableBeforeLosses - $lossUsed, 2));
                $computationHash = hash('sha256', json_encode([
                    'company_id' => $companyId,
                    'tax_year_id' => $taxYearId,
                    'accounting_profit' => (float)($pnl['profit_before_tax'] ?? 0),
                    'disallowable' => (float)($pnl['disallowable_add_backs'] ?? 0),
                    'depreciation' => (float)$assetAdjustments['depreciation_add_back'],
                    'allowances' => (float)$assetAdjustments['capital_allowances'],
                    'loss_bf' => $lossBf,
                    'loss_used' => $lossUsed,
                ], JSON_UNESCAPED_SLASHES));

                $schedule[$taxYearId] = [
                    'tax_year_id' => $taxYearId,
                    'label' => (string)($taxYear['label'] ?? ''),
                    'accounting_profit' => round((float)($pnl['profit_before_tax'] ?? 0), 2),
                    'disallowable_add_backs' => round((float)($pnl['disallowable_add_backs'] ?? 0), 2),
                    'depreciation_add_back' => round((float)$assetAdjustments['depreciation_add_back'], 2),
                    'capital_allowances' => round((float)$assetAdjustments['capital_allowances'], 2),
                    'taxable_before_losses' => $taxableBeforeLosses,
                    'taxable_profit' => $taxableProfit,
                    'estimated_corporation_tax' => round($taxableProfit * self::DEFAULT_CORPORATION_TAX_RATE, 2),
                    'loss_created' => round($lossCreated, 2),
                    'loss_brought_forward' => $lossBf,
                    'loss_utilised' => $lossUsed,
                    'loss_carried_forward' => $lossCf,
                    'other_treatment_count' => (int)($pnl['other_treatment_count'] ?? 0),
                    'unknown_treatment_count' => (int)($pnl['unknown_treatment_count'] ?? 0),
                    'asset_adjustment_warning' => (string)($assetAdjustments['warning'] ?? ''),
                    'computation_hash' => $computationHash,
                ];

                $this->insertLossHistory($companyId, $taxYearId, $computationHash, $schedule[$taxYearId]);
            }

            if ($this->tableExists('tax_loss_carryforwards')) {
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
                        'origin_tax_year_id' => (int)$lossRow['origin_tax_year_id'],
                        'amount_originated' => round((float)$lossRow['amount_originated'], 2),
                        'amount_used' => round((float)$lossRow['amount_used'], 2),
                        'amount_remaining' => round((float)$lossRow['amount_remaining'], 2),
                        'status' => (float)$lossRow['amount_remaining'] > 0 ? 'open' : 'used',
                    ]);
                }
            }

            if ($ownsTransaction) {
                InterfaceDB::commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            throw $exception;
        }

        return $schedule;
    }

    private function fetchAssetAdjustments(int $companyId, int $taxYearId): array {
        if (!$this->tableExists('tax_year_adjustments')) {
            return ['depreciation_add_back' => 0.0, 'capital_allowances' => 0.0, 'warning' => ''];
        }

        $rows = InterfaceDB::fetchAll( 'SELECT type,
                    direction,
                    COALESCE(SUM(amount), 0) AS total_amount
             FROM tax_year_adjustments
             WHERE company_id = :company_id
               AND tax_year_id = :tax_year_id
             GROUP BY type, direction', [
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
        ]);

        $depreciation = 0.0;
        $allowances = 0.0;
        foreach ($rows as $row) {
            $type = (string)($row['type'] ?? '');
            $direction = (string)($row['direction'] ?? '');
            $amount = round((float)($row['total_amount'] ?? 0), 2);

            if ($type === 'add_back_depreciation') {
                $depreciation += $direction === 'deduct' ? 0 - $amount : $amount;
            }
            if ($type === 'capital_allowances') {
                $allowances += $direction === 'add' ? 0 - $amount : $amount;
            }
        }

        $warning = '';
        if ($this->tableExists('asset_register') && $this->countCompanyAssets($companyId) > 0 && abs($depreciation) < 0.005 && abs($allowances) < 0.005) {
            $warning = 'Fixed assets exist, but no current tax-year adjustments were found. Refresh the Assets tax view if capital allowances are expected.';
        }

        return [
            'depreciation_add_back' => round(max(0.0, $depreciation), 2),
            'capital_allowances' => round(max(0.0, $allowances), 2),
            'warning' => $warning,
        ];
    }

    private function insertLossHistory(int $companyId, int $taxYearId, string $computationHash, array $row): void {
        if (!$this->tableExists('tax_loss_movement_history')) {
            return;
        }

        $delete = InterfaceDB::prepare(
            'DELETE FROM tax_loss_movement_history
             WHERE company_id = :company_id
               AND tax_year_id = :tax_year_id
               AND computation_hash = :computation_hash'
        );
        $delete->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'computation_hash' => $computationHash,
        ]);

        $insert = InterfaceDB::prepare(
            'INSERT INTO tax_loss_movement_history (
                company_id,
                tax_year_id,
                computation_hash,
                loss_created,
                loss_brought_forward,
                loss_utilised,
                loss_carried_forward,
                taxable_before_losses,
                taxable_profit,
                computed_at
             ) VALUES (
                :company_id,
                :tax_year_id,
                :computation_hash,
                :loss_created,
                :loss_brought_forward,
                :loss_utilised,
                :loss_carried_forward,
                :taxable_before_losses,
                :taxable_profit,
                CURRENT_TIMESTAMP
             )'
        );
        $insert->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'computation_hash' => $computationHash,
            'loss_created' => round((float)($row['loss_created'] ?? 0), 2),
            'loss_brought_forward' => round((float)($row['loss_brought_forward'] ?? 0), 2),
            'loss_utilised' => round((float)($row['loss_utilised'] ?? 0), 2),
            'loss_carried_forward' => round((float)($row['loss_carried_forward'] ?? 0), 2),
            'taxable_before_losses' => round((float)($row['taxable_before_losses'] ?? 0), 2),
            'taxable_profit' => round((float)($row['taxable_profit'] ?? 0), 2),
        ]);
    }

    private function countCompanyAssets(int $companyId): int {
        if (!$this->tableExists('asset_register')) {
            return 0;
        }

        return InterfaceDB::countWhere('asset_register', 'company_id', $companyId);
    }

    private function tableExists(string $table): bool {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $cache[$table] = InterfaceDB::tableExists($table);
        } catch (Throwable) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }
}


