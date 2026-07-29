<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_expenses_confirmationCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'year_end_expenses_confirmation';
    }

    public function title(): string
    {
        return 'Year End Expenses Confirmation';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'yearEndExpensesConfirmation',
                'service' => \eel_accounts\Service\YearEndExpenseConfirmationService::class,
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
                    'checkCode' => 'expense_position_acknowledgement',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['year.end.state', 'year.end.checklist', 'year.end.expenses.confirmation'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $expenses = (array)($context['services']['yearEndExpensesConfirmation'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $companySettings = (array)($company['settings'] ?? []);
        $accountingPeriod = (array)($expenses['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));

        if (empty($expenses['available'])) {
            return '<section class="settings-stack" id="year-end-expenses-confirmation">' . $this->renderErrors((array)($expenses['errors'] ?? ['Year-end expense confirmation is not available.'])) . '</section>';
        }

        $totals = (array)($expenses['totals'] ?? []);
        $sectionReview = (array)($context['services']['sectionReview'] ?? []);
        $rowsHtml = '';
        foreach ((array)($expenses['claimants'] ?? []) as $claimant) {
            $rowsHtml .= '<tr>
                <td>' . \eel_accounts\Support\Utf8::html((string)($claimant['claimant_name'] ?? '')) . '</td>
                <td>' . \eel_accounts\Support\Utf8::html($this->displayDate((string)($claimant['last_claimed'] ?? ''))) . '</td>
                <td>' . \eel_accounts\Support\Utf8::html((string)($claimant['last_item_desc'] ?? '')) . '</td>
                <td class="numeric">' . \eel_accounts\Support\Utf8::html(!array_key_exists('last_expense_amount', $claimant) || $claimant['last_expense_amount'] === null ? '' : $this->money($companySettings, $claimant['last_expense_amount'])) . '</td>
                <td class="numeric">' . \eel_accounts\Support\Utf8::html($this->money($companySettings, $claimant['brought_forward'] ?? 0)) . '</td>
                <td class="numeric">' . \eel_accounts\Support\Utf8::html($this->money($companySettings, $claimant['claimed_total'] ?? 0)) . '</td>
                <td class="numeric">' . \eel_accounts\Support\Utf8::html($this->money($companySettings, $claimant['payments_made'] ?? 0)) . '</td>
                <td class="numeric">' . \eel_accounts\Support\Utf8::html($this->money($companySettings, $claimant['carried_forward'] ?? 0)) . '</td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="8">No expense claim balances were found for this accounting period.</td></tr>';
        }

        $acknowledgementForm = $this->acknowledgementHtml(
            $sectionReview,
            $companyId,
            $accountingPeriodId
        );

        return '<section class="settings-stack" id="year-end-expenses-confirmation">
            <div class="month-grid">
                ' . $this->summaryCard('Balance brought forward (b/f)', $this->money($companySettings, $totals['brought_forward'] ?? 0)) . '
                ' . $this->summaryCard('Claimed in period', $this->money($companySettings, $totals['claimed_total'] ?? 0)) . '
                ' . $this->summaryCard('Payments in period', $this->money($companySettings, $totals['payments_made'] ?? 0)) . '
                ' . $this->summaryCard('Balance carried forward (c/f)', $this->money($companySettings, $totals['carried_forward'] ?? 0)) . '
            </div>
            <div class="table-scroll panel-soft">
                <table>
                    <thead><tr><th>Claimant</th><th>Last claimed</th><th>Last item desc</th><th>Last expense amount</th><th>Balance brought forward (b/f)</th><th>Claimed</th><th>Payments</th><th>Balance carried forward (c/f)</th></tr></thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>
            ' . $acknowledgementForm . '
        </section>';
    }

    private function acknowledgementHtml(array $review, int $companyId, int $accountingPeriodId): string
    {
        $acknowledgement = (array)($review['acknowledgement'] ?? []);
        return \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'expense position',
            'companyId' => $companyId,
            'accountingPeriodId' => $accountingPeriodId,
            'acknowledged' => !empty($review['acknowledgement_current']),
            'acknowledgementState' => (string)($review['acknowledgement_state'] ?? 'absent'),
            'acknowledgedAt' => (string)($acknowledgement['acknowledged_at'] ?? ''),
            'acknowledgedBy' => (string)($acknowledgement['acknowledged_by'] ?? ''),
            'note' => (string)($acknowledgement['note'] ?? ''),
            'intent' => 'approve_section_review',
            'revokeIntent' => 'revoke_section_review',
            'approveFields' => ['check_code' => 'expense_position_acknowledgement'],
            'revokeFields' => ['check_code' => 'expense_position_acknowledgement'],
            'noteName' => 'approval_note',
            'questions' => (array)($review['questions'] ?? []),
            'answers' => (array)($review['answers'] ?? []),
            'disabled' => empty($review['can_approve']),
            'disabledReason' => (string)(($review['approval_errors'] ?? [])[0] ?? ''),
        ]);
    }

    private function summaryCard(string $label, string $value): string
    {
        return '<div class="panel-soft"><div class="eyebrow">' . \eel_accounts\Support\Utf8::html($label) . '</div><div class="summary-value">' . \eel_accounts\Support\Utf8::html($value) . '</div></div>';
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function displayDate(string $date): string
    {
        return trim($date) !== '' ? HelperFramework::displayDate($date) : '';
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
