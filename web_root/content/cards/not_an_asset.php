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
    private const PAGE_SIZE = 15;

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

        $thresholdForm = $this->thresholdForm($companyId, $accountingPeriodId, $threshold, $settings);

        if ($nominalId <= 0 || empty($data['available'])) {
            return $thresholdForm
                . '<div class="helper">Set the Tools &amp; Small Equipment nominal on Company Nominals before reviewing potential assets.</div>';
        }

        return $thresholdForm . $this->configuredTable($context)->render(
            $context,
            [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]
        );
    }

    public function tables(array $context): array
    {
        return [$this->table($context)];
    }

    private function configuredTable(array $context): TableFramework
    {
        $rows = $this->rows($context);
        $pagination = HelperFramework::paginateArray($rows, $this->paginationPage($context), self::PAGE_SIZE);

        return $this->table($context)
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Potential asset items',
                $this->paginationPageField(),
                [
                    'page' => (string)($context['page']['page_id'] ?? 'assets'),
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => [$this->key()],
                ]
            );
    }

    private function table(array $context): TableFramework
    {
        $company = (array)($context['company'] ?? []);
        $settings = (array)($company['settings'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $companySettingsService = new \eel_accounts\Service\CompanySettingsService();

        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('non-assets-potential-fixed-assets')
            ->exportLimit(5000)
            ->empty('No Tools & Small Equipment items are over the selected threshold.')
            ->column(
                'date',
                'Date',
                html: fn(array $row): string => HelperFramework::escape($this->displayDate((string)($row['date'] ?? ''))),
                export: static fn(array $row): string => (string)($row['date'] ?? ''),
                exportType: 'date'
            )
            ->textColumn('source', 'Source')
            ->textColumn('description', 'Description')
            ->textColumn('reference', 'Reference')
            ->column(
                'amount',
                'Amount',
                html: static fn(array $row): string => HelperFramework::escape($companySettingsService->money($settings, (float)($row['amount'] ?? 0))),
                export: static fn(array $row): string => FormattingFramework::money((float)($row['amount'] ?? 0)),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'action',
                'Action',
                html: fn(array $row): string => $this->openSourceButton($row, $companyId, $accountingPeriodId),
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function rows(array $context): array
    {
        $data = (array)(($context['services'] ?? [])['nonAssetCandidates'] ?? []);

        return array_values(array_filter(
            (array)($data['rows'] ?? []),
            static fn(mixed $row): bool => is_array($row)
        ));
    }

    private function thresholdForm(int $companyId, int $accountingPeriodId, int $threshold, array $settings): string
    {
        $companySettingsService = new \eel_accounts\Service\CompanySettingsService();
        $options = '';
        foreach (\eel_accounts\Service\AssetService::potentialAssetThresholdOptions() as $option) {
            $optionValue = (string)$option;
            $options .= '<option value="' . HelperFramework::escape($optionValue) . '"' . ($option === $threshold ? ' selected' : '') . '>'
                . HelperFramework::escape($companySettingsService->money($settings, $option))
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

    private function openSourceButton(array $row, int $companyId, int $accountingPeriodId): string
    {
        $sourceType = (string)($row['source_type'] ?? '');
        $sourceId = (int)($row['source_id'] ?? 0);
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $sourceId <= 0) {
            return '';
        }

        if ($sourceType === 'transaction') {
            $monthKey = $this->monthKey((string)($row['date'] ?? ''));
            if ($monthKey === '') {
                return '';
            }

            return '<form method="get" data-ajax="true">
                <input type="hidden" name="page" value="transactions">
                <input type="hidden" name="show_card" value="transactions_imported">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="month_key" value="' . HelperFramework::escape($monthKey) . '">
                <input type="hidden" name="category_filter" value="all">
                <button class="button button-inline" type="submit">Open Source</button>
            </form>';
        }

        if ($sourceType === 'expense_claim') {
            $claimId = (int)($row['source_claim_id'] ?? 0);
            if ($claimId <= 0) {
                return '';
            }

            return '<form method="get" data-ajax="true">
                <input type="hidden" name="page" value="expense_claims">
                <input type="hidden" name="show_card" value="expense_claim_editor">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="claim_id" value="' . $claimId . '">
                <button class="button button-inline" type="submit">Open Source</button>
            </form>';
        }

        return '';
    }

    private function monthKey(string $date): string
    {
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }

        return substr($date, 0, 7) . '-01';
    }

    private function displayDate(string $value): string
    {
        $value = trim($value);

        return $value === '' ? '' : HelperFramework::displayDate($value);
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
    }
}
