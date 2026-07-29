<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Builds the stable, calculation-only Corporation Tax basis approved at Year End. */
final class YearEndTaxFreezeService
{
    public const BASIS_VERSION = 'year_end_ct_freeze_v3';

    /**
     * @param list<array<string, mixed>> $periods
     * @param list<string> $errors
     * @return array<string, mixed>
     */
    public function build(
        int $companyId,
        int $accountingPeriodId,
        array $periods,
        array $errors = [],
        ?int $expectedPeriodCount = null,
        array $_filingScope = []
    ): array {
        usort($periods, static fn(array $left, array $right): int => [
            (string)($left['period_start'] ?? ''),
            (int)($left['ct_period_id'] ?? 0),
        ] <=> [
            (string)($right['period_start'] ?? ''),
            (int)($right['ct_period_id'] ?? 0),
        ]);
        foreach ($periods as $index => &$period) {
            if ((int)($period['ct_period_sequence_no'] ?? 0) <= 0) {
                $period['ct_period_sequence_no'] = $index + 1;
            }
        }
        unset($period);

        $blockingDiagnostics = $this->blockingDiagnostics(
            $companyId,
            $accountingPeriodId,
            $periods,
            $errors,
            $expectedPeriodCount
        );
        $manifestPeriods = array_map(fn(array $period): array => $this->periodBasis($period), $periods);
        $manifest = [
            'basis_version' => self::BASIS_VERSION,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'periods' => $manifestPeriods,
            'totals' => [
                'taxable_profit' => $this->money(array_sum(array_map(static fn(array $period): float => (float)($period['taxable_profit'] ?? 0), $periods))),
                'ordinary_corporation_tax' => $this->money(array_sum(array_map(static fn(array $period): float => (float)($period['ordinary_corporation_tax'] ?? 0), $periods))),
                's455_tax' => $this->money(array_sum(array_map(static fn(array $period): float => (float)($period['s455_tax'] ?? 0), $periods))),
                'ct600a_tax' => $this->money(array_sum(array_map(static fn(array $period): float => (float)($period['ct600a_tax'] ?? 0), $periods))),
                'corporation_tax_liability' => $this->money(array_sum(array_map(static fn(array $period): float => (float)($period['estimated_corporation_tax'] ?? 0), $periods))),
                'tax_payable' => $this->money(array_sum(array_map(static fn(array $period): float => (float)($period['tax_payable'] ?? $period['estimated_corporation_tax'] ?? 0), $periods))),
            ],
            'blocking_diagnostic_codes' => array_values(array_map(
                static fn(array $diagnostic): string => (string)($diagnostic['code'] ?? ''),
                $blockingDiagnostics
            )),
        ];
        $acknowledgements = new YearEndAcknowledgementService();
        $manifest = $acknowledgements->normalizedBasis($this->canonicalManifest($manifest));
        $manifestHash = $acknowledgements->hashBasis($manifest);

        return [
            'freeze_status' => $blockingDiagnostics === [] ? 'ready_for_approval' : 'blocked',
            'freeze_manifest' => $manifest,
            'freeze_manifest_hash' => $manifestHash,
            'blocking_diagnostics' => $blockingDiagnostics,
            'blocking_diagnostic_count' => count($blockingDiagnostics),
        ];
    }

    /** @return array<string, mixed>|null */
    public function approvalBasis(array $taxReadiness): ?array
    {
        $manifest = $taxReadiness['freeze_manifest'] ?? null;
        if (!is_array($manifest)
            || (string)($taxReadiness['freeze_status'] ?? '') !== 'ready_for_approval') {
            return null;
        }

        return [
            'check_code' => 'tax_readiness_acknowledgement',
            'freeze_manifest' => $manifest,
        ];
    }

    /**
     * Canonical representation shared by live tax readiness, V2 approval,
     * persisted close evidence and the immutable calculation seal.
     *
     * @return array<string, mixed>
     */
    public function canonicalManifest(array $manifest): array
    {
        $canonical = $this->canonicalApprovalValue($manifest);
        return is_array($canonical) ? $canonical : [];
    }

    public function canonicalApprovalValue(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)
            || (is_string($value) && is_numeric($value))) {
            $number = rtrim(rtrim(sprintf('%.10F', (float)$value), '0'), '.');
            return $number === '' || $number === '-0' ? '0' : $number;
        }
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalApprovalValue($item);
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
            return $value;
        }
        if ($value === [] || !array_reduce(
            $value,
            static fn(bool $carry, mixed $item): bool => $carry && is_array($item),
            true
        )) {
            return $value;
        }

        $identityKeys = ['sequence_no', 'ct_period_id', 'pool_type', 'asset_id'];
        foreach ($identityKeys as $identityKey) {
            if (!array_reduce(
                $value,
                static fn(bool $carry, array $item): bool => $carry && array_key_exists($identityKey, $item),
                true
            )) {
                continue;
            }
            usort($value, function (array $left, array $right) use ($identityKey): int {
                $comparison = strnatcmp((string)$left[$identityKey], (string)$right[$identityKey]);
                return $comparison !== 0
                    ? $comparison
                    : strcmp($this->canonicalJson($left), $this->canonicalJson($right));
            });
            break;
        }
        return $value;
    }

    private function periodBasis(array $period): array
    {
        $diagnosticCodes = [];
        foreach ((array)($period['hard_gate_diagnostics'] ?? []) as $diagnostic) {
            if (!is_array($diagnostic) || empty($diagnostic['amount_affecting'])) {
                continue;
            }
            $code = trim((string)($diagnostic['code'] ?? ''));
            if ($code !== '') {
                $diagnosticCodes[] = $code;
            }
        }
        sort($diagnosticCodes, SORT_STRING);

        return [
            'ct_period_id' => (int)($period['ct_period_id'] ?? 0),
            'sequence_no' => (int)($period['ct_period_sequence_no'] ?? 0),
            'period_start' => (string)($period['period_start'] ?? ''),
            'period_end' => (string)($period['period_end'] ?? ''),
            'accounting_profit' => $this->money($period['accounting_profit'] ?? 0),
            'disallowable_add_backs' => $this->money($period['disallowable_add_backs'] ?? 0),
            'capital_add_backs' => $this->money($period['capital_add_backs'] ?? 0),
            'capital_expenditure_add_backs' => $this->money(
                $period['capital_expenditure_add_backs'] ?? $period['capital_add_backs'] ?? 0
            ),
            'disposal_profit_or_loss_adjustment' => $this->money(
                $period['disposal_profit_or_loss_adjustment'] ?? 0
            ),
            'depreciation_add_back' => $this->money($period['depreciation_add_back'] ?? 0),
            'capital_allowances' => $this->money($period['capital_allowances'] ?? 0),
            'taxable_before_losses' => $this->money($period['taxable_before_losses'] ?? 0),
            'losses_brought_forward' => $this->money($period['losses_brought_forward'] ?? $period['loss_brought_forward'] ?? 0),
            'losses_used' => $this->money($period['losses_used'] ?? $period['loss_utilised'] ?? 0),
            'loss_created_in_period' => $this->money($period['loss_created_in_period'] ?? $period['loss_created'] ?? 0),
            'losses_carried_forward' => $this->money($period['losses_carried_forward'] ?? $period['loss_carried_forward'] ?? 0),
            'taxable_profit' => $this->money($period['taxable_profit'] ?? 0),
            'associated_company_count' => (int)($period['associated_company_count'] ?? 0),
            'ordinary_corporation_tax' => $this->money($period['ordinary_corporation_tax'] ?? 0),
            's455_tax' => $this->money($period['s455_tax'] ?? 0),
            'ct600a_tax' => $this->money($period['ct600a_tax'] ?? 0),
            'corporation_tax_liability' => $this->money($period['estimated_corporation_tax'] ?? 0),
            'tax_payable' => $this->money($period['tax_payable'] ?? $period['estimated_corporation_tax'] ?? 0),
            'return_position_model_version' => (string)($period['return_position_model_version'] ?? ''),
            'other_treatment_amount' => $this->money($period['other_treatment_amount'] ?? 0),
            'unknown_treatment_amount' => $this->money($period['unknown_treatment_amount'] ?? 0),
            'prepayment_preview_reliable' => !array_key_exists('prepayment_preview_reliable', $period)
                || !empty($period['prepayment_preview_reliable']),
            'accounting_allocation_basis' => $this->stableNestedData((array)($period['accounting_allocation_basis'] ?? [])),
            'capital_allowance_breakdown' => $this->stableNestedData((array)($period['capital_allowance_breakdown'] ?? [])),
            'rate_bands' => $this->stableNestedData((array)($period['ct_rate_bands'] ?? [])),
            'disallowable_expense_breakdown' => $this->stableNestedData(
                (array)($period['disallowable_expense_breakdown'] ?? [])
            ),
            'blocking_diagnostic_codes' => $diagnosticCodes,
        ];
    }

    /**
     * @param list<array<string, mixed>> $periods
     * @param list<string> $errors
     * @return list<array<string, mixed>>
     */
    private function blockingDiagnostics(
        int $companyId,
        int $accountingPeriodId,
        array $periods,
        array $errors,
        ?int $expectedPeriodCount
    ): array
    {
        $diagnostics = [];
        foreach ($periods as $period) {
            foreach ((array)($period['hard_gate_diagnostics'] ?? []) as $diagnostic) {
                if (!is_array($diagnostic) || empty($diagnostic['amount_affecting'])) {
                    continue;
                }
                $diagnostics[] = $diagnostic;
            }
            foreach ((array)($period['blocking_errors'] ?? []) as $error) {
                $message = trim((string)$error);
                if ($message === '') {
                    continue;
                }
                $diagnostics[] = $this->structuralDiagnostic(
                    'ct_return_position_' . substr(hash('sha256', $message), 0, 12),
                    $message,
                    (int)($period['ct_period_id'] ?? 0)
                );
            }
        }

        foreach ($errors as $error) {
            $message = trim((string)$error);
            if ($message === '') {
                continue;
            }
            $diagnostics[] = $this->structuralDiagnostic(
                'ct_computation_' . substr(hash('sha256', $message), 0, 12),
                $message
            );
        }

        foreach ($periods as $period) {
            if (!array_key_exists('disallowable_expense_breakdown', $period)) {
                continue;
            }
            $diagnostic = $this->disallowableBreakdownDiagnostic(
                $companyId,
                $accountingPeriodId,
                (int)($period['ct_period_id'] ?? 0),
                (float)($period['disallowable_add_backs'] ?? 0),
                false,
                (array)$period['disallowable_expense_breakdown']
            );
            if ($diagnostic !== null) {
                $diagnostics[] = $diagnostic;
            }
            if ((float)($period['losses_brought_forward'] ?? $period['loss_brought_forward'] ?? 0) >= 0.005) {
                foreach ($this->predecessorBreakdownDiagnostics(
                    $companyId,
                    (string)($period['period_start'] ?? '')
                ) as $predecessorDiagnostic) {
                    $diagnostics[] = $predecessorDiagnostic;
                }
            }
        }

        if ($expectedPeriodCount !== null && count($periods) !== $expectedPeriodCount) {
            $diagnostics[] = $this->structuralDiagnostic(
                'ct_period_computation_count',
                'A current Corporation Tax computation is required for every CT period before Year End can close.'
            );
        }

        $previousEnd = null;
        foreach ($periods as $period) {
            $ctPeriodId = (int)($period['ct_period_id'] ?? 0);
            $start = trim((string)($period['period_start'] ?? ''));
            $end = trim((string)($period['period_end'] ?? ''));
            try {
                $startDate = new \DateTimeImmutable($start);
                $endDate = new \DateTimeImmutable($end);
            } catch (\Throwable) {
                $diagnostics[] = $this->structuralDiagnostic('ct_period_dates_' . $ctPeriodId, 'A CT period has invalid dates.', $ctPeriodId);
                continue;
            }
            if ($endDate < $startDate || $endDate > $startDate->modify('+1 year')->modify('-1 day')) {
                $diagnostics[] = $this->structuralDiagnostic('ct_period_length_' . $ctPeriodId, 'A CT period is invalid or exceeds twelve months.', $ctPeriodId);
            }
            if ($previousEnd instanceof \DateTimeImmutable && $startDate != $previousEnd->modify('+1 day')) {
                $diagnostics[] = $this->structuralDiagnostic('ct_period_continuity_' . $ctPeriodId, 'The CT periods are not contiguous.', $ctPeriodId);
            }
            $previousEnd = $endDate;
        }

        $unique = [];
        foreach ($diagnostics as $diagnostic) {
            $code = trim((string)($diagnostic['code'] ?? ''));
            if ($code !== '') {
                $unique[$code] = $diagnostic;
            }
        }
        ksort($unique, SORT_STRING);
        return array_values($unique);
    }

    /** @return list<array<string,mixed>> */
    private function predecessorBreakdownDiagnostics(int $companyId, string $periodStart): array
    {
        $diagnostics = [];
        $visited = [];
        while ($periodStart !== '') {
            $predecessor = \InterfaceDB::fetchOne(
                'SELECT id, accounting_period_id, period_start, period_end
                 FROM corporation_tax_periods
                 WHERE company_id = :company_id AND period_end < :period_start
                   AND status <> :superseded
                 ORDER BY period_end DESC, id DESC LIMIT 1',
                ['company_id' => $companyId, 'period_start' => $periodStart, 'superseded' => 'superseded']
            );
            if (!is_array($predecessor) || (int)($predecessor['id'] ?? 0) <= 0) {
                break;
            }
            $ctPeriodId = (int)$predecessor['id'];
            if (isset($visited[$ctPeriodId])) {
                break;
            }
            $visited[$ctPeriodId] = true;
            $summary = (new CorporationTaxComputationService())->fetchSummaryForCtPeriodId($companyId, $ctPeriodId);
            if (empty($summary['available'])) {
                break;
            }
            $diagnostic = $this->disallowableBreakdownDiagnostic(
                $companyId,
                (int)$predecessor['accounting_period_id'],
                $ctPeriodId,
                (float)($summary['disallowable_add_backs'] ?? 0),
                true
            );
            if ($diagnostic !== null) {
                $diagnostics[] = $diagnostic;
            }
            if ((float)($summary['losses_brought_forward'] ?? 0) < 0.005) {
                break;
            }
            $periodStart = (string)($predecessor['period_start'] ?? '');
        }
        return $diagnostics;
    }

    /** @return array<string,mixed>|null */
    private function disallowableBreakdownDiagnostic(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        float $expectedAmount,
        bool $predecessor,
        ?array $knownBreakdown = null
    ): ?array {
        if ($ctPeriodId <= 0 || $accountingPeriodId <= 0) {
            return $this->structuralDiagnostic(
                'disallowable_expense_breakdown_missing_' . $ctPeriodId,
                'The Corporation Tax period has no identifiable disallowable-expense source breakdown.',
                $ctPeriodId
            );
        }
        $breakdown = $knownBreakdown;
        if ($breakdown === null) {
            try {
                $workings = (new TaxWorkingsService())->fetchWorkings($companyId, $accountingPeriodId, $ctPeriodId);
                $breakdown = (new DisallowableExpenseBreakdownService())->fromTaxWorkings(
                    (array)($workings['disallowable_add_backs'] ?? []),
                    $expectedAmount
                );
            } catch (\Throwable) {
                $breakdown = ['reconciled' => false, 'expected_amount' => $expectedAmount, 'amount' => 0.0, 'difference' => $expectedAmount];
            }
        }
        if (!empty($breakdown['reconciled'])) {
            return null;
        }
        $message = 'Disallowable expense source rows do not reconcile to the £'
            . number_format((float)($breakdown['expected_amount'] ?? $expectedAmount), 2, '.', '')
            . ' aggregate add-back';
        if ($predecessor) {
            $message .= ' in a predecessor period carrying losses into this period';
        }
        return $this->structuralDiagnostic(
            'disallowable_expense_breakdown_unreconciled_' . $ctPeriodId,
            $message . '.',
            $ctPeriodId
        );
    }

    /** @return array<string, mixed> */
    private function structuralDiagnostic(string $code, string $message, int $ctPeriodId = 0): array
    {
        return [
            'code' => $code,
            'category' => 'tax_computation',
            'severity' => 'hard_failure',
            'amount_affecting' => true,
            'message' => $message,
            'workflow_page' => 'corporation_tax',
            'workflow_fields' => $ctPeriodId > 0 ? ['ct_period_id' => (string)$ctPeriodId] : [],
        ];
    }

    private function money(mixed $value): string
    {
        return number_format(round((float)$value, 2), 2, '.', '');
    }

    private function stableNestedData(array $value): array
    {
        if (array_is_list($value)) {
            $result = array_map(fn(mixed $item): mixed => $this->stableValue($item), $value);
            usort($result, static fn(mixed $left, mixed $right): int => strcmp(
                (string)\eel_accounts\Support\Utf8::json($left, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                (string)\eel_accounts\Support\Utf8::json($right, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
            ));
            return $result;
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->stableValue($item);
        }
        return $value;
    }

    private function stableValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->stableNestedData($value);
        }
        if (is_float($value)) {
            return number_format(round($value, 6), 6, '.', '');
        }
        if (is_string($value)) {
            return trim($value);
        }
        return $value;
    }

    private function canonicalJson(mixed $value): string
    {
        $json = \eel_accounts\Support\Utf8::json($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        return is_string($json) ? $json : '';
    }
}
