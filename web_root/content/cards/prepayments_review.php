<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _prepayments_reviewCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'prepayments_review';
    }

    public function title(): string
    {
        return 'Prepayment Review';
    }

    public function helper(array $context): string
    {
        return 'Only nominals marked as prepayment candidates in Nominals appear here. Enter service dates only when the source item covers a period beyond this accounting year.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'prepaymentsReview',
                'service' => \eel_accounts\Service\PrepaymentReviewService::class,
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
        return ['prepayments.state', 'year.end.state'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $review = (array)($context['services']['prepaymentsReview'] ?? []);
        if (empty($review['available'])) {
            return '<section class="settings-stack" id="prepayments-review">' . $this->renderErrors((array)($review['errors'] ?? ['Prepayment review is not available.'])) . '</section>';
        }

        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriod = (array)($review['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));
        $companySettings = (array)($company['settings'] ?? []);
        $isLocked = (new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId);
        $rowsHtml = '';

        foreach ((array)($review['items'] ?? []) as $item) {
            $rowsHtml .= $this->itemRow((array)$item, $companyId, $accountingPeriodId, $companySettings, $accountingPeriod, $isLocked);
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="6">No transaction or expense claim lines use nominals marked as prepayment candidates for this accounting period.</td></tr>';
        }

        return '<section class="settings-stack" id="prepayments-review">
            <div class="month-grid">
                ' . $this->summaryCard('Potential items', (string)(int)($review['total_count'] ?? 0)) . '
                ' . $this->summaryCard('Reviewed', (string)(int)($review['reviewed_count'] ?? 0)) . '
                ' . $this->summaryCard('Prepaid', (string)(int)($review['prepaid_count'] ?? 0)) . '
                ' . $this->summaryCard('Awaiting decision', (string)(int)($review['pending_count'] ?? 0)) . '
            </div>
            ' . ($isLocked ? '<div class="helper"><span class="badge warning">Period locked</span> Prepayment decisions are read only.</div>' : '') . '
            <div class="panel-soft">
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Source</th><th>Date</th><th>Nominal</th><th>Description</th><th>Amount</th><th>Status</th></tr></thead>
                        <tbody>' . $rowsHtml . '</tbody>
                    </table>
                </div>
            </div>
        </section>';
    }

    private function itemRow(array $item, int $companyId, int $accountingPeriodId, array $companySettings, array $accountingPeriod, bool $isLocked): string
    {
        $review = (array)($item['review'] ?? []);
        $sourceType = (string)($item['source_type'] ?? '');
        $sourceId = (int)($item['source_id'] ?? 0);
        $formId = 'prepayment-review-' . preg_replace('/[^a-z0-9_-]/i', '-', $sourceType) . '-' . $sourceId;
        $status = (string)($review['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'not_prepaid', 'prepaid'], true)) {
            $status = 'pending';
        }
        $sourceDate = (string)($item['source_date'] ?? '');
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        $serviceStart = trim((string)($review['service_start_date'] ?? ''));
        if ($serviceStart === '') {
            $serviceStart = $sourceDate;
        }
        $serviceEnd = trim((string)($review['service_end_date'] ?? ''));
        if ($serviceEnd === '') {
            $serviceEnd = $periodEnd;
        }
        $saveButtonClass = 'prepayment-save-' . preg_replace('/[^a-z0-9_-]/i', '-', $sourceType) . '-' . $sourceId;

        return '<tr>
            <td>' . HelperFramework::escape(HelperFramework::labelFromKey($sourceType, '_')) . '</td>
            <td>' . HelperFramework::escape($this->displayDate((string)($item['source_date'] ?? ''))) . '</td>
            <td>' . HelperFramework::escape(trim((string)($item['nominal_code'] ?? '') . ' ' . (string)($item['nominal_name'] ?? ''))) . '</td>
            <td>
                ' . HelperFramework::escape((string)($item['description'] ?? '')) . '
            </td>
            <td class="numeric">' . HelperFramework::escape($this->money($companySettings, $item['amount'] ?? 0)) . '</td>
            <td>
                ' . ($isLocked ? '<span class="badge ' . ($status === 'pending' ? 'warning' : ($status === 'prepaid' ? 'success' : 'info')) . '">' . HelperFramework::escape($this->statusLabel($status)) . '</span>' : '
                <form id="' . HelperFramework::escape($formId) . '" method="post" data-ajax="true" class="actions-row actions-row-nowrap prepayment-review-form">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                    <input type="hidden" name="card_action" value="Prepayments">
                    <input type="hidden" name="intent" value="save_review">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <input type="hidden" name="source_type" value="' . HelperFramework::escape($sourceType) . '">
                    <input type="hidden" name="source_id" value="' . $sourceId . '">
                    <input type="hidden" name="prepayment_notes" value="' . HelperFramework::escape((string)($review['notes'] ?? '')) . '">
                    <select class="select" id="' . HelperFramework::escape($formId) . '-status" name="prepayment_status" data-dirty-action-target=".' . HelperFramework::escape($saveButtonClass) . '" data-initial-value="' . HelperFramework::escape($status) . '">' . $this->statusOptions($status) . '</select>
                    <span class="prepayment-date-actions" data-visible-when-field="prepayment_status" data-visible-when-value="prepaid"' . ($status === 'prepaid' ? '' : ' hidden aria-hidden="true"') . '>
                        <label class="prepayment-date-field" for="' . HelperFramework::escape($formId) . '-service-start-date">Service start
                            <input class="input" id="' . HelperFramework::escape($formId) . '-service-start-date" type="date" name="service_start_date" value="' . HelperFramework::escape($serviceStart) . '" data-dirty-action-target=".' . HelperFramework::escape($saveButtonClass) . '" data-initial-value="' . HelperFramework::escape($serviceStart) . '" data-dirty-require-value="1">
                        </label>
                        <label class="prepayment-date-field" for="' . HelperFramework::escape($formId) . '-service-end-date">Service end
                            <input class="input" id="' . HelperFramework::escape($formId) . '-service-end-date" type="date" name="service_end_date" value="' . HelperFramework::escape($serviceEnd) . '" data-dirty-action-target=".' . HelperFramework::escape($saveButtonClass) . '" data-initial-value="' . HelperFramework::escape($serviceEnd) . '" data-dirty-require-value="1">
                        </label>
                    </span>
                    <button class="button button-inline primary ' . HelperFramework::escape($saveButtonClass) . '" type="submit" data-dirty-enable-mode="changed" disabled>Save decision</button>
                </form>') . '
            </td>
        </tr>';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'prepaid' => 'Prepaid',
            'not_prepaid' => 'Not pre-paid',
            default => 'Review required',
        };
    }

    private function statusOptions(string $selected): string
    {
        $labels = [
            'pending' => 'Review required — choose a decision',
            'not_prepaid' => 'Not pre-paid',
            'prepaid' => 'Prepaid',
        ];
        $html = '';
        foreach ($labels as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape($value) . '"' . ($selected === $value ? ' selected' : '') . ($value === 'pending' ? ' disabled' : '') . '>' . HelperFramework::escape($label) . '</option>';
        }

        return $html;
    }

    private function summaryCard(string $label, string $value): string
    {
        return '<div class="panel-soft"><div class="eyebrow">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
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
            $html .= '<div class="helper">' . HelperFramework::escape((string)$error) . '</div>';
        }

        return $html;
    }
}
