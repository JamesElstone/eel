<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _asset_registerCard extends CardBaseFramework
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
                'service' => \eel_accounts\Service\AssetService::class,
                'method' => 'fetchPageData',
                'params' => [
                    'companyId' => ':company_id',
                    'accountingPeriodId' => ':accounting_period_id',
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
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
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
                    ? '<form method="post" action="?page=assets" data-ajax="true">
                        <input type="hidden" name="company_id" value="' . $companyId . '">
                        <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                        <input type="hidden" name="asset_id" value="' . (int)($asset['id'] ?? 0) . '">
                        <input type="hidden" name="global_action" value="dispose_asset">
                        <div>
                            <input class="input" type="date" name="disposal_date" value="' . HelperFramework::escape(date('Y-m-d')) . '">
                            <input class="input" type="number" step="0.01" name="disposal_proceeds" placeholder="Proceeds">
                            <button class="button" type="submit">Dispose</button>
                        </div>
                    </form>'
                    : '<span class="helper">Disposed on ' . HelperFramework::escape($this->displayDate((string)($asset['disposal_date'] ?? ''))) . '</span>') . '</td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="6">No assets have been recorded yet.</td></tr>';
        }

        return '
            <div>
                <form method="post" action="?page=assets" data-ajax="true">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
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
        ';
    }

    private function displayDate(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return HelperFramework::displayDate($value);
    }

}
