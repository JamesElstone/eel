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
    private const PAGE_SIZE = 10;

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
                'method' => 'fetchRegisterData',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'defaultBankNominalId' => ':company.settings.default_bank_nominal_id',
                    'disposalSearchDate' => ':asset_disposal_search_date',
                    'disposalSearchAssetId' => ':asset_disposal_search_asset_id',
                ],
            ],
            [
                'key' => 'periodLockState',
                'service' => \eel_accounts\Service\YearEndLockService::class,
                'method' => 'isLocked',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);
        $resultContext = $actionResult->context();
        $searchDate = trim((string)(
            $resultContext['asset_disposal_search_date']
            ?? $request->input('asset_disposal_search_date', $request->input('disposal_search_date', ''))
        ));
        $assetId = max(0, (int)(
            $resultContext['asset_disposal_search_asset_id']
            ?? $request->input('asset_disposal_search_asset_id', $request->input('asset_id', 0))
        ));

        if ($searchDate !== '') {
            $pageContext['asset_disposal_search_date'] = $searchDate;
            $pageContext['asset_disposal_search_asset_id'] = $assetId;
        }

        $methodAssetId = max(0, (int)(
            $resultContext['asset_disposal_method_asset_id']
            ?? $request->input('asset_disposal_method_asset_id', $request->input('asset_id', 0))
        ));
        $method = $this->normaliseDisposalMethod((string)(
            $resultContext['asset_disposal_method']
            ?? $request->input('asset_disposal_method', '')
        ));
        if ($methodAssetId > 0 && $method !== '') {
            $pageContext['asset_disposal_method_asset_id'] = $methodAssetId;
            $pageContext['asset_disposal_method'] = $method;
        }

        return $pageContext;
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        if ($serviceKey === 'periodLockState') {
            return 'Period lock state could not be loaded: ' . (string)($error['message'] ?? 'service error');
        }

        return 'Asset data could not be loaded: ' . (string)($error['message'] ?? 'service error');
    }

    public function render(array $context): string
    {
        return $this->capitalAllowanceEligibilityHelperHtml()
            . $this->configuredTable($context)->render(
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
        $company = (array)($context['company'] ?? []);
        $table = $this->table($context);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Assets',
                $this->paginationPageField(),
                [
                    'page' => (string)($context['page']['page_id'] ?? 'assets'),
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => [$this->key()],
                    'company_id' => (int)($company['id'] ?? 0),
                    'accounting_period_id' => (int)($company['accounting_period_id'] ?? 0),
                ]
            );
    }

    private function table(array $context): TableFramework
    {
        $company = (array)($context['company'] ?? []);
        $settings = (array)($company['settings'] ?? []);
        $assetsPageData = (array)($context['services']['assetPageData'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $defaultBankNominalId = (int)($assetsPageData['default_bank_nominal_id'] ?? 0);
        $disposalSearch = (array)($assetsPageData['disposal_search'] ?? []);
        $disposalMethodAssetId = (int)($context['asset_disposal_method_asset_id'] ?? 0);
        $disposalMethod = $this->normaliseDisposalMethod((string)($context['asset_disposal_method'] ?? ''));
        $ageReferenceDate = $this->ageReferenceDate((string)($context['accounting_period']['period_end'] ?? ''));
        $isLocked = (bool)($context['services']['periodLockState'] ?? false);
        if (($context['service_errors']['periodLockState'] ?? null) !== null) {
            $isLocked = true;
        }

        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('asset-register')
            ->exportLimit(5000)
            ->classes(wrapperClass: 'table-scroll asset-register-table')
            ->empty('No assets have been recorded yet.')
            ->column(
                'purchase_date',
                'Purchase Date',
                html: fn(array $row): string => HelperFramework::escape($this->displayDate((string)($row['purchase_date'] ?? ''))),
                export: static fn(array $row): string => trim((string)($row['purchase_date'] ?? '')),
                exportType: 'date'
            )
            ->column(
                'age_days',
                'Age (days)',
                html: fn(array $row): string => HelperFramework::escape($this->ageDays($row, $ageReferenceDate)),
                export: fn(array $row): string => $this->ageDays($row, $ageReferenceDate),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'useful_life_years',
                'Useful Life',
                html: static fn(array $row): string => HelperFramework::escape((string)max(1, (int)($row['useful_life_years'] ?? 1))),
                export: static fn(array $row): string => (string)max(1, (int)($row['useful_life_years'] ?? 1)),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->textColumn('asset_code', 'Code')
            ->column(
                'description',
                'Description',
                html: fn(array $row): string => $this->descriptionHtml($row),
                export: static fn(array $row): string => trim((string)($row['description'] ?? ''))
            )
            ->column(
                'cost',
                'Cost',
                html: fn(array $row): string => HelperFramework::escape($this->money($settings, (float)($row['cost'] ?? 0))),
                export: static fn(array $row): string => number_format((float)($row['cost'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'period_depreciation',
                'Depreciation in Period',
                html: fn(array $row): string => HelperFramework::escape($this->money($settings, (float)($row['period_depreciation'] ?? 0))),
                export: static fn(array $row): string => number_format((float)($row['period_depreciation'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'resale_value',
                'Resale Value',
                html: fn(array $row): string => HelperFramework::escape($this->money($settings, (float)($row['resale_value'] ?? 0))),
                export: static fn(array $row): string => number_format((float)($row['resale_value'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'residual_value',
                'EOL Value',
                html: fn(array $row): string => HelperFramework::escape($this->money($settings, (float)($row['residual_value'] ?? 0))),
                export: static fn(array $row): string => number_format((float)($row['residual_value'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'status',
                'Status',
                html: static function (array $row): string {
                    $status = (string)($row['status'] ?? 'active');

                    return '<span class="badge ' . HelperFramework::escape($status === 'disposed' ? 'warning' : 'success') . '">'
                        . HelperFramework::escape($status)
                        . '</span>';
                }
            )
            ->column(
                'disposal_method',
                'Disposal Method',
                html: fn(array $row): string => $this->disposalMethodToggleHtml(
                    $row,
                    $companyId,
                    $accountingPeriodId,
                    $disposalMethodAssetId,
                    $disposalMethod,
                    $isLocked
                ),
                headerClass: 'asset-register-actions-heading',
                cellClass: 'asset-register-actions-cell',
                exportable: false
            )
            ->column(
                'actions',
                'Asset Disposal',
                html: fn(array $row): string => $this->actionsHtml(
                    $row,
                    $companyId,
                    $accountingPeriodId,
                    $defaultBankNominalId,
                    $disposalSearch,
                    $settings,
                    $disposalMethodAssetId,
                    $disposalMethod,
                    $isLocked
                ),
                headerClass: 'asset-register-actions-heading',
                cellClass: 'asset-register-actions-cell',
                exportable: false
            );
    }

    private function rows(array $context): array
    {
        $assetsPageData = (array)($context['services']['assetPageData'] ?? []);

        return array_values(array_filter(
            (array)($assetsPageData['assets'] ?? []),
            static fn(mixed $asset): bool => is_array($asset)
        ));
    }

    private function capitalAllowanceEligibilityHelperHtml(): string
    {
        return '<div class="helper">AIA eligibility check: owned by the business, used for the business, bought in the period claimed, and not a car, gift, leased item, land, building, or structure.</div>';
    }

    private function descriptionHtml(array $asset): string
    {
        $nominal = trim((string)($asset['nominal_code'] ?? '') . ' ' . (string)($asset['nominal_name'] ?? ''));

        return '<div>' . HelperFramework::escape((string)($asset['description'] ?? '')) . '</div>'
            . ($nominal !== '' ? '<div class="helper">' . HelperFramework::escape($nominal) . '</div>' : '');
    }

    private function actionsHtml(
        array $asset,
        int $companyId,
        int $accountingPeriodId,
        int $defaultBankNominalId,
        array $disposalSearch,
        array $settings,
        int $disposalMethodAssetId,
        string $disposalMethod,
        bool $isLocked
    ): string {
        $status = (string)($asset['status'] ?? 'active');

        if ($status === 'disposed') {
            $reason = trim((string)($asset['disposal_reason'] ?? ''));
            return '<span class="helper">Disposed on ' . HelperFramework::escape($this->displayDate((string)($asset['disposal_date'] ?? ''))) . '</span>'
                . ($reason !== '' ? '<div class="helper">Reason: ' . HelperFramework::escape($reason) . '</div>' : '');
        }

        return $this->disposalControls(
            $companyId,
            $accountingPeriodId,
            (int)($asset['id'] ?? 0),
            $defaultBankNominalId,
            $disposalSearch,
            $settings,
            $this->disposalMethodForAsset((int)($asset['id'] ?? 0), $disposalMethodAssetId, $disposalMethod),
            $isLocked
        );
    }

    private function disposalMethodToggleHtml(
        array $asset,
        int $companyId,
        int $accountingPeriodId,
        int $disposalMethodAssetId,
        string $disposalMethod,
        bool $isLocked
    ): string {
        if ((string)($asset['status'] ?? 'active') === 'disposed') {
            return '';
        }
        if ($isLocked) {
            return '<span class="helper">Period locked</span>';
        }

        $assetId = (int)($asset['id'] ?? 0);
        $currentMethod = $this->disposalMethodForAsset($assetId, $disposalMethodAssetId, $disposalMethod);
        $nextMethod = $currentMethod === 'at_nil_value' ? 'sell_asset' : 'at_nil_value';
        $currentLabel = $this->disposalMethodLabel($currentMethod);
        $nextLabel = $this->disposalMethodLabel($nextMethod);

        return '<form class="asset-disposal-method-form" method="post" action="?page=assets" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="card_action" value="Asset">
            <input type="hidden" name="intent" value="set_asset_disposal_method">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <input type="hidden" name="cards[]" value="' . HelperFramework::escape($this->key()) . '">
            <input type="hidden" name="asset_disposal_method_asset_id" value="' . $assetId . '">
            <input type="hidden" name="asset_disposal_method" value="' . HelperFramework::escape($nextMethod) . '">
            <button class="button button-inline" type="submit" title="Switch to ' . HelperFramework::escape($nextLabel) . '">' . HelperFramework::escape($currentLabel) . '</button>
        </form>';
    }

    private function disposalControls(
        int $companyId,
        int $accountingPeriodId,
        int $assetId,
        int $defaultBankNominalId,
        array $disposalSearch,
        array $settings,
        string $disposalMethod,
        bool $isLocked
    ): string {
        if ($isLocked) {
            return '<div class="helper"><span class="badge warning">Period locked</span> Asset disposals are read only.</div>';
        }

        $selectedAssetId = (int)($disposalSearch['asset_id'] ?? 0);
        $searchDate = $selectedAssetId === $assetId
            ? (string)($disposalSearch['search_date'] ?? date('Y-m-d'))
            : date('Y-m-d');
        $candidateHtml = $selectedAssetId === $assetId
            ? $this->disposalCandidates($companyId, $accountingPeriodId, $assetId, $defaultBankNominalId, $disposalSearch, $settings)
            : '';
        $nilReasonOptions = $this->nilReasonOptionsHtml();

        if ($disposalMethod === 'at_nil_value') {
            return '<div class="asset-disposal-panel">
                <form class="asset-disposal-form" method="post" action="?page=assets" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                    <input type="hidden" name="card_action" value="Asset">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <input type="hidden" name="asset_id" value="' . $assetId . '">
                    <input type="hidden" name="asset_disposal_method_asset_id" value="' . $assetId . '">
                    <input type="hidden" name="asset_disposal_method" value="at_nil_value">
                    <div class="asset-disposal-controls">
                        <div class="asset-disposal-row">
                            <input class="input" type="date" name="disposal_date" value="' . HelperFramework::escape($searchDate) . '">
                            <select class="select" name="disposal_event_type" aria-label="Nil value disposal reason" data-no-submit-on-change="true">' . $nilReasonOptions . '</select>
                            <input class="input" type="text" name="disposal_reason" placeholder="Nil value note" maxlength="20" size="20">
                            <button class="button button-inline primary" type="submit" name="intent" value="dispose_asset_nil">Dispose of at Nil Value</button>
                        </div>
                    </div>
                </form>
            </div>';
        }

        return '<div class="asset-disposal-panel">
            <form class="asset-disposal-form" method="post" action="?page=assets" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Asset">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="asset_id" value="' . $assetId . '">
                <input type="hidden" name="asset_disposal_method_asset_id" value="' . $assetId . '">
                <input type="hidden" name="asset_disposal_method" value="sell_asset">
                <div class="asset-disposal-controls">
                    <div class="asset-disposal-row">
                        <input class="input" type="date" name="disposal_search_date" value="' . HelperFramework::escape($searchDate) . '">
                        <button class="button button-inline primary" type="submit" name="intent" value="search_asset_disposal_receipts">Search Incoming Payments</button>
                    </div>
                </div>
            </form>
            ' . $candidateHtml . '
        </div>';
    }

    private function disposalCandidates(
        int $companyId,
        int $accountingPeriodId,
        int $assetId,
        int $defaultBankNominalId,
        array $disposalSearch,
        array $settings
    ): string {
        $errors = (array)($disposalSearch['errors'] ?? []);
        if ($errors !== []) {
            return '<div class="helper asset-disposal-error">' . HelperFramework::escape(implode(' ', array_map('strval', $errors))) . '</div>';
        }

        $windowStart = $this->displayDate((string)($disposalSearch['window_start'] ?? ''));
        $windowEnd = $this->displayDate((string)($disposalSearch['window_end'] ?? ''));
        $candidates = (array)($disposalSearch['candidates'] ?? []);
        $candidateRows = '';

        foreach ($candidates as $candidate) {
            $candidateRows .= '<div class="asset-disposal-candidate">
                <div>
                    <div>' . HelperFramework::escape($this->displayDate((string)($candidate['txn_date'] ?? '')) . ' - ' . $this->money($settings, (float)($candidate['amount'] ?? 0))) . '</div>
                    <div class="helper">' . HelperFramework::escape((string)($candidate['description'] ?? '')) . '</div>
                </div>
                <form method="post" action="?page=assets" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                    <input type="hidden" name="card_action" value="Asset">
                    <input type="hidden" name="intent" value="dispose_asset_with_transaction">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <input type="hidden" name="asset_id" value="' . $assetId . '">
                    <input type="hidden" name="transaction_id" value="' . (int)($candidate['id'] ?? 0) . '">
                    <input type="hidden" name="default_bank_nominal_id" value="' . $defaultBankNominalId . '">
                    <button class="button button-inline primary" type="submit">Link &amp; Dispose</button>
                </form>
            </div>';
        }

        if ($candidateRows === '') {
            $candidateRows = '<div class="helper">No incoming receipt transactions found.</div>';
        }

        return '<div class="asset-disposal-candidates">
            <div class="helper">Receipts from ' . HelperFramework::escape($windowStart) . ' to ' . HelperFramework::escape($windowEnd) . '</div>
            ' . $candidateRows . '
        </div>';
    }

    private function nilReasonOptionsHtml(): string
    {
        $html = '';

        foreach (\eel_accounts\Service\AssetService::nilDisposalEventOptions() as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape((string)$value) . '">'
                . HelperFramework::escape((string)$label)
                . '</option>';
        }

        return $html;
    }

    private function money(array $settings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($settings, $value);
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
    }

    private function displayDate(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return HelperFramework::displayDate($value);
    }

    private function ageDays(array $asset, ?\DateTimeImmutable $referenceDate): string
    {
        if ($referenceDate === null) {
            return '';
        }

        $purchaseDate = $this->isoDate((string)($asset['purchase_date'] ?? ''));
        if ($purchaseDate === null) {
            return '';
        }

        $usefulLifeEndDate = $this->usefulLifeEndDate($purchaseDate, (int)($asset['useful_life_years'] ?? 1));
        if ($usefulLifeEndDate !== null && $usefulLifeEndDate < $referenceDate) {
            $referenceDate = $usefulLifeEndDate;
        }
        if ($purchaseDate > $referenceDate) {
            return '0';
        }

        $days = $purchaseDate->diff($referenceDate)->days;

        return is_int($days) ? (string)($days + 1) : '';
    }

    private function ageReferenceDate(string $periodEnd): ?\DateTimeImmutable
    {
        $periodEndDate = $this->isoDate($periodEnd);
        if ($periodEndDate === null) {
            return null;
        }

        $today = new \DateTimeImmutable('today');

        return $periodEndDate < $today ? $periodEndDate : $today;
    }

    private function isoDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if (
            !$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
            || (is_array($errors) && ((int)($errors['warning_count'] ?? 0) > 0 || (int)($errors['error_count'] ?? 0) > 0))
        ) {
            return null;
        }

        return $date;
    }

    private function usefulLifeEndDate(\DateTimeImmutable $purchaseDate, int $lifeYears): ?\DateTimeImmutable
    {
        $lifeYears = max(1, $lifeYears);

        return $purchaseDate
            ->modify('+' . $lifeYears . ' years')
            ->modify('-1 day');
    }

    private function disposalMethodForAsset(int $assetId, int $selectedAssetId, string $method): string
    {
        if ($assetId > 0 && $selectedAssetId === $assetId && $method !== '') {
            return $method;
        }

        return 'sell_asset';
    }

    private function normaliseDisposalMethod(string $method): string
    {
        return in_array($method, ['sell_asset', 'at_nil_value'], true) ? $method : '';
    }

    private function disposalMethodLabel(string $method): string
    {
        return $method === 'at_nil_value' ? 'No Value' : 'Sold Asset';
    }

}
