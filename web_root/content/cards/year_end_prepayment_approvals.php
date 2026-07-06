<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_prepayment_approvalsCard extends CardBaseFramework
{
    private const PAGE_SIZE = 10;

    public function key(): string
    {
        return 'year_end_prepayment_approvals';
    }

    public function title(): string
    {
        return 'Prepayment Approvals';
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
            [
                'key' => 'prepaymentApproval',
                'service' => \eel_accounts\Service\YearEndChecklistService::class,
                'method' => 'fetchReviewAcknowledgement',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'checkCode' => 'prepayment_approvals',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['year.end.state', 'year.end.checklist', 'prepayments.state'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $acknowledgement = $context['services']['prepaymentApproval'] ?? null;
        $review = (array)($context['services']['prepaymentsReview'] ?? []);

        return '<section class="settings-stack" id="year-end-prepayment-approvals">
            ' . $this->summaryHtml($review) . '
            ' . $this->configuredTable($context)->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]) . '
            ' . $this->acknowledgementHtml(
                is_array($acknowledgement) ? $acknowledgement : null,
                $companyId,
                $accountingPeriodId,
                empty($review['available']),
                (int)($review['pending_count'] ?? 0)
            ) . '
        </section>';
    }

    public function tables(array $context): array
    {
        return [$this->table($context)];
    }

    private function configuredTable(array $context): TableFramework
    {
        $table = $this->table($context);
        $company = (array)($context['company'] ?? []);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Prepayments',
                $this->paginationPageField(),
                [
                    'page' => (string)($context['page']['page_id'] ?? 'year_end'),
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
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);

        return TableFramework::make($this->key(), $this->prepaidRows($context))
            ->filename('year-end-prepayment-approvals')
            ->exportLimit(5000)
            ->classes(wrapperClass: 'table-scroll')
            ->empty('No pre-paid items have been recorded for this accounting period.')
            ->textColumn('source_label', 'Source')
            ->textColumn('source_date_label', 'Date')
            ->textColumn('nominal_label', 'Nominal')
            ->textColumn('description', 'Description')
            ->column(
                'amount',
                'Amount',
                html: fn(array $row): string => HelperFramework::escape($this->money($companySettings, $row['amount'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['amount'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->textColumn('service_start_label', 'Service Start')
            ->textColumn('service_end_label', 'Service End')
            ->textColumn('reviewed_by', 'Reviewed By')
            ->textColumn('reviewed_at', 'Reviewed At');
    }

    private function prepaidRows(array $context): array
    {
        $rows = [];
        foreach ((array)(($context['services']['prepaymentsReview'] ?? [])['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $review = (array)($item['review'] ?? []);
            if ((string)($review['status'] ?? '') !== 'prepaid') {
                continue;
            }

            $rows[] = [
                'source_label' => HelperFramework::labelFromKey((string)($item['source_type'] ?? ''), '_'),
                'source_date_label' => $this->displayDate((string)($item['source_date'] ?? '')),
                'nominal_label' => trim((string)($item['nominal_code'] ?? '') . ' ' . (string)($item['nominal_name'] ?? '')),
                'description' => (string)($item['description'] ?? ''),
                'amount' => (float)($item['amount'] ?? 0),
                'service_start_label' => $this->displayDate((string)($review['service_start_date'] ?? '')),
                'service_end_label' => $this->displayDate((string)($review['service_end_date'] ?? '')),
                'reviewed_by' => (string)($review['reviewed_by'] ?? ''),
                'reviewed_at' => (string)($review['reviewed_at'] ?? ''),
            ];
        }

        return $rows;
    }

    private function summaryHtml(array $review): string
    {
        if (empty($review['available'])) {
            return '<section class="panel-soft settings-stack">' . $this->renderErrors((array)($review['errors'] ?? ['Prepayment review is not available.'])) . '</section>';
        }

        return '<div class="month-grid">
            ' . $this->summaryCard('Pre-paid items', (string)(int)($review['prepaid_count'] ?? 0)) . '
            ' . $this->summaryCard('Incomplete', (string)(int)($review['pending_count'] ?? 0)) . '
        </div>';
    }

    private function acknowledgementHtml(?array $acknowledgement, int $companyId, int $accountingPeriodId, bool $reviewUnavailable, int $incompleteCount): string
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return '';
        }

        if ($acknowledgement !== null) {
            return '<section class="panel-soft success settings-stack">
                <div class="eyebrow">Acknowledgement</div>
                <div class="stat-foot">' . HelperFramework::escape($this->confirmationFoot(
                    (string)($acknowledgement['acknowledged_at'] ?? ''),
                    (string)($acknowledgement['acknowledged_by'] ?? '')
                )) . '</div>
                <form method="post" data-ajax="true">
                    <input type="hidden" name="card_action" value="YearEnd">
                    <input type="hidden" name="intent" value="reopen_review_check">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <input type="hidden" name="check_code" value="prepayment_approvals">
                    <button class="button" type="submit">Revoke acknowledgement</button>
                </form>
            </section>';
        }

        $disabled = $reviewUnavailable || $incompleteCount > 0;
        $buttonAttributes = $disabled ? ' disabled title="' . HelperFramework::escape($this->blockedApprovalTitle($reviewUnavailable, $incompleteCount)) . '"' : ' disabled data-year-end-ack-submit';

        return '<section class="panel-soft warn full settings-stack">
            <div class="eyebrow">Acknowledgement</div>
            <form method="post" data-ajax="true" class="form-grid" data-year-end-ack-form="true">
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="acknowledge_review_check">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="check_code" value="prepayment_approvals">
                <label class="checkbox-row full">
                    <input type="checkbox" required data-year-end-ack-checkbox' . ($disabled ? ' disabled' : '') . '>
                    <span>I acknowledge that the year-end prepayment position has been reviewed before closing this Accounting Period</span>
                </label>
                ' . ($disabled ? '<div class="helper full">' . HelperFramework::escape($this->blockedApprovalTitle($reviewUnavailable, $incompleteCount)) . '</div>' : '') . '
                <div class="actions-row"><button class="button primary" type="submit"' . $buttonAttributes . '>Save acknowledgement</button></div>
            </form>
        </section>';
    }

    private function blockedApprovalTitle(bool $reviewUnavailable, int $incompleteCount): string
    {
        if ($reviewUnavailable) {
            return 'Prepayment review must be available before this approval can be saved.';
        }

        return 'Complete all pre-paid service dates before saving this approval. Incomplete: ' . $incompleteCount . '.';
    }

    private function confirmationFoot(string $acknowledgedAt, string $acknowledgedBy): string
    {
        return 'Confirmed'
            . (trim($acknowledgedAt) !== '' ? ' at ' . trim($acknowledgedAt) : '')
            . (trim($acknowledgedBy) !== '' ? ' by ' . trim($acknowledgedBy) : '')
            . '.';
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

    private function tableInvalidationFact(): string
    {
        return 'prepayments.state';
    }
}
