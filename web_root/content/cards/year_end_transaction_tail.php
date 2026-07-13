<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_transaction_tailCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'year_end_transaction_tail';
    }

    public function title(): string
    {
        return 'Transaction Cut-off Review';
    }

    public function helper(array $context): string
    {
        return 'Review the last imported transaction on each company account before closing the accounting period.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'yearEndTransactionTail',
                'service' => \eel_accounts\Service\YearEndTransactionTailService::class,
                'method' => 'fetchContext',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['year.end.state', 'year.end.transaction.tail'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $tail = (array)($context['services']['yearEndTransactionTail'] ?? []);
        if (empty($tail['available'])) {
            return '<section class="settings-stack" id="year-end-transaction-tail">' . $this->renderErrors((array)($tail['errors'] ?? ['Transaction cut-off review is not available.'])) . '</section>';
        }

        $companySettings = (array)(((array)($context['company'] ?? []))['settings'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriod = (array)($tail['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));
        $acknowledgement = $tail['acknowledgement'] ?? $tail['previous_acknowledgement'] ?? null;
        $rowsHtml = '';
        foreach ((array)($tail['rows'] ?? []) as $row) {
            $amount = array_key_exists('last_transaction_amount', $row) ? $row['last_transaction_amount'] : null;
            $amountDisplay = $amount === null || trim((string)$amount) === '' ? '-' : $this->money($companySettings, $amount);
            $balance = array_key_exists('balance', $row) ? $row['balance'] : null;
            $balanceDisplay = $balance === null || trim((string)$balance) === '' ? '-' : $this->money($companySettings, $balance);
            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['account'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($row['account_type'] ?? ''), '_')) . '</td>
                <td>' . HelperFramework::escape($this->blankToDash($this->displayDate((string)($row['last_transaction_date'] ?? '')))) . '</td>
                <td>' . HelperFramework::escape($this->blankToDash((string)($row['last_transaction_desc'] ?? ''))) . '</td>
                <td class="numeric">' . HelperFramework::escape($amountDisplay) . '</td>
                <td class="numeric">' . HelperFramework::escape($balanceDisplay) . '</td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="6">No company accounts were found for this company.</td></tr>';
        }

        return '<section class="settings-stack" id="year-end-transaction-tail">
            <div class="table-scroll panel-soft">
                <table>
                    <thead><tr><th>Account</th><th>Type</th><th>Last transaction date</th><th>Last transaction desc</th><th>Last transaction amount</th><th>Balance</th></tr></thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>
            ' . $this->acknowledgementHtml(is_array($acknowledgement) ? $acknowledgement : null, (string)($tail['acknowledgement_state'] ?? 'absent'), $companyId, $accountingPeriodId) . '
        </section>';
    }

    private function acknowledgementHtml(?array $acknowledgement, string $state, int $companyId, int $accountingPeriodId): string
    {
        $acknowledged = $state === 'current';
        return \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'transaction cut-off position',
            'companyId' => $companyId,
            'accountingPeriodId' => $accountingPeriodId,
            'acknowledged' => $acknowledged,
            'acknowledgementState' => $state,
            'acknowledgedAt' => (string)($acknowledgement['acknowledged_at'] ?? ''),
            'acknowledgedBy' => (string)($acknowledgement['acknowledged_by'] ?? ''),
            'note' => (string)($acknowledgement['note'] ?? ''),
            'intent' => 'save_transaction_tail_acknowledgement',
            'revokeIntent' => 'save_transaction_tail_acknowledgement',
            'checkboxName' => 'transaction_tail_acknowledgement',
            'approveFields' => ['transaction_tail_acknowledgement' => '1'],
            'revokeFields' => ['transaction_tail_acknowledgement' => '0'],
            'noteName' => 'review_acknowledgement_note',
            'noteId' => 'transaction-tail-acknowledgement-note',
        ]);
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function displayDate(string $date): string
    {
        return trim($date) !== '' ? HelperFramework::displayDate($date) : '';
    }

    private function blankToDash(string $value): string
    {
        return trim($value) !== '' ? $value : '-';
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
