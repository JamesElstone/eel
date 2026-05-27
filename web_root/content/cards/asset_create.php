<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _asset_createCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'asset_create';
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
        $accountingPeriods = (array)($page['accounting_periods'] ?? []);
        $nominalAccounts = (array)($page['nominal_accounts'] ?? []);
        $prefillTransaction = is_array($assetsPageData['prefill_transaction'] ?? null) ? $assetsPageData['prefill_transaction'] : null;
        $assetCategories = is_array($assetsPageData['asset_categories'] ?? null) ? $assetsPageData['asset_categories'] : [];

        return '
            <form method="post" action="?page=assets" data-ajax="true">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
                . ($prefillTransaction !== null
                    ? '<input type="hidden" name="transaction_id" value="' . (int)($prefillTransaction['transaction_id'] ?? 0) . '">'
                    : '') . '
                <input type="hidden" name="global_action" value="' . HelperFramework::escape($prefillTransaction !== null ? 'create_asset_from_transaction' : 'create_manual_asset') . '">
                <div class="form-grid">
                    <div class="field">
                        <label for="asset_description">Description</label>
                        <input class="input" id="asset_description" type="text" name="description" value="' . HelperFramework::escape((string)($prefillTransaction['description'] ?? '')) . '" required>
                    </div>
                    <div class="field">
                        <label for="asset_category">Category</label>
                        <select class="select" id="asset_category" name="category">' . $this->assetCategoryOptions($assetCategories) . '</select>
                    </div>
                    <div class="field">
                        <label for="asset_purchase_date">Purchase Date</label>
                        <input class="input" id="asset_purchase_date" type="date" name="purchase_date" value="' . HelperFramework::escape((string)($prefillTransaction['purchase_date'] ?? '')) . '" required>
                    </div>
                    <div class="field">
                        <label for="asset_cost">Cost</label>
                        <input class="input" id="asset_cost" type="number" step="0.01" name="cost" value="' . HelperFramework::escape((string)($prefillTransaction['cost'] ?? '')) . '" required>
                    </div>
                    <div class="field">
                        <label for="asset_life">Useful Life (Years)</label>
                        <input class="input" id="asset_life" type="number" min="1" name="useful_life_years" value="3" required>
                    </div>
                    <div class="field">
                        <label for="asset_method">Depreciation Method</label>
                        <select class="select" id="asset_method" name="depreciation_method">
                            <option value="straight_line">Straight line</option>
                            <option value="reducing_balance">Reducing balance</option>
                            <option value="none">None</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="asset_residual">Residual Value</label>
                        <input class="input" id="asset_residual" type="number" step="0.01" min="0" name="residual_value" value="0.00">
                    </div>'
                    . ($prefillTransaction === null
                        ? '<div class="field">
                        <label for="asset_offset_nominal_id">Credit Nominal</label>
                        <select class="select" id="asset_offset_nominal_id" name="offset_nominal_id">' . $this->nominalOptions($nominalAccounts, (int)($assetsPageData['default_bank_nominal_id'] ?? 0)) . '</select>
                    </div>'
                        : '') . '
                </div>
                <div>
                    <button class="button primary" type="submit">' . HelperFramework::escape($prefillTransaction !== null ? 'Create Asset' : 'Post Asset') . '</button>
                </div>
            </form>
        ';
    }

    private function assetCategoryOptions(array $assetCategories): string
    {
        $html = '';
        foreach ($assetCategories as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape((string)$value) . '">' . HelperFramework::escape((string)$label) . '</option>';
        }

        return $html;
    }

    private function nominalOptions(array $nominalAccounts, int $selectedId): string
    {
        $html = '';
        foreach ($nominalAccounts as $nominal) {
            $nominalId = (int)($nominal['id'] ?? 0);
            $selected = $nominalId === $selectedId ? ' selected' : '';
            $html .= '<option value="' . $nominalId . '"' . $selected . '>' . HelperFramework::escape(FormattingFramework::nominalLabel((array)$nominal, ' ')) . '</option>';
        }

        return $html;
    }
}
