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
        return 'Red months indicate balance-continuity failures. Amber months have no uploaded rows unless surrounding statement balances prove no missing movement.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'statement_coverage_heatmap',
                'service' => UploadStatementCoverageService::class,
                'method' => 'buildHeatmapOptions',
                'params' => [
                    'companyId' => ':company.id',
                    'taxYearId' => ':company.tax_year_id',
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
        $taxYear = (array)($context['tax_year'] ?? []);
        $taxYearLabel = trim((string)($taxYear['label'] ?? ''));

        if ($options === []) {
            return '<div class="helper">Select a company and accounting period to see statement coverage.</div>';
        }

        if ($taxYearLabel !== '') {
            $options['label'] = 'Statement Coverage from ' . $taxYearLabel;
        }

        return (new ChartService())->monthHeatmap($options);
    }
}
