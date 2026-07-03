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
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'defaultBankNominalId' => ':company.settings.default_bank_nominal_id',
                    'prefillTransactionId' => ':prefill_transaction_id',
                    'disposalSearchDate' => ':asset_disposal_search_date',
                    'disposalSearchAssetId' => ':asset_disposal_search_asset_id',
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

        return $pageContext;
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
        $assets = is_array($assetsPageData['assets'] ?? null) ? $assetsPageData['assets'] : [];
        $defaultBankNominalId = (int)($assetsPageData['default_bank_nominal_id'] ?? 0);
        $disposalSearch = (array)($assetsPageData['disposal_search'] ?? []);
        $rowsHtml = '';

        foreach ($assets as $asset) {
            $status = (string)($asset['status'] ?? 'active');
            $assetId = (int)($asset['id'] ?? 0);
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
                    ? $this->disposalControls($companyId, $accountingPeriodId, $assetId, $defaultBankNominalId, $disposalSearch)
                    : '<span class="helper">Disposed on ' . HelperFramework::escape($this->displayDate((string)($asset['disposal_date'] ?? ''))) . '</span>') . '</td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="6">No assets have been recorded yet.</td></tr>';
        }

        return '
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

    private function disposalControls(
        int $companyId,
        int $accountingPeriodId,
        int $assetId,
        int $defaultBankNominalId,
        array $disposalSearch
    ): string {
        $selectedAssetId = (int)($disposalSearch['asset_id'] ?? 0);
        $searchDate = $selectedAssetId === $assetId
            ? (string)($disposalSearch['search_date'] ?? date('Y-m-d'))
            : date('Y-m-d');
        $candidateHtml = $selectedAssetId === $assetId
            ? $this->disposalCandidates($companyId, $accountingPeriodId, $assetId, $defaultBankNominalId, $disposalSearch)
            : '';

        return '<div class="asset-disposal-panel">
            <form class="asset-disposal-form" method="post" action="?page=assets" data-ajax="true">
                <input type="hidden" name="card_action" value="Asset">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="asset_id" value="' . $assetId . '">
                <div class="asset-disposal-controls">
                    <input class="input" type="date" name="disposal_search_date" value="' . HelperFramework::escape($searchDate) . '">
                    <button class="button button-inline primary" type="submit" name="intent" value="search_asset_disposal_receipts">Search Incomming Payments</button>
                    <button class="button button-inline primary" type="submit" name="intent" value="dispose_asset_nil">Dispose of at Nil Value</button>
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
        array $disposalSearch
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
                    <div>' . HelperFramework::escape($this->displayDate((string)($candidate['txn_date'] ?? '')) . ' - ' . FormattingFramework::money((float)($candidate['amount'] ?? 0))) . '</div>
                    <div class="helper">' . HelperFramework::escape((string)($candidate['description'] ?? '')) . '</div>
                </div>
                <form method="post" action="?page=assets" data-ajax="true">
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

    private function displayDate(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return HelperFramework::displayDate($value);
    }

}
