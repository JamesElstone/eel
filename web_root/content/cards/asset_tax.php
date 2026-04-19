<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _asset_taxCard implements CardInterfaceFramework
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

    public function invalidationFacts(): array
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
        $assetsPageData = (array)($context['services']['assetPageData'] ?? []);
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $selectedTaxYearId = (int)($page['selected_tax_year_id'] ?? $page['tax_year_id'] ?? 0);
        $assetTaxView = is_array($assetsPageData['tax_view'] ?? null) ? $assetsPageData['tax_view'] : null;
        $assetTaxYears = is_array($assetsPageData['tax_years'] ?? null) ? $assetsPageData['tax_years'] : [];

        return '<div class="card">
            <div class="card-header">
                <h2 class="card-title">Tax View</h2>
            </div>
            <div class="card-body">
                <form class="toolbar" method="get" action="" data-ajax-card-form="true" data-ajax-card-update="assets-register,assets-create-form,assets-tax-view">
                    <input type="hidden" name="page" value="assets">
                    <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                    <div class="mini-field">
                        <label for="asset_tax_year_id">Accounting Period</label>
                        <select class="select" id="asset_tax_year_id" name="tax_year_id" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">' . $this->taxYearOptions($assetTaxYears, $selectedTaxYearId) . '</select>
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
            </div>
        </div>';
    }

    private function taxYearOptions(array $assetTaxYears, int $selectedTaxYearId): string
    {
        $html = '';
        foreach ($assetTaxYears as $taxYear) {
            $taxYearId = (int)($taxYear['id'] ?? 0);
            $selected = $taxYearId === $selectedTaxYearId ? ' selected' : '';
            $html .= '<option value="' . $taxYearId . '"' . $selected . '>' . HelperFramework::escape((string)($taxYear['label'] ?? '')) . '</option>';
        }

        return $html;
    }

}
