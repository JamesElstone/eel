<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class YearEndClosePreviewService
{
    public const ASSET_DEPRECIATION_SOURCE_TYPE = 'asset_depreciation';

    public function depreciationExpenseForPeriod(
        int $companyId,
        int $accountingPeriodId,
        string $periodStart = '',
        string $periodEnd = '',
        ?array $depreciationPreview = null
    ): float
    {
        $total = 0.0;
        foreach ($this->depreciationRowsForPeriod(
            $companyId,
            $accountingPeriodId,
            $periodStart,
            $periodEnd,
            $depreciationPreview
        ) as $row) {
            $total = round($total + (float)($row['amount'] ?? 0), 2);
        }

        return round(max(0.0, $total), 2);
    }

    public function depreciationRowsForPeriod(
        int $companyId,
        int $accountingPeriodId,
        string $periodStart = '',
        string $periodEnd = '',
        ?array $depreciationPreview = null
    ): array
    {
        $rows = array_merge(
            $this->postedDepreciationRows($companyId, $accountingPeriodId),
            $this->pendingDepreciationRows($companyId, $accountingPeriodId, $depreciationPreview)
        );

        $allocatedRows = [];
        foreach ($rows as $row) {
            $amount = round((float)($row['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $entryStart = (string)($row['period_start'] ?? '');
            $entryEnd = (string)($row['period_end'] ?? '');
            if ($periodStart !== '' && $periodEnd !== '') {
                $entryDays = $this->periodDays($entryStart, $entryEnd);
                $overlapDays = $this->overlapDays($entryStart, $entryEnd, $periodStart, $periodEnd);
                if ($entryDays <= 0 || $overlapDays <= 0) {
                    continue;
                }
                $amount = round($amount * ($overlapDays / $entryDays), 2);
            }

            if ($amount <= 0) {
                continue;
            }

            $row['amount'] = $amount;
            $allocatedRows[] = $row;
        }

        return $allocatedRows;
    }

    /**
     * Allocate the canonical depreciation preview across calendar months while
     * preserving the exact period total after per-month rounding.
     *
     * @return array<string, float> keyed by YYYY-MM-01
     */
    public function monthlyDepreciationExpenseForPeriod(
        int $companyId,
        int $accountingPeriodId,
        string $periodStart,
        string $periodEnd
    ): array {
        if ($this->periodDays($periodStart, $periodEnd) <= 0) {
            return [];
        }

        $months = [];
        $cursor = (new \DateTimeImmutable($periodStart))->modify('first day of this month');
        $lastMonth = (new \DateTimeImmutable($periodEnd))->modify('first day of this month');
        while ($cursor <= $lastMonth) {
            $months[$cursor->format('Y-m-01')] = 0.0;
            $cursor = $cursor->modify('+1 month');
        }

        foreach ($this->depreciationRowsForPeriod($companyId, $accountingPeriodId) as $row) {
            $entryStart = (string)($row['period_start'] ?? '');
            $entryEnd = (string)($row['period_end'] ?? '');
            $entryDays = $this->periodDays($entryStart, $entryEnd);
            $requestedOverlapDays = $this->overlapDays($entryStart, $entryEnd, $periodStart, $periodEnd);
            if ($entryDays <= 0 || $requestedOverlapDays <= 0) {
                continue;
            }

            $rowAmount = round((float)($row['amount'] ?? 0), 2);
            $expectedTotal = round($rowAmount * ($requestedOverlapDays / $entryDays), 2);
            $allocatedTotal = 0.0;
            $lastAllocatedMonth = '';

            foreach (array_keys($months) as $monthStart) {
                $monthEnd = (new \DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
                $overlapDays = $this->overlapDays(
                    $entryStart,
                    $entryEnd,
                    max($periodStart, $monthStart),
                    min($periodEnd, $monthEnd)
                );
                if ($overlapDays <= 0) {
                    continue;
                }

                $amount = round($rowAmount * ($overlapDays / $entryDays), 2);
                $months[$monthStart] = round($months[$monthStart] + $amount, 2);
                $allocatedTotal = round($allocatedTotal + $amount, 2);
                $lastAllocatedMonth = $monthStart;
            }

            $roundingResidual = round($expectedTotal - $allocatedTotal, 2);
            if ($lastAllocatedMonth !== '' && abs($roundingResidual) >= 0.005) {
                $months[$lastAllocatedMonth] = round($months[$lastAllocatedMonth] + $roundingResidual, 2);
            }
        }

        return $months;
    }

    public function pendingBalanceSheetAdjustments(
        int $companyId,
        int $accountingPeriodId,
        string $periodEnd,
        ?array $depreciationPreview = null,
        ?array $prepaymentPreview = null,
        ?float $profitBeforeTax = null
    ): array
    {
        return (array)($this->pendingBalanceSheetAdjustmentContext(
            $companyId,
            $accountingPeriodId,
            $periodEnd,
            $depreciationPreview,
            $prepaymentPreview,
            $profitBeforeTax
        )['adjustments'] ?? []);
    }

    public function pendingBalanceSheetAdjustmentContext(
        int $companyId,
        int $accountingPeriodId,
        string $periodEnd,
        ?array $depreciationPreview = null,
        ?array $prepaymentPreview = null,
        ?float $profitBeforeTax = null,
        bool $includePriorOpenPeriods = true
    ): array {
        $targetPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($targetPeriod === null || (string)($targetPeriod['period_end'] ?? '') === '') {
            return [
                'adjustments' => [],
                'reliable' => false,
                'warnings' => ['The selected accounting period could not be resolved for closing previews.'],
                'periods' => [],
            ];
        }

        $targetPeriodEnd = (string)$targetPeriod['period_end'];
        if ($periodEnd !== '' && $periodEnd < $targetPeriodEnd) {
            return [
                'adjustments' => [],
                'reliable' => true,
                'warnings' => [],
                'periods' => [],
            ];
        }

        $periods = $includePriorOpenPeriods
            ? (\InterfaceDB::fetchAll(
                'SELECT id, company_id, period_start, period_end
                 FROM accounting_periods
                 WHERE company_id = :company_id
                   AND period_end <= :period_end
                 ORDER BY period_end ASC, id ASC',
                ['company_id' => $companyId, 'period_end' => $targetPeriodEnd]
            ) ?: [])
            : [$targetPeriod];
        $lockService = new YearEndLockService();
        $adjustments = [];
        $warnings = [];
        $periodContexts = [];
        $reliable = true;
        $previewedDirectorLoanReclassificationByDirector = [];

        foreach ($periods as $period) {
            $periodId = (int)($period['id'] ?? 0);
            if ($periodId <= 0 || $lockService->isLocked($companyId, $periodId)) {
                continue;
            }

            $isTarget = $periodId === $accountingPeriodId;
            try {
                $periodDepreciationPreview = $isTarget && $depreciationPreview !== null
                    ? $depreciationPreview
                    : (new AssetService())->previewDepreciationRun($companyId, $periodId);
                if ($isTarget && $prepaymentPreview !== null) {
                    $periodPrepaymentPreview = $prepaymentPreview;
                    $periodPrepaymentContext = ['success' => true, 'errors' => []];
                } else {
                    $periodPrepaymentContext = (new PrepaymentScheduleService())
                        ->fetchPreviewAdjustmentContext($companyId, $periodId);
                    $periodPrepaymentPreview = (array)($periodPrepaymentContext['adjustments'] ?? []);
                }

                if (empty($periodDepreciationPreview['success'])) {
                    $reliable = false;
                    $depreciationErrors = array_values(array_filter(array_map(
                        'strval',
                        (array)($periodDepreciationPreview['errors'] ?? [])
                    )));
                    $warnings[] = 'Pending depreciation could not be calculated for the open period ending '
                        . (string)($period['period_end'] ?? '') . ': '
                        . ($depreciationErrors !== []
                            ? implode(' ', $depreciationErrors)
                            : 'The depreciation preview returned an unsuccessful result.');
                }
                if (empty($periodPrepaymentContext['success'])) {
                    $reliable = false;
                    $prepaymentErrors = array_values(array_filter(array_map(
                        'strval',
                        (array)($periodPrepaymentContext['errors'] ?? [])
                    )));
                    $warnings[] = 'Pending prepayments could not be calculated reliably for the open period ending '
                        . (string)($period['period_end'] ?? '') . ': '
                        . ($prepaymentErrors !== []
                            ? implode(' ', $prepaymentErrors)
                            : 'The prepayment preview returned an unsuccessful result.');
                }

                $periodWarnings = [];
                $periodReliable = true;
                $periodAdjustments = $this->pendingBalanceSheetAdjustmentsForPeriod(
                    $companyId,
                    $periodId,
                    $period,
                    $periodDepreciationPreview,
                    $periodPrepaymentPreview,
                    $isTarget ? $profitBeforeTax : null,
                    $periodWarnings,
                    $periodReliable
                );
                if (!$periodReliable) {
                    $reliable = false;
                }
                $warnings = array_merge($warnings, $periodWarnings);
                $directorLoanOffset = $this->pendingDirectorLoanOffsetAdjustmentContext(
                    $companyId,
                    $periodId,
                    $previewedDirectorLoanReclassificationByDirector
                );
                if (empty($directorLoanOffset['reliable'])) {
                    $reliable = false;
                    $warnings = array_merge($warnings, (array)($directorLoanOffset['warnings'] ?? []));
                }
                $directorLoanAdjustments = (array)($directorLoanOffset['adjustments'] ?? []);
                $periodHasDirectorLoanOffset = $directorLoanAdjustments !== [];
                foreach ((array)($directorLoanOffset['adjustment_by_director'] ?? []) as $directorId => $amount) {
                    $previewedDirectorLoanReclassificationByDirector[(int)$directorId] = round(
                        (float)($previewedDirectorLoanReclassificationByDirector[(int)$directorId] ?? 0)
                            + (float)$amount,
                        2
                    );
                }
                $periodAdjustments = array_merge($periodAdjustments, $directorLoanAdjustments);
                $adjustments = array_merge($adjustments, $periodAdjustments);
                $periodContexts[] = [
                    'accounting_period_id' => $periodId,
                    'period_end' => (string)($period['period_end'] ?? ''),
                    'adjustment_count' => count($periodAdjustments),
                    'prepayment_preview_reliable' => !empty($periodPrepaymentContext['success']),
                    'director_loan_offset_previewed' => $periodHasDirectorLoanOffset,
                ];
            } catch (\Throwable $exception) {
                $reliable = false;
                $warnings[] = 'Closing preview adjustments could not be calculated for the open period ending '
                    . (string)($period['period_end'] ?? '') . ': ' . $exception->getMessage();
            }
        }

        return [
            'adjustments' => $adjustments,
            'reliable' => $reliable,
            'warnings' => array_values(array_unique($warnings)),
            'periods' => $periodContexts,
        ];
    }

    private function pendingBalanceSheetAdjustmentsForPeriod(
        int $companyId,
        int $accountingPeriodId,
        array $accountingPeriod,
        ?array $depreciationPreview,
        array $prepaymentPreview,
        ?float $profitBeforeTax,
        ?array &$warnings = null,
        ?bool &$reliable = null
    ): array {
        $corporationTax = $this->pendingCorporationTaxProvisionAdjustmentContext(
            $companyId,
            $accountingPeriodId,
            $accountingPeriod,
            $depreciationPreview,
            $prepaymentPreview,
            $profitBeforeTax
        );
        $warnings = array_values(array_unique(array_map(
            'strval',
            (array)($corporationTax['warnings'] ?? [])
        )));
        $reliable = !empty($corporationTax['reliable']);

        return array_merge(
            $this->pendingPrepaymentBalanceSheetAdjustments($prepaymentPreview),
            $this->pendingDepreciationBalanceSheetAdjustments($companyId, $accountingPeriodId, $depreciationPreview),
            (array)($corporationTax['adjustments'] ?? []),
            $this->pendingRetainedEarningsCloseAdjustment(
                $companyId,
                $accountingPeriodId,
                $accountingPeriod,
                $depreciationPreview,
                $prepaymentPreview,
                $profitBeforeTax,
                (float)($corporationTax[
                    !empty($corporationTax['reliable'])
                        ? 'estimated_corporation_tax'
                        : 'posted_corporation_tax_charge'
                ] ?? 0)
            )
        );
    }

    /** @return list<array<string, mixed>> */
    public function prepaymentExpenseRowsForPeriod(
        int $companyId,
        int $accountingPeriodId,
        string $periodStart = '',
        string $periodEnd = '',
        ?array $prepaymentPreview = null
    ): array {
        $rows = [];
        $prepaymentPreview ??= (new PrepaymentScheduleService())->fetchPreviewAdjustments($companyId, $accountingPeriodId);
        foreach ($prepaymentPreview as $adjustment) {
            $journalDate = (string)($adjustment['journal_date'] ?? '');
            if (($periodStart !== '' && $journalDate < $periodStart)
                || ($periodEnd !== '' && $journalDate > $periodEnd)) {
                continue;
            }
            $debitNominal = $this->nominalById((int)$adjustment['debit_nominal_id']);
            $creditNominal = $this->nominalById((int)$adjustment['credit_nominal_id']);
            $expenseNominal = is_array($debitNominal) && in_array((string)$debitNominal['account_type'], ['expense', 'cost_of_sales'], true)
                ? $debitNominal
                : (is_array($creditNominal) && in_array((string)$creditNominal['account_type'], ['expense', 'cost_of_sales'], true) ? $creditNominal : null);
            if (!is_array($expenseNominal)) {
                continue;
            }
            $amount = ((int)$adjustment['amount_pence']) / 100;
            $signedAmount = (int)$adjustment['debit_nominal_id'] === (int)$expenseNominal['nominal_account_id']
                ? $amount
                : -$amount;
            $rows[] = $expenseNominal + [
                'journal_date' => $journalDate,
                'amount' => round($signedAmount, 2),
                'source' => 'pending_prepayment',
                'review_id' => (int)$adjustment['review_id'],
                'schedule_id' => (int)$adjustment['schedule_id'],
            ];
        }
        return $rows;
    }

    public function prepaymentExpenseAdjustmentForPeriod(
        int $companyId,
        int $accountingPeriodId,
        string $periodStart = '',
        string $periodEnd = '',
        ?array $prepaymentPreview = null
    ): float {
        return round(array_sum(array_map(
            static fn(array $row): float => (float)$row['amount'],
            $this->prepaymentExpenseRowsForPeriod(
                $companyId,
                $accountingPeriodId,
                $periodStart,
                $periodEnd,
                $prepaymentPreview
            )
        )), 2);
    }

    /** @return array<string, float> */
    public function monthlyPrepaymentExpenseForPeriod(
        int $companyId,
        int $accountingPeriodId,
        string $periodStart,
        string $periodEnd
    ): array {
        $months = [];
        foreach ($this->prepaymentExpenseRowsForPeriod($companyId, $accountingPeriodId, $periodStart, $periodEnd) as $row) {
            $month = substr((string)$row['journal_date'], 0, 7) . '-01';
            $months[$month] = round((float)($months[$month] ?? 0) + (float)$row['amount'], 2);
        }
        return $months;
    }

    public function depreciationExpenseNominal(): ?array
    {
        return $this->nominalByCode('6200', 'expense');
    }

    private function postedDepreciationRows(int $companyId, int $accountingPeriodId): array
    {
        if (!$this->tableExists('asset_depreciation_entries') || !$this->tableExists('asset_register')) {
            return [];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT ade.asset_id,
                    COALESCE(ar.asset_code, \'\') AS asset_code,
                    ar.accum_dep_nominal_id,
                    ade.period_start,
                    ade.period_end,
                    ade.amount,
                    0 AS is_pending
             FROM asset_depreciation_entries ade
             INNER JOIN asset_register ar ON ar.id = ade.asset_id
             WHERE ade.accounting_period_id = :accounting_period_id
               AND ar.company_id = :company_id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        ) ?: [];

        return array_map(static fn(array $row): array => [
            'asset_id' => (int)($row['asset_id'] ?? 0),
            'asset_code' => (string)($row['asset_code'] ?? ''),
            'accum_dep_nominal_id' => (int)($row['accum_dep_nominal_id'] ?? 0),
            'period_start' => (string)($row['period_start'] ?? ''),
            'period_end' => (string)($row['period_end'] ?? ''),
            'amount' => round((float)($row['amount'] ?? 0), 2),
            'is_pending' => false,
        ], $rows);
    }

    private function pendingDepreciationRows(
        int $companyId,
        int $accountingPeriodId,
        ?array $depreciationPreview = null
    ): array
    {
        if (!$this->tableExists('asset_register')) {
            return [];
        }

        $preview = $depreciationPreview
            ?? (new \eel_accounts\Service\AssetService())->previewDepreciationRun($companyId, $accountingPeriodId);
        $previewRows = array_values(array_filter(
            (array)($preview['rows'] ?? []),
            static fn(mixed $row): bool => is_array($row) && (int)($row['asset_id'] ?? 0) > 0
        ));
        if (empty($preview['success']) || $previewRows === []) {
            return [];
        }

        $assetIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['asset_id'], $previewRows)));
        $assetMap = $this->assetDepreciationNominalMap($assetIds);
        $rows = [];
        foreach ($previewRows as $row) {
            $assetId = (int)($row['asset_id'] ?? 0);
            $asset = (array)($assetMap[$assetId] ?? []);
            $rows[] = [
                'asset_id' => $assetId,
                'asset_code' => (string)($row['asset_code'] ?? $asset['asset_code'] ?? ''),
                'accum_dep_nominal_id' => (int)($asset['accum_dep_nominal_id'] ?? 0),
                'period_start' => (string)($row['period_start'] ?? ''),
                'period_end' => (string)($row['period_end'] ?? ''),
                'amount' => round((float)($row['amount'] ?? 0), 2),
                'is_pending' => true,
            ];
        }

        return $rows;
    }

    private function pendingDepreciationBalanceSheetAdjustments(
        int $companyId,
        int $accountingPeriodId,
        ?array $depreciationPreview = null
    ): array
    {
        $adjustments = [];
        foreach ($this->pendingDepreciationRows($companyId, $accountingPeriodId, $depreciationPreview) as $row) {
            $nominalId = (int)($row['accum_dep_nominal_id'] ?? 0);
            $amount = round((float)($row['amount'] ?? 0), 2);
            if ($nominalId <= 0 || $amount <= 0) {
                continue;
            }

            $nominal = $this->nominalById($nominalId);
            if ($nominal === null) {
                continue;
            }

            $adjustments[] = $nominal + [
                'debit' => 0.0,
                'credit' => $amount,
                'source' => 'pending_depreciation',
            ];
        }

        return $adjustments;
    }

    /** @return list<array<string, mixed>> */
    private function pendingPrepaymentBalanceSheetAdjustments(array $prepaymentPreview): array
    {
        $adjustments = [];
        foreach ($prepaymentPreview as $adjustment) {
            $debitNominal = $this->nominalById((int)$adjustment['debit_nominal_id']);
            $creditNominal = $this->nominalById((int)$adjustment['credit_nominal_id']);
            $assetNominal = is_array($debitNominal) && (string)$debitNominal['account_type'] === 'asset'
                ? $debitNominal
                : (is_array($creditNominal) && (string)$creditNominal['account_type'] === 'asset' ? $creditNominal : null);
            if (!is_array($assetNominal)) {
                continue;
            }
            $amount = ((int)$adjustment['amount_pence']) / 100;
            $adjustments[] = $assetNominal + [
                'debit' => (int)$adjustment['debit_nominal_id'] === (int)$assetNominal['nominal_account_id'] ? $amount : 0.0,
                'credit' => (int)$adjustment['credit_nominal_id'] === (int)$assetNominal['nominal_account_id'] ? $amount : 0.0,
                'source' => 'pending_prepayment',
            ];
        }
        return $adjustments;
    }

    private function pendingDirectorLoanOffsetAdjustmentContext(
        int $companyId,
        int $accountingPeriodId,
        array $previewedPriorReclassificationByDirector
    ): array
    {
        $context = (new \eel_accounts\Service\DirectorLoanReconciliationService())->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return [
                'adjustments' => [],
                'adjustment_amount' => 0.0,
                'adjustment_by_director' => [],
                'reliable' => true,
                'warnings' => [],
            ];
        }

        $warnings = (array)($context['warnings'] ?? []);
        if (
            $warnings !== []
            || (int)($context['unattributed_count'] ?? 0) > 0
            || abs((float)($context['legacy_unresolved_reclassification_amount'] ?? 0)) >= 0.005
        ) {
            return [
                'adjustments' => [],
                'adjustment_amount' => 0.0,
                'adjustment_by_director' => [],
                'reliable' => false,
                'warnings' => [
                    'Pending director loan control reclassification could not be calculated reliably for accounting period '
                        . $accountingPeriodId . ': '
                        . implode(' ', array_map('strval', $warnings !== []
                            ? $warnings
                            : ['Every Director Loan entry must have a valid director attribution.'])),
                ],
            ];
        }

        $assetNominalId = (int)(($context['asset_nominal'] ?? [])['id'] ?? 0);
        $liabilityNominalId = (int)(($context['liability_nominal'] ?? [])['id'] ?? 0);
        if ($assetNominalId <= 0 || $liabilityNominalId <= 0) {
            return [
                'adjustments' => [],
                'adjustment_amount' => 0.0,
                'adjustment_by_director' => [],
                'reliable' => false,
                'warnings' => ['Pending director loan control reclassification could not resolve both configured nominals.'],
            ];
        }

        $adjustments = [];
        $adjustmentByDirector = [];
        foreach ((array)($context['per_director'] ?? []) as $position) {
            $directorId = (int)($position['director_id'] ?? 0);
            if ($directorId <= 0) {
                continue;
            }

            $adjustmentAmount = round(
                (float)($position['desired_reclassification'] ?? 0)
                    - (float)($position['posted_reclassification'] ?? 0)
                    - (float)($previewedPriorReclassificationByDirector[$directorId] ?? 0),
                2
            );
            if (abs($adjustmentAmount) < 0.005) {
                continue;
            }
            $adjustmentByDirector[$directorId] = $adjustmentAmount;
            $amount = abs($adjustmentAmount);
            $applyingReclassification = $adjustmentAmount > 0;
            $lines = [
                [
                    'nominal_account_id' => $applyingReclassification ? $liabilityNominalId : $assetNominalId,
                    'debit' => $amount,
                    'credit' => 0.0,
                ],
                [
                    'nominal_account_id' => $applyingReclassification ? $assetNominalId : $liabilityNominalId,
                    'debit' => 0.0,
                    'credit' => $amount,
                ],
            ];
            foreach ($lines as $line) {
                $nominalId = (int)$line['nominal_account_id'];
                $nominal = $this->nominalById($nominalId);
                if ($nominal === null) {
                    continue;
                }
                $adjustments[] = $nominal + [
                    'director_id' => $directorId,
                    'debit' => round((float)$line['debit'], 2),
                    'credit' => round((float)$line['credit'], 2),
                    'source' => 'pending_director_loan_offset',
                ];
            }
        }

        return [
            'adjustments' => $adjustments,
            'adjustment_amount' => round(array_sum($adjustmentByDirector), 2),
            'adjustment_by_director' => $adjustmentByDirector,
            'reliable' => true,
            'warnings' => [],
        ];
    }

    /**
     * Preview the exact CT provision delta which Year End will post. The
     * pre-tax calculation is supplied directly so this path cannot recurse
     * through the balance-sheet preview that called it.
     *
     * @return array{
     *   adjustments: list<array<string, mixed>>,
     *   estimated_corporation_tax: float,
     *   posted_corporation_tax_charge: float,
     *   unposted_corporation_tax_adjustment: float,
     *   reliable: bool,
     *   warnings: list<string>
     * }
     */
    private function pendingCorporationTaxProvisionAdjustmentContext(
        int $companyId,
        int $accountingPeriodId,
        array $accountingPeriod,
        ?array $depreciationPreview,
        array $prepaymentPreview,
        ?float $profitBeforeTax
    ): array
    {
        $periodStart = (string)($accountingPeriod['period_start'] ?? '');
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        $profitAndLoss = (new PreTaxProfitLossService())->calculate(
            $companyId,
            $accountingPeriodId,
            $periodEnd,
            $periodStart,
            $depreciationPreview,
            $prepaymentPreview
        );
        if ($profitBeforeTax !== null) {
            $profitAndLoss['profit_before_tax'] = round($profitBeforeTax, 2);
        }

        $estimate = (new CorporationTaxComputationService())
            ->previewProvisionPositionForAccountingPeriod(
                $companyId,
                $accountingPeriodId,
                $accountingPeriod,
                $profitAndLoss
            );
        if (empty($estimate['available'])) {
            return [
                'adjustments' => [],
                'estimated_corporation_tax' => 0.0,
                'posted_corporation_tax_charge' => round((float)($profitAndLoss['posted_corporation_tax_charge'] ?? 0), 2),
                'unposted_corporation_tax_adjustment' => 0.0,
                'reliable' => false,
                'warnings' => array_values(array_map(
                    'strval',
                    (array)($estimate['errors'] ?? ['The Corporation Tax provision preview is unavailable.'])
                )),
            ];
        }

        $estimated = round((float)($estimate['estimated_corporation_tax'] ?? 0), 2);
        $posted = round((float)($estimate['posted_corporation_tax_charge'] ?? 0), 2);
        $delta = round(
            (float)($estimate['unposted_corporation_tax_adjustment'] ?? ($estimated - $posted)),
            2
        );
        if (abs($delta) < 0.005) {
            return [
                'adjustments' => [],
                'estimated_corporation_tax' => $estimated,
                'posted_corporation_tax_charge' => $posted,
                'unposted_corporation_tax_adjustment' => 0.0,
                'reliable' => true,
                'warnings' => [],
            ];
        }

        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $expenseNominal = $this->nominalById((int)($settings['corporation_tax_expense_nominal_id'] ?? 0));
        $liabilityNominal = $this->nominalById((int)($settings['corporation_tax_liability_nominal_id'] ?? 0));
        if ($expenseNominal === null || $liabilityNominal === null) {
            return [
                'adjustments' => [],
                'estimated_corporation_tax' => $estimated,
                'posted_corporation_tax_charge' => $posted,
                'unposted_corporation_tax_adjustment' => $delta,
                'reliable' => false,
                'warnings' => ['Corporation Tax expense and liability nominals are required for the Year End close preview.'],
            ];
        }

        $amount = abs($delta);
        $adjustments = $delta > 0
            ? [
                $expenseNominal + [
                    'debit' => $amount,
                    'credit' => 0.0,
                    'source' => 'pending_corporation_tax_provision',
                ],
                $liabilityNominal + [
                    'debit' => 0.0,
                    'credit' => $amount,
                    'source' => 'pending_corporation_tax_provision',
                ],
            ]
            : [
                $liabilityNominal + [
                    'debit' => $amount,
                    'credit' => 0.0,
                    'source' => 'pending_corporation_tax_provision_reversal',
                ],
                $expenseNominal + [
                    'debit' => 0.0,
                    'credit' => $amount,
                    'source' => 'pending_corporation_tax_provision_reversal',
                ],
            ];

        return [
            'adjustments' => $adjustments,
            'estimated_corporation_tax' => $estimated,
            'posted_corporation_tax_charge' => $posted,
            'unposted_corporation_tax_adjustment' => $delta,
            'reliable' => true,
            'warnings' => [],
        ];
    }

    private function pendingRetainedEarningsCloseAdjustment(
        int $companyId,
        int $accountingPeriodId,
        array $accountingPeriod,
        ?array $depreciationPreview = null,
        ?array $prepaymentPreview = null,
        ?float $profitBeforeTax = null,
        float $estimatedCorporationTax = 0.0
    ): array
    {
        if (!$this->tableExists('journal_entry_metadata')) {
            return [];
        }

        $existingClose = (new \eel_accounts\Service\ManualJournalService())->fetchJournalByTag(
            $companyId,
            $accountingPeriodId,
            \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
            \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_KEY
        );
        if (is_array($existingClose)) {
            return [];
        }

        $periodStart = (string)($accountingPeriod['period_start'] ?? '');
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        $profitBeforeTax = $profitBeforeTax ?? $this->profitBeforeTaxIncludingDepreciation(
            $companyId,
            $accountingPeriodId,
            $periodStart,
            $periodEnd,
            $depreciationPreview,
            $prepaymentPreview
        );
        $profitLoss = round($profitBeforeTax - $estimatedCorporationTax, 2);

        $nominal = $this->nominalByCode(\eel_accounts\Service\RetainedEarningsCloseService::RETAINED_EARNINGS_CODE, 'equity');
        if ($nominal === null || abs($profitLoss) < 0.005) {
            return [];
        }

        return [[
            ...$nominal,
            'debit' => $profitLoss < 0 ? abs($profitLoss) : 0.0,
            'credit' => $profitLoss > 0 ? $profitLoss : 0.0,
            'source' => 'pending_retained_earnings_close',
        ]];
    }

    private function profitBeforeTaxIncludingDepreciation(
        int $companyId,
        int $accountingPeriodId,
        string $periodStart,
        string $periodEnd,
        ?array $depreciationPreview = null,
        ?array $prepaymentPreview = null
    ): float
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT na.account_type,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN journal_entry_metadata jem_close
               ON jem_close.journal_id = j.id
              AND jem_close.journal_tag = :close_journal_tag
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND COALESCE(j.source_type, \'\') <> :asset_depreciation_source_type
               AND jem_close.id IS NULL
               AND na.account_type IN (:income_type, :cost_type, :expense_type)
             GROUP BY na.account_type',
            [
                'close_journal_tag' => \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
                'asset_depreciation_source_type' => self::ASSET_DEPRECIATION_SOURCE_TYPE,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'income_type' => 'income',
                'cost_type' => 'cost_of_sales',
                'expense_type' => 'expense',
            ]
        ) ?: [];

        $income = 0.0;
        $expenses = 0.0;
        foreach ($rows as $row) {
            $accountType = (string)($row['account_type'] ?? '');
            $debit = (float)($row['total_debit'] ?? 0);
            $credit = (float)($row['total_credit'] ?? 0);
            if ($accountType === 'income') {
                $income += round($credit - $debit, 2);
            } else {
                $expenses += round($debit - $credit, 2);
            }
        }

        $expenses = round($expenses + $this->depreciationExpenseForPeriod(
            $companyId,
            $accountingPeriodId,
            $periodStart,
            $periodEnd,
            $depreciationPreview
        ), 2);
        $expenses = round($expenses + $this->prepaymentExpenseAdjustmentForPeriod(
            $companyId,
            $accountingPeriodId,
            $periodStart,
            $periodEnd,
            $prepaymentPreview
        ), 2);

        return round($income - $expenses, 2);
    }

    private function assetDepreciationNominalMap(array $assetIds): array
    {
        $assetIds = array_values(array_filter(array_unique(array_map('intval', $assetIds)), static fn(int $id): bool => $id > 0));
        if ($assetIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($assetIds), '?'));
        $rows = \InterfaceDB::prepareExecute(
            'SELECT id, asset_code, accum_dep_nominal_id
             FROM asset_register
             WHERE id IN (' . $placeholders . ')',
            $assetIds
        )->fetchAll() ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(int)($row['id'] ?? 0)] = $row;
        }

        return $map;
    }

    private function nominalById(int $nominalId): ?array
    {
        if ($nominalId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT na.id AS nominal_account_id,
                    COALESCE(na.code, \'\') AS code,
                    COALESCE(na.name, \'\') AS name,
                    COALESCE(na.account_type, \'\') AS account_type,
                    COALESCE(nas.code, \'\') AS subtype_code,
                    COALESCE(na.tax_treatment, \'\') AS tax_treatment
             FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.id = :id
             LIMIT 1',
            ['id' => $nominalId]
        );

        return is_array($row) ? $row : null;
    }

    private function nominalByCode(string $code, string $accountType = ''): ?array
    {
        $params = ['code' => $code];
        $accountTypeSql = '';
        if ($accountType !== '') {
            $accountTypeSql = ' AND na.account_type = :account_type';
            $params['account_type'] = $accountType;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT na.id AS nominal_account_id,
                    COALESCE(na.code, \'\') AS code,
                    COALESCE(na.name, \'\') AS name,
                    COALESCE(na.account_type, \'\') AS account_type,
                    COALESCE(nas.code, \'\') AS subtype_code
             FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.code = :code
               ' . $accountTypeSql . '
               AND COALESCE(na.is_active, 0) = 1
             ORDER BY na.id
             LIMIT 1',
            $params
        );

        return is_array($row) ? $row : null;
    }

    private function fetchAccountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, period_start, period_end
             FROM accounting_periods
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );

        return is_array($row) ? $row : null;
    }

    private function periodDays(string $start, string $end): int
    {
        if ($start === '' || $end === '' || $end < $start) {
            return 0;
        }

        return (int)(new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days + 1;
    }

    private function overlapDays(string $entryStart, string $entryEnd, string $periodStart, string $periodEnd): int
    {
        if ($entryStart === '' || $entryEnd === '' || $periodStart === '' || $periodEnd === '') {
            return 0;
        }

        $start = max($entryStart, $periodStart);
        $end = min($entryEnd, $periodEnd);

        return $end < $start ? 0 : $this->periodDays($start, $end);
    }

    private function tableExists(string $table): bool
    {
        try {
            return \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
