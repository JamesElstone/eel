<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _uploads_statement_coverageCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'uploads_statement_coverage';
    }

    public function title(): string
    {
        return 'Statement Coverage';
    }

    public function helper(array $context): string
    {
        return 'Coverage is shown separately for each bank account. Red months indicate balance-continuity failures. Amber months have no uploaded rows unless surrounding statement balances prove no missing movement.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'statement_coverage_heatmap',
                'service' => \eel_accounts\Service\UploadStatementCoverageService::class,
                'method' => 'buildHeatmapOptions',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['transactions.imported'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $options = (array)($context['services']['statement_coverage_heatmap'] ?? []);
        $accountingPeriod = (array)($context['accounting_period'] ?? []);
        $accountingPeriodLabel = trim((string)($accountingPeriod['label'] ?? ''));

        if ($options === []) {
            return '<div class="helper">Select a company and accounting period to see statement coverage.</div>';
        }

        if ($accountingPeriodLabel !== '') {
            $options['label'] = 'Statement Coverage from ' . $accountingPeriodLabel;
        }

        $chartService = new ChartService();
        $accountHeatmaps = (array)($options['account_heatmaps'] ?? []);

        if ($accountHeatmaps !== []) {
            $html = '<div class="stack uploads-statement-coverage-account-heatmaps">';

            foreach ($accountHeatmaps as $accountOptions) {
                if (!is_array($accountOptions)) {
                    continue;
                }

                if ($accountingPeriodLabel !== '') {
                    $accountOptions['label'] = trim((string)($accountOptions['account_label'] ?? $accountOptions['label'] ?? 'Statement Coverage')) . ' from ' . $accountingPeriodLabel;
                }

                $accountOptions['legend'] = false;
                $html .= $chartService->monthHeatmap($accountOptions);
            }

            return $html . $this->sharedLegend() . '</div>';
        }

        return $chartService->monthHeatmap($options);
    }

    private function sharedLegend(): string
    {
        return '<div class="month-heatmap-legend"><span class="month-heatmap-legend-item"><span class="month-heatmap-legend-swatch month-heatmap-cell--pass"></span>Covered</span><span class="month-heatmap-legend-item"><span class="month-heatmap-legend-swatch month-heatmap-cell--warning"></span>Needs review</span><span class="month-heatmap-legend-item"><span class="month-heatmap-legend-swatch month-heatmap-cell--fail"></span>Gap</span><span class="month-heatmap-legend-item"><span class="month-heatmap-legend-swatch month-heatmap-cell--muted"></span>No data</span></div>';
    }
}
