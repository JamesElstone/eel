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
            [
                'key' => 'sectionReview',
                'service' => \eel_accounts\Service\YearEndSectionApprovalService::class,
                'method' => 'fetchReview',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'checkCode' => 'transaction_tail_review',
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
        $sectionReview = (array)($context['services']['sectionReview'] ?? []);
        $rowsHtml = '';
        foreach ((array)($tail['rows'] ?? []) as $row) {
            $amount = array_key_exists('last_transaction_amount', $row) ? $row['last_transaction_amount'] : null;
            $amountDisplay = $amount === null || trim((string)$amount) === '' ? '-' : $this->money($companySettings, $amount);
            $balance = array_key_exists('balance', $row) ? $row['balance'] : null;
            $balanceDisplay = $balance === null || trim((string)$balance) === '' ? '-' : $this->money($companySettings, $balance);
            $rowsHtml .= '<tr>
                <td>' . \eel_accounts\Support\Utf8::html((string)($row['account'] ?? '')) . '</td>
                <td>' . \eel_accounts\Support\Utf8::html(HelperFramework::labelFromKey((string)($row['account_type'] ?? ''), '_')) . '</td>
                <td>' . \eel_accounts\Support\Utf8::html($this->blankToDash($this->displayDate((string)($row['last_transaction_date'] ?? '')))) . '</td>
                <td>' . \eel_accounts\Support\Utf8::html($this->blankToDash((string)($row['last_transaction_desc'] ?? ''))) . '</td>
                <td class="numeric">' . \eel_accounts\Support\Utf8::html($amountDisplay) . '</td>
                <td class="numeric">' . \eel_accounts\Support\Utf8::html($balanceDisplay) . '</td>
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
            ' . $this->acknowledgementHtml($sectionReview, $companyId, $accountingPeriodId) . '
        </section>';
    }

    private function acknowledgementHtml(array $review, int $companyId, int $accountingPeriodId): string
    {
        $acknowledgement = (array)($review['acknowledgement'] ?? []);
        return \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'transaction cut-off position',
            'companyId' => $companyId,
            'accountingPeriodId' => $accountingPeriodId,
            'acknowledged' => !empty($review['acknowledgement_current']),
            'acknowledgementState' => (string)($review['acknowledgement_state'] ?? 'absent'),
            'acknowledgedAt' => (string)($acknowledgement['acknowledged_at'] ?? ''),
            'acknowledgedBy' => (string)($acknowledgement['acknowledged_by'] ?? ''),
            'note' => (string)($acknowledgement['note'] ?? ''),
            'intent' => 'approve_section_review',
            'revokeIntent' => 'revoke_section_review',
            'approveFields' => ['check_code' => 'transaction_tail_review'],
            'revokeFields' => ['check_code' => 'transaction_tail_review'],
            'noteName' => 'approval_note',
            'noteId' => 'transaction-tail-acknowledgement-note',
            'questions' => (array)($review['questions'] ?? []),
            'answers' => (array)($review['answers'] ?? []),
            'disabled' => empty($review['can_approve']),
            'disabledReason' => (string)(($review['approval_errors'] ?? [])[0] ?? ''),
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
            $html .= '<div class="helper">' . \eel_accounts\Support\Utf8::html((string)$error) . '</div>';
        }

        return $html;
    }
}
