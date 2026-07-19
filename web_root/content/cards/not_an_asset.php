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
                'key' => 'nonAssetReview',
                'service' => \eel_accounts\Service\NonAssetReviewService::class,
                'method' => 'fetchContext',
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
        return ['asset.not_an_asset', 'page.context', 'year.end.state', 'year.end.checklist'];
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
        $review = (array)($context['services']['nonAssetReview'] ?? []);
        $data = (array)($review['candidates'] ?? []);
        $dataEntry = (array)($review['data_entry'] ?? []);
        $threshold = \eel_accounts\Service\AssetService::normalisePotentialAssetThreshold($data['threshold'] ?? ($settings['potential_asset_threshold'] ?? 250));
        $nominalId = (int)($settings['tools_small_equipment_nominal_id'] ?? 0);
        $dataEntryPermitted = !empty($dataEntry['permitted']) && empty($dataEntry['is_locked']);

        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return '<div class="helper">Select a company and accounting period before reviewing non-assets.</div>';
        }

        if ($nominalId <= 0 || empty($data['available'])) {
            return ($dataEntryPermitted ? $this->thresholdToolbar($companyId, $accountingPeriodId, $threshold, $settings) : $this->readOnlyHelper($dataEntry))
                . '<div class="helper">Set the Tools &amp; Small Equipment nominal on Company Nominals before reviewing potential assets.</div>';
        }

        $tableHtml = $this->renderTableWithThresholdToolbar(
            $this->configuredTable($context),
            $context,
            $dataEntryPermitted ? $this->thresholdForm($companyId, $accountingPeriodId, $threshold, $settings) : $this->readOnlyHelper($dataEntry)
        );
        $acknowledgement = $review['acknowledgement'] ?? null;

        return $tableHtml . $this->yearEndAcknowledgementHtml(
            is_array($acknowledgement) ? $acknowledgement : null,
            $companyId,
            $accountingPeriodId,
            $dataEntry
        );
    }

    private function yearEndAcknowledgementHtml(
        ?array $acknowledgement,
        int $companyId,
        int $accountingPeriodId,
        array $dataEntry
    ): string
    {
        return \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'fixed asset candidate position',
            'companyId' => $companyId,
            'accountingPeriodId' => $accountingPeriodId,
            'locked' => !empty($dataEntry['is_locked']),
            'disabled' => empty($dataEntry['permitted']),
            'disabledReason' => (string)($dataEntry['reason'] ?? ''),
            'acknowledged' => !empty($acknowledgement['current']),
            'acknowledgementState' => (string)($acknowledgement['state'] ?? 'absent'),
            'acknowledgedAt' => (string)($acknowledgement['acknowledged_at'] ?? ''),
            'acknowledgedBy' => (string)($acknowledgement['acknowledged_by'] ?? ''),
            'note' => (string)($acknowledgement['note'] ?? ''),
            'intent' => 'acknowledge_review_check',
            'revokeIntent' => 'reopen_review_check',
            'approveFields' => ['check_code' => 'fixed_asset_review_placeholder'],
            'revokeFields' => ['check_code' => 'fixed_asset_review_placeholder'],
            'noteName' => 'review_acknowledgement_note',
            'noteId' => 'fixed-asset-review-note',
        ]);
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
        $dataEntry = (array)(($context['services']['nonAssetReview'] ?? [])['data_entry'] ?? []);
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
                export: static fn(array $row): string => number_format((float)($row['amount'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'action',
                'Action',
                html: fn(array $row): string => $this->actionButtons(
                    $row,
                    $companyId,
                    $accountingPeriodId,
                    (int)($settings['default_bank_nominal_id'] ?? 0),
                    $dataEntry
                ),
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function rows(array $context): array
    {
        $data = (array)(((array)(($context['services'] ?? [])['nonAssetReview'] ?? []))['candidates'] ?? []);

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
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="card_action" value="Asset">
            <input type="hidden" name="intent" value="save_potential_asset_threshold">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <label for="potential_asset_threshold">Threshold</label>
            <select class="select" id="potential_asset_threshold" name="potential_asset_threshold">' . $options . '</select>
        </form>';
    }

    private function thresholdToolbar(int $companyId, int $accountingPeriodId, int $threshold, array $settings): string
    {
        return '<div class="card-toolbar">
            <div class="actions-row">'
                . $this->thresholdForm($companyId, $accountingPeriodId, $threshold, $settings)
            . '</div>
        </div>';
    }

    private function readOnlyHelper(array $dataEntry): string
    {
        $reason = trim((string)($dataEntry['reason'] ?? ''));
        if (!empty($dataEntry['is_locked'])) {
            return '<div class="helper"><span class="badge warning">Period locked</span> Non-asset thresholds and conversions are read only.</div>';
        }

        return '<div class="helper">' . HelperFramework::escape(
            $reason !== '' ? $reason : 'Data entry is not permitted for this accounting period.'
        ) . '</div>';
    }

    private function renderTableWithThresholdToolbar(TableFramework $table, array $context, string $thresholdForm): string
    {
        $exportHiddenFields = [
            'cards[]' => (array)($context['page']['page_cards'] ?? []),
        ];
        $toolbar = $table->renderToolbar($context, $exportHiddenFields);
        $toolbar = preg_replace(
            '/<div class="actions-row">\s*<\/div>/',
            '<div class="actions-row">' . $thresholdForm . '</div>',
            $toolbar,
            1
        ) ?? $toolbar;

        return '<div class="panel-soft">'
            . $toolbar
            . $table->renderTable()
            . $table->renderFooter()
            . '</div>';
    }

    private function actionButtons(
        array $row,
        int $companyId,
        int $accountingPeriodId,
        int $defaultBankNominalId,
        array $dataEntry
    ): string
    {
        $buttons = array_filter([
            $this->openSourceButton($row, $companyId, $accountingPeriodId),
            $this->convertToAssetForm($row, $companyId, $accountingPeriodId, $defaultBankNominalId, $dataEntry),
        ], static fn(string $html): bool => $html !== '');

        return $buttons === [] ? '' : '<div class="actions-stack">' . implode('', $buttons) . '</div>';
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

            return \eel_accounts\Renderer\WorkflowHandoffRenderer::button(
                'transactions',
                'Open Related Workflow',
                [
                    'show_card' => 'transactions_imported',
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'month_key' => $monthKey,
                    'category_filter' => 'all',
                ],
                'button button-inline'
            );
        }

        if ($sourceType === 'expense_claim') {
            $claimId = (int)($row['source_claim_id'] ?? 0);
            if ($claimId <= 0) {
                return '';
            }

            return \eel_accounts\Renderer\WorkflowHandoffRenderer::button(
                'expense_claims',
                'Open Related Workflow',
                [
                    'show_card' => 'expense_claim_editor',
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'claim_id' => $claimId,
                ],
                'button button-inline'
            );
        }

        return '';
    }

    private function convertToAssetForm(
        array $row,
        int $companyId,
        int $accountingPeriodId,
        int $defaultBankNominalId,
        array $dataEntry
    ): string
    {
        $sourceType = (string)($row['source_type'] ?? '');
        $sourceId = (int)($row['source_id'] ?? 0);
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $sourceId <= 0 || !in_array($sourceType, ['transaction', 'expense_claim'], true)) {
            return '';
        }
        if (empty($dataEntry['permitted'])) {
            return '<span class="helper">' . HelperFramework::escape(
                !empty($dataEntry['is_locked']) ? 'Period locked' : (string)($dataEntry['reason'] ?? 'Data entry is not permitted.')
            ) . '</span>';
        }

        $formId = 'non-asset-convert-' . preg_replace('/[^a-z0-9_-]+/', '-', strtolower($sourceType)) . '-' . $sourceId;
        $message = $sourceType === 'transaction'
            ? 'This will recategorise the transaction and rebuild its journal. Continue?'
            : 'This will rebuild the posted expense claim journal. The claim will remain posted. Continue?';

        return '<form method="post" action="?page=assets" data-ajax="true" class="non-asset-convert-form">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="card_action" value="Asset">
            <input type="hidden" name="intent" value="convert_non_asset_to_asset">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <input type="hidden" name="default_bank_nominal_id" value="' . $defaultBankNominalId . '">
            <input type="hidden" name="source_type" value="' . HelperFramework::escape($sourceType) . '">
            <input type="hidden" name="source_id" value="' . $sourceId . '">
            <input type="hidden" name="description" value="' . HelperFramework::escape((string)($row['description'] ?? '')) . '">
            <input type="hidden" name="purchase_date" value="' . HelperFramework::escape((string)($row['date'] ?? '')) . '">
            <input type="hidden" name="cost" value="' . HelperFramework::escape(number_format((float)($row['amount'] ?? 0), 2, '.', '')) . '">
            <div class="form-flex-flow">
                <div class="form-row">
                    <label for="' . HelperFramework::escape($formId) . '-category">Asset category</label>
                    <select class="select" id="' . HelperFramework::escape($formId) . '-category" name="asset_category">' . $this->assetCategoryOptions('tools_equipment') . '</select>
                </div>
                <div class="form-row">
                    <label for="' . HelperFramework::escape($formId) . '-life">Useful life</label>
                    <select class="select" id="' . HelperFramework::escape($formId) . '-life" name="asset_useful_life_years">' . $this->assetUsefulLifeOptions(3) . '</select>
                </div>
                <div class="form-row">
                    <label for="' . HelperFramework::escape($formId) . '-method" title="None: no depreciation is posted. Straight Line: spreads cost less EOL Value evenly over the useful life. Reducing Balance: depreciates by the same rate each period, using the asset&apos;s remaining value after previous depreciation.">Depreciation</label>
                    <select class="select" id="' . HelperFramework::escape($formId) . '-method" name="asset_depreciation_method">' . $this->depreciationMethodOptions('straight_line') . '</select>
                </div>
                <div class="form-row">
                    <label for="' . HelperFramework::escape($formId) . '-residual" title="End of Life Value, also known as the Residual Value, is the value the item has after the useful life period has expired.">EOL Value</label>
                    <input class="input" id="' . HelperFramework::escape($formId) . '-residual" name="asset_residual_value" inputmode="decimal" value="0.00">
                </div>
                <button class="button primary" type="submit" data-chicken-check="true" data-chicken-title="Convert to Asset" data-chicken-message="' . HelperFramework::escape($message) . '" data-chicken-confirm-text="Convert to Asset" data-chicken-button-class="button primary">Convert to Asset</button>
            </div>
        </form>';
    }

    private function assetCategoryOptions(string $selectedCategory): string
    {
        $html = '';
        foreach (\eel_accounts\Service\AssetService::assetCategoryOptions() as $value => $label) {
            $selected = $value === $selectedCategory ? ' selected' : '';
            $html .= '<option value="' . HelperFramework::escape((string)$value) . '"' . $selected . '>' . HelperFramework::escape((string)$label) . '</option>';
        }

        return $html;
    }

    private function assetUsefulLifeOptions(int $selectedYears): string
    {
        $html = '';
        foreach ([1, 2, 3, 5, 10] as $years) {
            $selected = $years === $selectedYears ? ' selected' : '';
            $html .= '<option value="' . $years . '"' . $selected . '>' . $years . ' ' . ($years === 1 ? 'Year' : 'Years') . '</option>';
        }

        return $html;
    }

    private function depreciationMethodOptions(string $selectedMethod): string
    {
        $options = [
            'straight_line' => 'Straight line',
            'reducing_balance' => 'Reducing balance',
            'none' => 'None',
        ];
        $html = '';
        foreach ($options as $value => $label) {
            $selected = $value === $selectedMethod ? ' selected' : '';
            $html .= '<option value="' . HelperFramework::escape($value) . '"' . $selected . '>' . HelperFramework::escape($label) . '</option>';
        }

        return $html;
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
