<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Builds journal-truth, amount-affecting diagnostics for each CT period. */
final class CorporationTaxHardGateService
{
    /** @param null|\Closure(int, string): array<string, mixed> $predecessorFetcher */
    public function __construct(private readonly ?\Closure $predecessorFetcher = null)
    {
    }

    /** @param list<array<string, mixed>> $periods @return list<array<string, mixed>> */
    public function apply(int $companyId, array $periods): array
    {
        usort($periods, static fn(array $left, array $right): int => [
            (string)($left['period_start'] ?? ''),
            (int)($left['ct_period_id'] ?? 0),
        ] <=> [
            (string)($right['period_start'] ?? ''),
            (int)($right['ct_period_id'] ?? 0),
        ]);

        $previous = null;
        foreach ($periods as $index => $period) {
            $predecessor = $previous;
            $predecessorExpected = false;
            $predecessorAvailable = true;
            if ($index === 0) {
                $state = $this->predecessorState($companyId, (string)($period['period_start'] ?? ''));
                $predecessorExpected = !empty($state['expected']);
                $predecessorAvailable = !$predecessorExpected || !empty($state['available']);
                $predecessor = is_array($state['summary'] ?? null) ? (array)$state['summary'] : null;
            }

            $period['hard_gate_diagnostics'] = $this->evaluatePeriod(
                $period,
                $predecessor,
                $predecessorExpected,
                $predecessorAvailable
            );
            $period['hard_gate_pass'] = $period['hard_gate_diagnostics'] === [];
            $periods[$index] = $period;
            $previous = $period;
        }

        return $periods;
    }

    /**
     * @param array<string, mixed> $current
     * @param null|array<string, mixed> $previous
     * @return list<array<string, mixed>>
     */
    public function evaluatePeriod(
        array $current,
        ?array $previous = null,
        bool $predecessorExpected = false,
        bool $predecessorAvailable = true
    ): array {
        $diagnostics = [];
        $ctPeriodId = (int)($current['ct_period_id'] ?? 0);

        foreach (['unknown', 'other'] as $treatment) {
            $amount = $this->treatmentAmount($current, $treatment);
            if ($amount < 0.005) {
                continue;
            }
            $diagnostics[] = $this->diagnostic(
                'nominal_' . $treatment . '_treatment',
                'nominal_treatment',
                ucfirst($treatment) . ' nominal tax treatment has non-zero journal value ' . number_format($amount, 2, '.', '') . '.',
                'nominals',
                $ctPeriodId
            );
        }

        foreach ((array)($current['warnings'] ?? []) as $warning) {
            $warning = trim((string)$warning);
            $lower = strtolower($warning);
            if ($warning === ''
                || str_contains($lower, 'nominal tax treatments are unknown')
                || str_contains($lower, 'nominal tax treatments are marked as other')
                || $this->isGeneralCalculationAssumption($lower)) {
                continue;
            }
            [$category, $workflow] = $this->warningRoute($lower);
            $diagnostics[] = $this->diagnostic(
                $category . '_' . substr(hash('sha256', $warning), 0, 12),
                $category,
                $warning,
                $workflow,
                $ctPeriodId
            );
        }

        if ($predecessorExpected && !$predecessorAvailable) {
            $diagnostics[] = $this->diagnostic(
                'loss_predecessor_unavailable',
                'loss',
                'The immediately preceding CT period has no current locked computation from which to prove losses brought forward.',
                'corporation_tax',
                $ctPeriodId
            );
        }

        $broughtForward = round((float)($current['losses_brought_forward'] ?? 0), 2);
        $used = round((float)($current['losses_used'] ?? 0), 2);
        $created = round((float)($current['loss_created_in_period'] ?? $current['taxable_loss'] ?? 0), 2);
        $carriedForward = round((float)($current['losses_carried_forward'] ?? 0), 2);
        $taxableBeforeLosses = round((float)($current['taxable_before_losses'] ?? 0), 2);
        $taxableProfit = round((float)($current['taxable_profit'] ?? 0), 2);

        if ($previous !== null) {
            $expectedBroughtForward = round((float)($previous['losses_carried_forward'] ?? 0), 2);
            if (abs($broughtForward - $expectedBroughtForward) >= 0.005) {
                $diagnostics[] = $this->lossDiagnostic('loss_brought_forward_continuity', 'Losses brought forward do not agree to the preceding CT period carried-forward balance.', $ctPeriodId);
            }
        }
        if (min($broughtForward, $used, $created, $carriedForward, $taxableProfit) < -0.005) {
            $diagnostics[] = $this->lossDiagnostic('loss_negative_value', 'The CT loss schedule contains a negative value in a field that must be non-negative.', $ctPeriodId);
        }
        if ($used - $broughtForward >= 0.005 || $used - max(0.0, $taxableBeforeLosses) >= 0.005) {
            $diagnostics[] = $this->lossDiagnostic('loss_use_exceeds_available', 'Losses used exceed the available brought-forward loss or current taxable profit.', $ctPeriodId);
        }
        $expectedCreated = $taxableBeforeLosses < 0 ? abs($taxableBeforeLosses) : 0.0;
        if (abs($created - $expectedCreated) >= 0.005) {
            $diagnostics[] = $this->lossDiagnostic('loss_created_cross_cast', 'The loss created does not agree to the negative taxable result before losses.', $ctPeriodId);
        }
        if (abs($carriedForward - round($broughtForward - $used + $created, 2)) >= 0.005) {
            $diagnostics[] = $this->lossDiagnostic('loss_carried_forward_cross_cast', 'Losses carried forward do not cross-cast from brought forward, used, and created amounts.', $ctPeriodId);
        }
        if (abs($taxableProfit - round(max(0.0, $taxableBeforeLosses - $used), 2)) >= 0.005) {
            $diagnostics[] = $this->lossDiagnostic('loss_taxable_profit_cross_cast', 'Taxable profit does not cross-cast from the result before losses and losses used.', $ctPeriodId);
        }

        $unique = [];
        foreach ($diagnostics as $diagnostic) {
            $unique[(string)$diagnostic['code']] = $diagnostic;
        }
        return array_values($unique);
    }

    /** @return array{expected: bool, available: bool, summary?: array<string, mixed>} */
    private function predecessorState(int $companyId, string $periodStart): array
    {
        if ($this->predecessorFetcher !== null) {
            return (array)($this->predecessorFetcher)($companyId, $periodStart);
        }
        if ($companyId <= 0 || $periodStart === '' || !\InterfaceDB::tableExists('corporation_tax_periods')) {
            return ['expected' => false, 'available' => true];
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT ctp.status, ctp.period_end, ctp.latest_computation_run_id,
                    COALESCE(yer.is_locked, 0) AS accounting_period_locked,
                    cr.summary_json
             FROM corporation_tax_periods ctp
             INNER JOIN accounting_periods ap ON ap.id = ctp.accounting_period_id
             LEFT JOIN year_end_reviews yer
               ON yer.company_id = ctp.company_id AND yer.accounting_period_id = ctp.accounting_period_id
             LEFT JOIN corporation_tax_computation_runs cr
               ON cr.id = ctp.latest_computation_run_id
              AND cr.company_id = ctp.company_id
              AND cr.accounting_period_id = ctp.accounting_period_id
              AND cr.ct_period_id = ctp.id
             WHERE ctp.company_id = :company_id
               AND ctp.status <> :superseded
               AND ctp.period_end < :period_start
             ORDER BY ctp.period_end DESC, ctp.id DESC
             LIMIT 1',
            ['company_id' => $companyId, 'superseded' => 'superseded', 'period_start' => $periodStart]
        );
        if (!is_array($row)) {
            return ['expected' => false, 'available' => true];
        }

        $final = (int)($row['accounting_period_locked'] ?? 0) === 1
            || in_array((string)($row['status'] ?? ''), ['submitted', 'accepted'], true);
        $summary = json_decode((string)($row['summary_json'] ?? ''), true);
        if (!$final || (int)($row['latest_computation_run_id'] ?? 0) <= 0 || !is_array($summary)) {
            return ['expected' => true, 'available' => false];
        }

        return ['expected' => true, 'available' => true, 'summary' => $summary];
    }

    private function treatmentAmount(array $source, string $treatment): float
    {
        $key = $treatment . '_treatment_amount';
        if (array_key_exists($key, $source)) {
            return round(abs((float)$source[$key]), 2);
        }
        return (int)($source[$treatment . '_treatment_count'] ?? 0) > 0 ? 0.01 : 0.0;
    }

    /** @return array{0: string, 1: string} */
    private function warningRoute(string $warning): array
    {
        if (str_contains($warning, 'vehicle') || str_contains($warning, 'car ') || str_contains($warning, 'co2')) {
            return ['vehicle', 'vehicles'];
        }
        if (str_contains($warning, 'disposal') || str_contains($warning, 'balancing') || str_contains($warning, 'cessation')) {
            return ['disposal', 'assets'];
        }
        if (str_contains($warning, 'loss')) {
            return ['loss', 'corporation_tax'];
        }
        if (str_contains($warning, 'capital allowance') || str_contains($warning, 'fixed asset') || str_contains($warning, 'pool')) {
            return ['capital_allowance', 'assets'];
        }
        if (str_contains($warning, 'prepayment')) {
            return ['prepayment', 'prepayments'];
        }
        return ['tax_computation', 'corporation_tax'];
    }

    private function isGeneralCalculationAssumption(string $warning): bool
    {
        return str_contains($warning, 'assumes non-ring-fence profits')
            || str_contains($warning, 'assumes augmented profits equal taxable profits');
    }

    private function lossDiagnostic(string $code, string $message, int $ctPeriodId): array
    {
        return $this->diagnostic($code, 'loss', $message, 'corporation_tax', $ctPeriodId);
    }

    private function diagnostic(string $code, string $category, string $message, string $workflowPage, int $ctPeriodId): array
    {
        return [
            'code' => $code,
            'category' => $category,
            'severity' => 'hard_failure',
            'amount_affecting' => true,
            'message' => $message,
            'workflow_page' => $workflowPage,
            'workflow_fields' => $ctPeriodId > 0 ? ['ct_period_id' => (string)$ctPeriodId] : [],
        ];
    }
}
