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
        $rowsHtml = '';

        foreach ((array)($review['items'] ?? []) as $item) {
            $rowsHtml .= $this->itemRow((array)$item, $companyId, $accountingPeriodId, $companySettings);
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="9">No transaction or expense claim lines use nominals marked as prepayment candidates for this accounting period.</td></tr>';
        }

        return '<section class="settings-stack" id="prepayments-review">
            <div class="month-grid">
                ' . $this->summaryCard('Potential items', (string)(int)($review['total_count'] ?? 0)) . '
                ' . $this->summaryCard('Reviewed', (string)(int)($review['reviewed_count'] ?? 0)) . '
                ' . $this->summaryCard('Pending', (string)(int)($review['pending_count'] ?? 0)) . '
            </div>
            <div class="helper">Only nominals marked as prepayment candidates in Nominals appear here. Enter service dates only when the source item covers a period beyond this accounting year.</div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Source</th><th>Date</th><th>Nominal</th><th>Description</th><th>Amount</th><th>Status</th><th>Service start</th><th>Service end</th><th></th></tr></thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>
        </section>';
    }

    private function itemRow(array $item, int $companyId, int $accountingPeriodId, array $companySettings): string
    {
        $review = (array)($item['review'] ?? []);
        $sourceType = (string)($item['source_type'] ?? '');
        $sourceId = (int)($item['source_id'] ?? 0);
        $formId = 'prepayment-review-' . preg_replace('/[^a-z0-9_-]/i', '-', $sourceType) . '-' . $sourceId;
        $status = (string)($review['status'] ?? 'pending');

        return '<tr>
            <td>' . HelperFramework::escape(HelperFramework::labelFromKey($sourceType, '_')) . '</td>
            <td>' . HelperFramework::escape($this->displayDate((string)($item['source_date'] ?? ''))) . '</td>
            <td>' . HelperFramework::escape(trim((string)($item['nominal_code'] ?? '') . ' ' . (string)($item['nominal_name'] ?? ''))) . '</td>
            <td>
                ' . HelperFramework::escape((string)($item['description'] ?? '')) . '
                <form id="' . HelperFramework::escape($formId) . '" method="post" data-ajax="true">
                    <input type="hidden" name="card_action" value="Prepayments">
                    <input type="hidden" name="intent" value="save_review">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <input type="hidden" name="source_type" value="' . HelperFramework::escape($sourceType) . '">
                    <input type="hidden" name="source_id" value="' . $sourceId . '">
                    <input type="hidden" name="prepayment_notes" value="' . HelperFramework::escape((string)($review['notes'] ?? '')) . '">
                </form>
            </td>
            <td class="numeric">' . HelperFramework::escape($this->money($companySettings, $item['amount'] ?? 0)) . '</td>
            <td><select class="select" name="prepayment_status" form="' . HelperFramework::escape($formId) . '">' . $this->statusOptions($status) . '</select></td>
            <td><input class="input" type="date" name="service_start_date" form="' . HelperFramework::escape($formId) . '" value="' . HelperFramework::escape((string)($review['service_start_date'] ?? '')) . '"></td>
            <td><input class="input" type="date" name="service_end_date" form="' . HelperFramework::escape($formId) . '" value="' . HelperFramework::escape((string)($review['service_end_date'] ?? '')) . '"></td>
            <td><button class="button button-inline primary" type="submit" form="' . HelperFramework::escape($formId) . '">Save</button></td>
        </tr>';
    }

    private function statusOptions(string $selected): string
    {
        $labels = [
            'pending' => 'Pending',
            'not_prepaid' => 'Not prepaid',
            'prepaid' => 'Prepaid',
        ];
        $html = '';
        foreach ($labels as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . HelperFramework::escape($label) . '</option>';
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
