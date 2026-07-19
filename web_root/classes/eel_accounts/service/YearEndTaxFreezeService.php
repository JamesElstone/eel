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
    public const BASIS_VERSION = 'year_end_ct_freeze_v1';

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
        ?int $expectedPeriodCount = null
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

        $blockingDiagnostics = $this->blockingDiagnostics($periods, $errors, $expectedPeriodCount);
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
                'corporation_tax_liability' => $this->money(array_sum(array_map(static fn(array $period): float => (float)($period['estimated_corporation_tax'] ?? 0), $periods))),
            ],
            'blocking_diagnostic_codes' => array_values(array_map(
                static fn(array $diagnostic): string => (string)($diagnostic['code'] ?? ''),
                $blockingDiagnostics
            )),
        ];
        $manifestHash = (new YearEndAcknowledgementService())->hashBasis($manifest);

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

    /** @return array<string, mixed> */
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
            'corporation_tax_liability' => $this->money($period['estimated_corporation_tax'] ?? 0),
            'other_treatment_amount' => $this->money($period['other_treatment_amount'] ?? 0),
            'unknown_treatment_amount' => $this->money($period['unknown_treatment_amount'] ?? 0),
            'prepayment_preview_reliable' => !array_key_exists('prepayment_preview_reliable', $period)
                || !empty($period['prepayment_preview_reliable']),
            'accounting_allocation_basis' => $this->stableNestedData((array)($period['accounting_allocation_basis'] ?? [])),
            'capital_allowance_breakdown' => $this->stableNestedData((array)($period['capital_allowance_breakdown'] ?? [])),
            'rate_bands' => $this->stableNestedData((array)($period['ct_rate_bands'] ?? [])),
            'blocking_diagnostic_codes' => $diagnosticCodes,
        ];
    }

    /**
     * @param list<array<string, mixed>> $periods
     * @param list<string> $errors
     * @return list<array<string, mixed>>
     */
    private function blockingDiagnostics(array $periods, array $errors, ?int $expectedPeriodCount): array
    {
        $diagnostics = [];
        foreach ($periods as $period) {
            foreach ((array)($period['hard_gate_diagnostics'] ?? []) as $diagnostic) {
                if (!is_array($diagnostic) || empty($diagnostic['amount_affecting'])) {
                    continue;
                }
                $diagnostics[] = $diagnostic;
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
                (string)json_encode($left, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                (string)json_encode($right, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
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
}
