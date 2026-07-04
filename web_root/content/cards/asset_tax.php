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
                'method' => 'fetchPageData',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'defaultBankNominalId' => ':company.settings.default_bank_nominal_id',
                    'prefillTransactionId' => ':prefill_transaction_id',
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

        $calculationRows = [
            ['Accounting Profit', (float)($assetTaxView['accounting_profit'] ?? 0)],
            ['+ Disallowable Expenses', (float)($assetTaxView['disallowable_add_backs'] ?? 0)],
            ['+ Depreciation', (float)($assetTaxView['depreciation_add_back'] ?? 0)],
            ['- Capital Allowances', (float)($assetTaxView['capital_allowances'] ?? 0)],
            ['= Taxable Profit Before Losses', (float)($assetTaxView['taxable_before_losses'] ?? 0)],
            ['Losses B/F', (float)($assetTaxView['losses_brought_forward'] ?? 0)],
            ['Losses Used', (float)($assetTaxView['losses_used'] ?? 0)],
            ['Losses C/F', (float)($assetTaxView['losses_carried_forward'] ?? 0)],
            ['Taxable Profit', (float)($assetTaxView['taxable_profit'] ?? 0)],
        ];

        $rowsHtml = '';
        foreach ($calculationRows as [$label, $amount]) {
            $rowsHtml .= '<tr><td>' . HelperFramework::escape($label) . '</td><td>' . HelperFramework::escape($this->money($settings, $amount)) . '</td></tr>';
        }

        return '<div class="table-scroll asset-tax-table"><table><thead><tr><th>Calculation</th><th>Amount</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table></div>';
    }

    private function money(array $settings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($settings, $value);
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
