<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dividend_reserve_reviewCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'dividend_reserve_review';
    }

    public function title(): string
    {
        return 'Dividend Reserve Review';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context', 'dividend.capacity', 'dividend.declare', 'dividend.warnings'];
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companySettings = (array)($company['settings'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $review = (array)($context['dividends']['reserve_review'] ?? []);

        if (empty($review['available'])) {
            return '<div class="settings-stack">' . $this->renderErrors((array)($review['errors'] ?? ['Dividend reserve review is not available.'])) . '</div>';
        }

        $summary = (array)($review['summary'] ?? []);
        $snapshot = (array)($review['snapshot'] ?? []);
        $status = (string)($review['status'] ?? 'missing');
        $statusClass = $status === 'current' ? 'success' : ($status === 'stale' ? 'warning' : 'danger');
        $statusLabel = (string)($review['status_label'] ?? 'Reserve review missing');
        $reviewedAt = trim((string)($snapshot['reviewed_at'] ?? ''));
        $asAtDate = (string)($review['as_at_date'] ?? '');

        $rowsHtml = '';
        foreach ((array)($review['rows'] ?? []) as $row) {
            $nominalId = (int)($row['nominal_account_id'] ?? 0);
            if ($nominalId <= 0) {
                continue;
            }

            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['nominal_code'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['nominal_name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $row['profit_effect'] ?? 0)) . '</td>
                <td>
                    <select class="select" name="treatment[' . $nominalId . ']">
                        ' . $this->treatmentOptions((array)($review['treatments'] ?? []), (string)($row['treatment'] ?? 'unknown')) . '
                    </select>
                </td>
            </tr>';
        }
        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="4">No posted profit and loss movements exist for this period.</td></tr>';
        }

        return '<div class="settings-stack">
            <div class="status-head">
                <span class="badge ' . HelperFramework::escape($statusClass) . '">' . HelperFramework::escape($statusLabel) . '</span>
                <span class="helper">As at ' . HelperFramework::escape($asAtDate !== '' ? $asAtDate : '-') . '</span>
                ' . ($reviewedAt !== '' ? '<span class="helper">Last reviewed ' . HelperFramework::escape(HelperFramework::displayDate($reviewedAt)) . '</span>' : '') . '
            </div>
            <div class="summary-grid four">
                ' . $this->summaryCard('Brought forward reserves', $this->money($companySettings, $summary['brought_forward_distributable_reserves'] ?? 0)) . '
                ' . $this->summaryCard('Distributable current profit', $this->money($companySettings, $summary['distributable_current_profit'] ?? 0)) . '
                ' . $this->summaryCard('Dividends declared', $this->money($companySettings, $summary['dividends_declared'] ?? 0)) . '
                ' . $this->summaryCard('Closing distributable reserves', $this->money($companySettings, $summary['closing_distributable_reserves'] ?? 0)) . '
            </div>
            <div class="summary-grid four">
                ' . $this->summaryCard('Ledger profit / loss', $this->money($companySettings, $summary['ledger_profit_loss'] ?? 0)) . '
                ' . $this->summaryCard('Reviewed realised profit', $this->money($companySettings, $summary['realised_profit_amount'] ?? 0)) . '
                ' . $this->summaryCard('Reviewed reductions', $this->money($companySettings, $this->reviewedReductions($summary))) . '
                ' . $this->summaryCard('Unknown', $this->money($companySettings, $summary['unknown_amount'] ?? 0)) . '
            </div>
            <form method="post" action="?page=dividends" data-ajax="true" class="settings-stack">
                <input type="hidden" name="card_action" value="Dividend">
                <input type="hidden" name="intent" value="save_dividend_reserve_review">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="as_at_date" value="' . HelperFramework::escape($asAtDate) . '">
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Code</th><th>Nominal</th><th>Profit effect</th><th>Reserve treatment</th></tr></thead>
                        <tbody>' . $rowsHtml . '</tbody>
                    </table>
                </div>
                <div class="helper">Unknown amounts and unreviewed snapshots are not treated as distributable for dividend declarations.</div>
                <div class="actions-row">
                    <button class="button primary" type="submit"
                        data-chicken-check="true"
                        data-chicken-title="Save dividend reserve review"
                        data-chicken-message="This records the current reserve classification snapshot for dividend capacity checks.<br><br>Continue?"
                        data-chicken-confirm-text="Save Review">Save Review</button>
                </div>
            </form>
        </div>';
    }

    private function treatmentOptions(array $treatments, string $selected): string
    {
        $html = '';
        foreach ($treatments as $treatment) {
            $value = (string)$treatment;
            $html .= '<option value="' . HelperFramework::escape($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . HelperFramework::escape(HelperFramework::labelFromKey($value, '_')) . '</option>';
        }

        return $html;
    }

    private function summaryCard(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function reviewedReductions(array $summary): float
    {
        return round(
            (float)($summary['realised_loss_amount'] ?? 0)
            + (float)($summary['unrealised_loss_amount'] ?? 0)
            + (float)($summary['tax_charge_amount'] ?? 0)
            + (float)($summary['dividend_distribution_amount'] ?? 0)
            + (float)($summary['unknown_amount'] ?? 0),
            2
        );
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function renderErrors(array $errors): string
    {
        $html = '';
        foreach ($errors as $error) {
            $html .= '<div class="helper">' . HelperFramework::escape((string)$error) . '</div>';
        }

        return $html;
    }
}
