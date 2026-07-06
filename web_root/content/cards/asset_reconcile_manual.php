<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _asset_reconcile_manualCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'asset_reconcile_manual';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'manualAssetReconciliation',
                'service' => \eel_accounts\Service\AssetService::class,
                'method' => 'fetchManualAssetReconciliationData',
                'params' => [
                    'companyId' => ':company.id',
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
        return 'Manual asset reconciliation data could not be loaded: ' . (string)($error['message'] ?? 'service error');
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $settings = (array)($company['settings'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $defaultBankNominalId = (int)($settings['default_bank_nominal_id'] ?? 0);
        $data = (array)($context['services']['manualAssetReconciliation'] ?? []);
        $assets = is_array($data['assets'] ?? null) ? $data['assets'] : [];

        if ($assets === []) {
            return '<div class="asset-reconcile-manual">
                <h3>Reconcile Manually Created Assets</h3>
                <div class="helper">No manually created assets need reconciliation.</div>
            </div>';
        }

        $rowsHtml = '';
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($asset['asset_code'] ?? '')) . '</td>
                <td>
                    <div>' . HelperFramework::escape((string)($asset['description'] ?? '')) . '</div>
                    <div class="helper">' . HelperFramework::escape((string)($asset['manual_addition_reason_label'] ?? '')) . '</div>
                </td>
                <td>' . HelperFramework::escape($this->displayDate((string)($asset['purchase_date'] ?? ''))) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $asset['cost'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape((string)($asset['manual_offset_nominal_label'] ?? '')) . '</td>
                <td>' . $this->candidateRows($companyId, $accountingPeriodId, (int)($asset['id'] ?? 0), $defaultBankNominalId, (array)($asset['candidates'] ?? []), $settings) . '</td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="6">No manually created assets need reconciliation.</td></tr>';
        }

        return '<div class="asset-reconcile-manual">
            <h3>Reconcile Manually Created Assets</h3>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Description / Reason</th>
                            <th>Purchase Date</th>
                            <th>Cost</th>
                            <th>Funding / clearing nominal</th>
                            <th>Candidate transactions</th>
                        </tr>
                    </thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>
        </div>';
    }

    private function candidateRows(
        int $companyId,
        int $accountingPeriodId,
        int $assetId,
        int $defaultBankNominalId,
        array $candidates,
        array $settings
    ): string {
        $candidateRows = '';
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $candidateRows .= '<div class="asset-reconcile-candidate">
                <div>
                    <div>' . HelperFramework::escape($this->displayDate((string)($candidate['txn_date'] ?? '')) . ' - ' . $this->money($settings, $candidate['amount'] ?? 0)) . '</div>
                    <div class="helper">' . HelperFramework::escape((string)($candidate['description'] ?? '')) . '</div>
                    <div class="helper">' . HelperFramework::escape((string)($candidate['nominal_label'] ?? 'Unassigned')) . '</div>
                </div>
                <form method="post" action="?page=assets" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                    <input type="hidden" name="card_action" value="Asset">
                    <input type="hidden" name="intent" value="reconcile_manual_asset_with_transaction">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <input type="hidden" name="asset_id" value="' . $assetId . '">
                    <input type="hidden" name="transaction_id" value="' . (int)($candidate['id'] ?? 0) . '">
                    <input type="hidden" name="default_bank_nominal_id" value="' . $defaultBankNominalId . '">
                    <input type="hidden" name="confirm_rebuild_journal" value="0">
                    <button class="button button-inline primary" type="submit"' . $this->journalRebuildAttributes($candidate) . '>Link &amp; Reconcile</button>
                </form>
            </div>';
        }

        return $candidateRows !== ''
            ? '<div class="asset-reconcile-candidates">' . $candidateRows . '</div>'
            : '<span class="helper">No matching imported transactions found.</span>';
    }

    private function journalRebuildAttributes(array $candidate): string
    {
        return (int)($candidate['has_derived_journal'] ?? 0) === 1
            ? ' data-chicken-check="true" data-chicken-title="Confirm journal rebuild" data-chicken-message="This will rebuild the journal entry for this transaction.<br><br>Continue?" data-chicken-confirm-text="Continue" data-chicken-button-class="button primary" data-submit-field="confirm_rebuild_journal" data-submit-value="1"'
            : '';
    }

    private function displayDate(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return HelperFramework::displayDate($value);
    }

    private function money(array $settings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($settings, $value);
    }
}
