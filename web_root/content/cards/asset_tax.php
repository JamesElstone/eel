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

    public function services(): array
    {
        return [
            [
                'key' => 'assetPageData',
                'service' => AssetService::class,
                'method' => 'fetchPageData',
                'params' => [
                    'companyId' => ':company_id',
                    'taxYearId' => ':tax_year_id',
                    'defaultBankNominalId' => ':default_bank_nominal_id',
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
        return '';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $company = (array)($context['company'] ?? []);
        $assetsPageData = (array)($context['services']['assetPageData'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $taxYearId = (int)($company['tax_year_id'] ?? 0);
        $pageId = trim((string)($page['page_id'] ?? ''));
        $assetTaxView = is_array($assetsPageData['tax_view'] ?? null) ? $assetsPageData['tax_view'] : null;
        $assetTaxYears = is_array($assetsPageData['tax_years'] ?? null) ? $assetsPageData['tax_years'] : [];

        return '
           <form class="toolbar" method="post" data-ajax="true" data-accounting-period-selector="true">
                <input type="hidden" name="action" value="set-page-context">
                <input type="hidden" name="_ajax" value="1">
                <input type="hidden" name="page" value="' . HelperFramework::escape($pageId) . '">
                <input type="hidden" name="company_id" value="' . HelperFramework::escape((string)$companyId) . '">
                <div class="mini-field">
                    <label for="asset_tax_year_id">Accounting Period</label>
                    <select class="select" id="asset_tax_year_id" name="tax_year_id">' . $this->taxYearOptions($assetTaxYears, $taxYearId) . '</select>
                </div>
            </form>
            ' . ($assetTaxView !== null
                ? '<div class="list">
                    <div class="list-item"><strong>Accounting Profit</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['accounting_profit'] ?? 0))) . '</span></div>
                    <div class="list-item"><strong>+ Depreciation</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['depreciation_add_back'] ?? 0))) . '</span></div>
                    <div class="list-item"><strong>- Capital Allowances</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['capital_allowances'] ?? 0))) . '</span></div>
                    <div class="list-item"><strong>= Taxable Profit Before Losses</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['taxable_before_losses'] ?? 0))) . '</span></div>
                    <div class="list-item"><strong>Losses B/F</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['losses_brought_forward'] ?? 0))) . '</span></div>
                    <div class="list-item"><strong>Losses Used</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['losses_used'] ?? 0))) . '</span></div>
                    <div class="list-item"><strong>Losses C/F</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['losses_carried_forward'] ?? 0))) . '</span></div>
                    <div class="list-item"><strong>Taxable Profit</strong><span>' . HelperFramework::escape(FormattingFramework::money((float)($assetTaxView['taxable_profit'] ?? 0))) . '</span></div>
                </div>'
                : '<div class="helper">Select a company and accounting period to view tax adjustments.</div>') . '
        ';
    }

    private function taxYearOptions(array $assetTaxYears, int $taxYearId): string
    {
        $html = '';
        foreach ($assetTaxYears as $taxYear) {
            $optionTaxYearId = (int)($taxYear['id'] ?? 0);
            $selected = $optionTaxYearId === $taxYearId ? ' selected' : '';
            $html .= '<option value="' . HelperFramework::escape((string)$optionTaxYearId) . '"' . $selected . '>' . HelperFramework::escape((string)($taxYear['label'] ?? '')) . '</option>';
        }

        return $html;
    }

}
