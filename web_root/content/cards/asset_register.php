<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _asset_registerCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'asset_register';
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
        $settings = (array)($page['settings'] ?? []);
        $dateFormat = (string)($settings['date_format'] ?? '');
        $selectedTaxYearId = (int)($page['selected_tax_year_id'] ?? $page['tax_year_id'] ?? 0);
        $assets = is_array($assetsPageData['assets'] ?? null) ? $assetsPageData['assets'] : [];
        $rowsHtml = '';

        foreach ($assets as $asset) {
            $status = (string)($asset['status'] ?? 'active');
            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($asset['asset_code'] ?? '')) . '</td>
                <td>
                    <div>' . HelperFramework::escape((string)($asset['description'] ?? '')) . '</div>
                    <div class="helper">' . HelperFramework::escape(trim((string)($asset['nominal_code'] ?? '') . ' ' . (string)($asset['nominal_name'] ?? ''))) . '</div>
                </td>
                <td>' . HelperFramework::escape(FormattingFramework::money((float)($asset['cost'] ?? 0))) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money((float)($asset['nbv'] ?? 0))) . '</td>
                <td><span class="badge ' . HelperFramework::escape($status === 'disposed' ? 'warning' : 'success') . '">' . HelperFramework::escape($status) . '</span></td>
                <td>' . ($status !== 'disposed'
                    ? '<form method="post" action="' . HelperFramework::escape($this->assetsPageUrl($selectedCompanyId, $selectedTaxYearId)) . '" data-ajax-card-form="true" data-ajax-card-update="assets-register,assets-tax-view">
                        <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                        <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
                        <input type="hidden" name="asset_id" value="' . (int)($asset['id'] ?? 0) . '">
                        <input type="hidden" name="global_action" value="dispose_asset">
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <input class="input" type="date" name="disposal_date" value="' . HelperFramework::escape(date('Y-m-d')) . '" style="max-width: 150px;">
                            <input class="input" type="number" step="0.01" name="disposal_proceeds" placeholder="Proceeds" style="max-width: 130px;">
                            <button class="button" type="submit">Dispose</button>
                        </div>
                    </form>'
                    : '<span class="helper">Disposed on ' . HelperFramework::escape($this->displayDate((string)($asset['disposal_date'] ?? ''), $selectedCompanyId, $dateFormat)) . '</span>') . '</td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="6">No assets have been recorded yet.</td></tr>';
        }

        return '<div class="card" style="grid-column: 1 / -1;">
            <div class="card-header">
                <h2 class="card-title">Asset Registers</h2>
            </div>
            <div class="card-body">
                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px;">
                    <form method="post" action="' . HelperFramework::escape($this->assetsPageUrl($selectedCompanyId, $selectedTaxYearId)) . '" data-ajax-card-form="true" data-ajax-card-update="assets-schema-status,assets-register,assets-create-form,assets-tax-view">
                        <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">
                        <input type="hidden" name="tax_year_id" value="' . $selectedTaxYearId . '">
                        <input type="hidden" name="global_action" value="run_asset_depreciation">
                        <button class="button primary" type="submit">Run Depreciation</button>
                    </form>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Description</th>
                                <th>Cost</th>
                                <th>NBV</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>' . $rowsHtml . '</tbody>
                    </table>
                </div>
            </div>
        </div>';
    }

    private function assetsPageUrl(int $companyId, int $taxYearId): string
    {
        return '?page=assets&company_id=' . $companyId . '&tax_year_id=' . $taxYearId;
    }

    private function displayDate(string $value, int $companyId, string $dateFormat): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return HelperFramework::displayDate($value, $companyId, $dateFormat);
    }

}
