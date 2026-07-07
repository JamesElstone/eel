<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _asset_taxCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'asset_tax';
    }

    public function title(): string
    {
        return 'Example Tax Prediction';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'assetPageData',
                'service' => \eel_accounts\Service\AssetService::class,
                'method' => 'fetchTaxData',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return 'Asset data could not be loaded: ' . (string)($error['message'] ?? 'service error');
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $company = (array)($context['company'] ?? []);
        $settings = (array)($company['settings'] ?? []);
        $assetsPageData = (array)($context['services']['assetPageData'] ?? []);
        $assetTaxView = is_array($assetsPageData['tax_view'] ?? null) ? $assetsPageData['tax_view'] : null;
        $accountingPeriodId = (int)(
            $assetsPageData['accounting_period_id']
            ?? $page['accounting_period_id']
            ?? $context['accounting_period_id']
            ?? $company['accounting_period_id']
            ?? 0
        );

        if ($assetTaxView === null) {
            return '<div class="helper">' . HelperFramework::escape($this->emptyStateMessage($assetsPageData, $accountingPeriodId)) . '</div>';
        }

        return $this->taxTable($settings, $assetTaxView);
    }

    private function money(array $settings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($settings, $value);
    }

    private function taxTable(array $settings, array $assetTaxView): string
    {
        $periods = array_values(array_filter(
            (array)($assetTaxView['periods'] ?? []),
            static fn(mixed $period): bool => is_array($period)
        ));
        $totals = (array)($assetTaxView['totals'] ?? $assetTaxView);
        $rows = [
            ['Accounting Profit', 'accounting_profit'],
            ['+ Disallowable Expenses', 'disallowable_add_backs'],
            ['+ Depreciation', 'depreciation_add_back'],
            ['- Capital Allowances', 'capital_allowances'],
            ['= Taxable Profit Before Losses', 'taxable_before_losses'],
            ['Losses B/F', 'losses_brought_forward'],
            ['Losses Used', 'losses_used'],
            ['Losses C/F', 'losses_carried_forward'],
            ['Taxable Profit', 'taxable_profit'],
        ];

        $head = '<th>Calculation</th>';
        if ($periods === []) {
            $head .= '<th>Amount</th>';
        } else {
            foreach ($periods as $period) {
                $head .= '<th>' . HelperFramework::escape($this->periodHeading($period)) . '</th>';
            }
            $head .= '<th>Total</th>';
        }

        $body = '';
        foreach ($rows as [$label, $key]) {
            $body .= '<tr><td>' . HelperFramework::escape($label) . '</td>';
            if ($periods === []) {
                $body .= '<td>' . HelperFramework::escape($this->money($settings, $assetTaxView[$key] ?? 0)) . '</td>';
            } else {
                foreach ($periods as $period) {
                    $body .= '<td>' . HelperFramework::escape($this->money($settings, $period[$key] ?? 0)) . '</td>';
                }
                $body .= '<td>' . HelperFramework::escape($this->money($settings, $totals[$key] ?? 0)) . '</td>';
            }
            $body .= '</tr>';
        }

        return '<div class="table-scroll asset-tax-table"><table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
    }

    private function periodHeading(array $period): string
    {
        $label = trim((string)($period['period_label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        $start = trim((string)($period['period_start'] ?? ''));
        $end = trim((string)($period['period_end'] ?? ''));
        return trim($start . ' to ' . $end) !== 'to' ? trim($start . ' to ' . $end) : 'CT period';
    }

    private function emptyStateMessage(array $assetsPageData, int $accountingPeriodId): string
    {
        if ($accountingPeriodId <= 0) {
            return 'Select an accounting period to view tax adjustments.';
        }

        if (array_key_exists('schema_ready', $assetsPageData) && empty($assetsPageData['schema_ready'])) {
            return 'Run the fixed asset migrations before viewing tax adjustments.';
        }

        return 'No tax view is available for the selected accounting period.';
    }
}
