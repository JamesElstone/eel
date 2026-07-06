<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dividend_historyCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'dividend_history';
    }

    public function title(): string
    {
        return 'Dividend History';
    }

    public function services(): array
    {
        return [$this->dividendContextService()];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function render(array $context): string
    {
        $rows = (array)($this->dividendsContext($context)['history'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companySettings = (array)($company['settings'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        if ($rows === []) {
            return '<div class="helper">No dividend journals exist yet for the selected company and accounting period.</div>';
        }

        $rowsHtml = '';
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? 'posted');
            $paymentLinkStatus = (string)($row['payment_link_status'] ?? '');
            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['journal_date'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['description'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $row['amount'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape((string)($row['settlement_account'] ?? '')) . '</td>
                <td><div class="helper">' . HelperFramework::escape((string)($row['source_ref'] ?? '')) . '</div></td>
                <td><span class="badge ' . HelperFramework::escape($this->statusBadgeClass($status)) . '">' . HelperFramework::escape(HelperFramework::labelFromKey($status, '_')) . '</span></td>
                <td>
                    <span class="badge ' . HelperFramework::escape($this->paymentLinkBadgeClass($paymentLinkStatus)) . '">' . HelperFramework::escape((string)($row['payment_link_label'] ?? 'Manual / draft')) . '</span>
                    <div class="helper">' . HelperFramework::escape((string)($row['payment_link_detail'] ?? '')) . '</div>
                </td>
                <td>' . $this->actionsHtml($row, $companyId, $accountingPeriodId) . '</td>
            </tr>';
        }

        return '<div class="table-scroll">
            <table>
                <thead><tr><th>Date</th><th>Description</th><th>Amount</th><th>Settlement account</th><th>Source reference</th><th>Status</th><th>Payment Link</th><th>Actions</th></tr></thead>
                <tbody>' . $rowsHtml . '</tbody>
            </table>
        </div>';
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function actionsHtml(array $row, int $companyId, int $accountingPeriodId): string
    {
        if (!empty($row['can_void'])) {
            $journalId = (int)($row['id'] ?? 0);

            return '<form method="post" action="?page=dividends" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Dividend">
                <input type="hidden" name="intent" value="void_dividend">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="journal_id" value="' . $journalId . '">
                <button class="button danger" type="submit"
                    data-chicken-check="true"
                    data-chicken-title="Void dividend"
                    data-chicken-message="This will create a reversing journal, mark the dividend voucher as voided, and add a separate company minutes record for the void. The original voucher and declaration minutes will remain unchanged.<br><br>Continue?"
                    data-chicken-confirm-text="Void Dividend"
                    data-chicken-button-class="button danger">Void</button>
            </form>';
        }

        if ((string)($row['payment_link_status'] ?? '') === 'recategorised' && trim((string)($row['transaction_notes'] ?? '')) === '') {
            return '<span class="helper">Add a source transaction note first.</span>';
        }

        return '<span class="helper">-</span>';
    }

    private function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'posted' => 'success',
            'voided' => 'danger',
            default => 'warning',
        };
    }

    private function paymentLinkBadgeClass(string $status): string
    {
        return match ($status) {
            'linked' => 'success',
            'recategorised' => 'warning',
            'missing' => 'danger',
            'voided' => 'muted',
            default => 'info',
        };
    }

    private function dividendContextService(): array
    {
        return [
            'key' => 'dividendContext',
            'service' => \eel_accounts\Service\DividendViewDataService::class,
            'method' => 'fetchContext',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ];
    }

    private function dividendsContext(array $context): array
    {
        $serviceContext = $context['services']['dividendContext'] ?? null;
        if (is_array($serviceContext)) {
            return $serviceContext;
        }

        return (array)($context['dividends'] ?? []);
    }
}
