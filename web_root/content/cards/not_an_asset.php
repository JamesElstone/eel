<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _not_an_assetCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'not_an_asset';
    }

    public function title(): string
    {
        return 'Non-Assets';
    }

    public function helper(array $context): string
    {
        return 'Review higher-value Tools & Small Equipment purchases that may need fixed asset treatment.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'nonAssetCandidates',
                'service' => \eel_accounts\Service\AssetService::class,
                'method' => 'fetchNonAssetCandidates',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'toolsSmallEquipmentNominalId' => ':company.settings.tools_small_equipment_nominal_id',
                    'threshold' => ':company.settings.potential_asset_threshold',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['asset.not_an_asset', 'page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return 'Non-assets data could not be loaded: ' . (string)($error['message'] ?? 'service error');
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $settings = (array)($company['settings'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $data = (array)($context['services']['nonAssetCandidates'] ?? []);
        $threshold = \eel_accounts\Service\AssetService::normalisePotentialAssetThreshold($data['threshold'] ?? ($settings['potential_asset_threshold'] ?? 250));
        $nominalId = (int)($settings['tools_small_equipment_nominal_id'] ?? 0);

        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return '<div class="helper">Select a company and accounting period before reviewing non-assets.</div>';
        }

        $thresholdForm = $this->thresholdForm($companyId, $accountingPeriodId, $threshold);

        if ($nominalId <= 0 || empty($data['available'])) {
            return $thresholdForm
                . '<div class="helper">Set the Tools &amp; Small Equipment nominal on Company Nominals before reviewing potential assets.</div>';
        }

        $rows = (array)($data['rows'] ?? []);
        $rowsHtml = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape($this->displayDate((string)($row['date'] ?? ''))) . '</td>
                <td>' . HelperFramework::escape((string)($row['source'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['description'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['reference'] ?? '')) . '</td>
                <td class="numeric">' . HelperFramework::escape(FormattingFramework::money((float)($row['amount'] ?? 0))) . '</td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="5">No Tools &amp; Small Equipment items are over the selected threshold.</td></tr>';
        }

        return $thresholdForm . '
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Source</th>
                            <th>Description</th>
                            <th>Reference</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>
        ';
    }

    private function thresholdForm(int $companyId, int $accountingPeriodId, int $threshold): string
    {
        $options = '';
        foreach (\eel_accounts\Service\AssetService::potentialAssetThresholdOptions() as $option) {
            $optionValue = (string)$option;
            $options .= '<option value="' . HelperFramework::escape($optionValue) . '"' . ($option === $threshold ? ' selected' : '') . '>'
                . HelperFramework::escape($optionValue)
                . '</option>';
        }

        return '<form method="post" action="?page=assets" data-ajax="true" class="toolbar">
            <input type="hidden" name="card_action" value="Asset">
            <input type="hidden" name="intent" value="save_potential_asset_threshold">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <label for="potential_asset_threshold">Threshold</label>
            <select class="select" id="potential_asset_threshold" name="potential_asset_threshold">' . $options . '</select>
            <button class="button primary" type="submit">Save Threshold</button>
        </form>';
    }

    private function displayDate(string $value): string
    {
        $value = trim($value);

        return $value === '' ? '' : HelperFramework::displayDate($value);
    }
}
