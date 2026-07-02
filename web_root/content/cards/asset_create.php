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
                'service' => \eel_accounts\Service\AssetService::class,
                'method' => 'fetchPageData',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'defaultBankNominalId' => ':company.settings.default_bank_nominal_id',
                    'prefillTransactionId' => ':prefill_transaction_id',
                ],
            ],
            [
                'key' => 'nominal_accounts',
                'service' => \eel_accounts\Repository\NominalAccountRepository::class,
                'method' => 'fetchNominalAccounts',
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
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $accountingPeriods = (array)($page['accounting_periods'] ?? []);
        $services = (array)($context['services'] ?? []);
        $nominalAccounts = (array)($services['nominal_accounts'] ?? $page['nominal_accounts'] ?? []);
        $prefillTransaction = is_array($assetsPageData['prefill_transaction'] ?? null) ? $assetsPageData['prefill_transaction'] : null;
        $assetCategories = is_array($assetsPageData['asset_categories'] ?? null)
            ? $assetsPageData['asset_categories']
            : \eel_accounts\Service\AssetService::assetCategoryOptions();
        $isManualAsset = $prefillTransaction === null;
        $formAttributes = $isManualAsset
            ? 'method="post" enctype="multipart/form-data" action="?page=assets" data-manual-asset-form="true"'
            : 'method="post" action="?page=assets" data-ajax="true"';

        return '
            <form class="asset-create-form" ' . $formAttributes . '>
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
                . ($prefillTransaction !== null
                    ? '<input type="hidden" name="transaction_id" value="' . (int)($prefillTransaction['transaction_id'] ?? 0) . '">'
                    : '') . '
                <input type="hidden" name="card_action" value="Asset">
                <input type="hidden" name="default_bank_nominal_id" value="' . (int)($assetsPageData['default_bank_nominal_id'] ?? 0) . '">
                <input type="hidden" name="global_action" value="' . HelperFramework::escape($prefillTransaction !== null ? 'create_asset_from_transaction' : 'create_manual_asset') . '">
                ' . ($isManualAsset ? '<input type="hidden" name="manual_asset_legal_acknowledged" value="0" data-manual-asset-legal-acknowledged>' : '') . '
                <div class="asset-create-controls">
                    <div class="field">
                        <label for="asset_description">Description</label>
                        <input class="input" id="asset_description" type="text" name="description" value="' . HelperFramework::escape((string)($prefillTransaction['description'] ?? '')) . '" required>
                    </div>
                    <div class="field">
                        <label for="asset_category">Asset category</label>
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
                        <label for="asset_life">Useful life</label>
                        <input class="input" id="asset_life" type="number" min="1" name="useful_life_years" value="3" required>
                    </div>
                    <div class="field">
                        <label for="asset_method" title="None: no depreciation is posted. Straight Line: spreads cost less EOL Value evenly over the useful life. Reducing Balance: depreciates by the same rate each period, using the asset&apos;s remaining value after previous depreciation.">Depreciation</label>
                        <select class="select" id="asset_method" name="depreciation_method">
                            <option value="straight_line">Straight line</option>
                            <option value="reducing_balance">Reducing balance</option>
                            <option value="none">None</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="asset_residual" title="End of Life Value, also known as the Residual Value, is the value the item has after the useful life period has expired.">EOL Value</label>
                        <input class="input" id="asset_residual" type="number" step="0.01" min="0" name="residual_value" value="0.00">
                    </div>'
                    . ($prefillTransaction === null
                        ? '<div class="field">
                        <label for="asset_manual_addition_reason">Manual addition reason</label>
                        <select class="select" id="asset_manual_addition_reason" name="manual_addition_reason" required>' . $this->manualAdditionReasonOptions() . '</select>
                    </div>
                    <div class="field">
                        <label for="asset_offset_nominal_id">Funding / clearing nominal</label>
                        <select class="select" id="asset_offset_nominal_id" name="offset_nominal_id">' . $this->nominalOptions($nominalAccounts, (int)($assetsPageData['default_bank_nominal_id'] ?? 0)) . '</select>
                    </div>
                    <div class="field asset-evidence-field">
                        <label for="manual_asset_evidence">Evidence file</label>
                        <div class="upload-box upload-dropzone" data-upload-dropzone data-upload-max-files="1">
                            <input class="input" id="manual_asset_evidence" type="file" name="manual_asset_evidence" accept=".jpg,.jpeg,.pdf,image/jpeg,application/pdf" required data-upload-input>
                            <label data-upload-selection-summary></label>
                            <ul class="file-list" data-upload-file-list hidden></ul>
                        </div>
                    </div>'
                        : '') . '
                    <button class="button primary" type="submit"' . ($isManualAsset ? $this->manualAssetLegalWarningAttributes() . ' data-upload-submit' : '') . '>' . HelperFramework::escape($prefillTransaction !== null ? 'Create Asset' : 'Post Asset') . ($isManualAsset ? '<img class="upload-processing-icon is-hidden" src="svg/loader.svg" alt="" aria-hidden="true" data-upload-processing-icon>' : '') . '</button>
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

    private function manualAdditionReasonOptions(): string
    {
        $html = '<option value="">Select reason</option>';
        foreach (\eel_accounts\Service\AssetService::manualAdditionReasonOptions() as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape((string)$value) . '">' . HelperFramework::escape((string)$label) . '</option>';
        }

        return $html;
    }

    private function nominalOptions(array $nominalAccounts, int $selectedId): string
    {
        $html = '';
        foreach ($nominalAccounts as $nominal) {
            if (!$this->isFundingNominalCandidate((array)$nominal)) {
                continue;
            }

            $nominalId = (int)($nominal['id'] ?? 0);
            $selected = $nominalId === $selectedId ? ' selected' : '';
            $html .= '<option value="' . $nominalId . '"' . $selected . '>' . HelperFramework::escape(FormattingFramework::nominalLabel((array)$nominal, ' ')) . '</option>';
        }

        return $html;
    }

    private function isFundingNominalCandidate(array $nominal): bool
    {
        return \eel_accounts\Service\AssetService::isManualAssetOffsetNominalCandidate($nominal);
    }

    private function manualAssetLegalWarningAttributes(): string
    {
        $message = 'Creating a manual fixed asset records a formal accounting entry and may affect statutory accounts and corporation tax. '
            . 'Knowingly recording a non-existent asset, claiming capital allowances for it, or later disposing of it to hide the original entry may amount to false accounting or tax evasion. '
            . 'Only continue if the asset exists, the evidence file supports that, and the entry is complete and accurate.';

        return ' data-manual-asset-legal-check="true"'
            . ' data-manual-asset-warning-title="Manual asset legal warning"'
            . ' data-manual-asset-warning-message="' . HelperFramework::escape($message) . '"'
            . ' data-manual-asset-warning-confirm-text="Acknowledge and Post"';
    }
}
