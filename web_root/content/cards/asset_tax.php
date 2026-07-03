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
        $assetsPageData = (array)($context['services']['assetPageData'] ?? []);
        $assetTaxView = is_array($assetsPageData['tax_view'] ?? null) ? $assetsPageData['tax_view'] : null;
        $accountingPeriodId = (int)(
            $assetsPageData['accounting_period_id']
            ?? $page['accounting_period_id']
            ?? $context['accounting_period_id']
            ?? $company['accounting_period_id']
            ?? 0
        );

        return $assetTaxView !== null
            ? '<div class="list">
                    <div class="list-item"><strong>Accounting Profit</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['accounting_profit'] ?? 0))) . '</span></div>
                    <div class="list-item"><strong>+ Disallowable Expenses</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['disallowable_add_backs'] ?? 0))) . '</span></div>
                    <div class="list-item"><strong>+ Depreciation</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['depreciation_add_back'] ?? 0))) . '</span></div>
                    <div class="list-item"><strong>- Capital Allowances</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['capital_allowances'] ?? 0))) . '</span></div>
                    <div class="list-item"><strong>= Taxable Profit Before Losses</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['taxable_before_losses'] ?? 0))) . '</span></div>
                    <div class="list-item"><strong>Losses B/F</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['losses_brought_forward'] ?? 0))) . '</span></div>
                    <div class="list-item"><strong>Losses Used</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['losses_used'] ?? 0))) . '</span></div>
                    <div class="list-item"><strong>Losses C/F</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['losses_carried_forward'] ?? 0))) . '</span></div>
                    <div class="list-item"><strong>Taxable Profit</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['taxable_profit'] ?? 0))) . '</span></div>
                </div>'
            : '<div class="helper">' . HelperFramework::escape($this->emptyStateMessage($assetsPageData, $accountingPeriodId)) . '</div>';
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
