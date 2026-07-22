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
        return 'Only posted expense debits on nominals marked as prepayment candidates appear here. Amounts are apportioned by inclusive days and exact cumulative penny rounding. Future accounting-period releases are posted in the first service month of each period; monthly P&amp;L is not spread in this phase.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'prepaymentWorkflowContext',
                'service' => \eel_accounts\Service\PrepaymentWorkflowContextService::class,
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
        $workflowContext = (array)($context['services']['prepaymentWorkflowContext'] ?? []);
        $review = (array)($workflowContext['review'] ?? []);
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
            $rowsHtml = '<tr><td colspan="6">No direct transaction, split line or expense claim line uses a nominal marked as a prepayment candidate for this accounting period.</td></tr>';
        }
        $carriedHtml = $this->carriedSchedulesHtml((array)($review['carried_schedules'] ?? []), $companySettings);
        $currentPeriodSchedulesHtml = $this->currentPeriodSchedulesHtml(
            (array)($review['items'] ?? []),
            $accountingPeriodId,
            $companySettings,
            $companyId,
            $isLocked
        );
        $excludedHtml = $this->excludedCandidatesHtml((array)($review['excluded_items'] ?? []), $companySettings);
        $repairHtml = $this->scheduleRepairHtml(
            (array)($workflowContext['repair'] ?? []),
            $companyId,
            $accountingPeriodId,
            $companySettings,
            $isLocked
        );

        return '<section class="settings-stack" id="prepayments-review">
            <div class="month-grid prepayments-summary-grid">
                ' . $this->summaryCard('Potential items', (string)(int)($review['total_count'] ?? 0)) . '
                ' . $this->summaryCard('Reviewed', (string)(int)($review['reviewed_count'] ?? 0)) . '
                ' . $this->summaryCard('Prepaid', (string)(int)($review['prepaid_count'] ?? 0)) . '
                ' . $this->summaryCard('Awaiting decision', (string)(int)($review['pending_count'] ?? 0)) . '
                ' . $this->summaryCard('Carried schedules', (string)(int)($review['carried_schedule_count'] ?? 0)) . '
            </div>
            ' . $carriedHtml . '
            ' . $currentPeriodSchedulesHtml . '
            ' . ($isLocked ? '<div class="helper"><span class="badge warning">Period locked</span> Prepayment decisions are read only.</div>' : '') . '
            ' . $repairHtml . '
            ' . $excludedHtml . '
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

    private function scheduleRepairHtml(
        array $repair,
        int $companyId,
        int $accountingPeriodId,
        array $settings,
        bool $isLocked
    ): string {
        if (empty($repair['available'])) {
            return '';
        }
        $missingRows = '';
        foreach ((array)($repair['missing_reviews'] ?? []) as $missing) {
            $allocation = (array)($missing['selected_allocation'] ?? []);
            $missingRows .= '<tr><td>#' . (int)$missing['review_id'] . '</td><td>'
                . HelperFramework::escape(HelperFramework::labelFromKey((string)$missing['source_type'], '_') . ' #' . (int)$missing['source_id']) . '</td><td>'
                . HelperFramework::escape($this->displayDate((string)$missing['source_date'])) . '</td><td class="numeric">'
                . HelperFramework::escape($this->money($settings, ((int)$missing['source_amount_pence']) / 100)) . '</td><td>'
                . HelperFramework::escape($this->displayDate((string)$missing['service_start_date']) . '–' . $this->displayDate((string)$missing['service_end_date'])) . '</td><td class="numeric">'
                . (int)($allocation['overlap_days'] ?? 0) . '</td><td class="numeric">'
                . HelperFramework::escape($this->money($settings, ((int)($allocation['expense_pence'] ?? 0)) / 100)) . '</td><td class="numeric">'
                . HelperFramework::escape($this->money($settings, ((int)($allocation['closing_deferred_pence'] ?? 0)) / 100)) . '</td></tr>';
        }
        if ($missingRows === '') {
            return '';
        }
        $button = $isLocked ? '' : '<form method="post" data-ajax="true" class="actions-row">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Prepayments">
                <input type="hidden" name="intent" value="recalculate_schedule">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <button class="button" type="submit">Recalculate schedule</button>
            </form>';
        return '<div class="panel-soft warn" id="prepayment-schedule-repair"><h4 class="card-title">Saved prepayments missing automated schedules</h4>
            <p class="helper">This read-only preview reconstructs the schedule from the posted source and saved service dates. Recalculation creates append-only schedule snapshots only; it does not post journals.</p>
            <div class="table-scroll"><table><thead><tr><th>Review</th><th>Source</th><th>Date</th><th>Amount</th><th>Service dates</th><th>AP days</th><th>AP expense</th><th>Closing asset</th></tr></thead><tbody>'
            . $missingRows . '</tbody></table></div>' . $button . '</div>';
    }

    private function excludedCandidatesHtml(array $items, array $companySettings): string
    {
        if ($items === []) {
            return '';
        }
        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr><td>'
                . HelperFramework::escape(HelperFramework::labelFromKey((string)($item['source_type'] ?? ''), '_'))
                . ' #' . (int)($item['source_id'] ?? 0) . '</td><td>'
                . HelperFramework::escape($this->displayDate((string)($item['source_date'] ?? ''))) . '</td><td>'
                . HelperFramework::escape(trim((string)($item['nominal_code'] ?? '') . ' ' . (string)($item['nominal_name'] ?? ''))) . '</td><td>'
                . HelperFramework::escape((string)($item['description'] ?? '')) . '</td><td class="numeric">'
                . HelperFramework::escape($this->money($companySettings, $item['amount'] ?? 0)) . '</td><td>'
                . HelperFramework::escape((string)($item['exclusion_reason'] ?? 'The source is not eligible for prepayment review.'))
                . '</td></tr>';
        }

        return '<div class="panel-soft warn"><h4 class="card-title">Excluded source items</h4>'
            . '<p class="helper">These rows use a prepayment-candidate nominal but do not have exact posted positive-debit evidence. They are shown for coverage and do not block Year End.</p>'
            . '<div class="table-scroll"><table><thead><tr><th>Source</th><th>Date</th><th>Nominal</th><th>Description</th><th>Amount</th><th>Reason</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></div>';
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
        $sourceValid = !array_key_exists('source_valid', $item) || !empty($item['source_valid']);

        return '<tr>
            <td>' . HelperFramework::escape(HelperFramework::labelFromKey($sourceType, '_')) . '</td>
            <td>' . HelperFramework::escape($this->displayDate((string)($item['source_date'] ?? ''))) . '</td>
            <td>' . HelperFramework::escape(trim((string)($item['nominal_code'] ?? '') . ' ' . (string)($item['nominal_name'] ?? ''))) . '</td>
            <td>
                ' . HelperFramework::escape((string)($item['description'] ?? '')) . '
                ' . (!$sourceValid ? '<div class="helper"><span class="badge warning">Not postable</span> ' . HelperFramework::escape((string)(($item['source_errors'] ?? [])[0] ?? 'The source journal is not ready.')) . '</div>' : '') . '
            </td>
            <td class="numeric">' . HelperFramework::escape($this->money($companySettings, $item['amount'] ?? 0)) . '</td>
            <td>
                ' . ($isLocked || !$sourceValid ? '<span class="badge ' . ($status === 'pending' ? 'warning' : ($status === 'prepaid' ? 'success' : 'info')) . '">' . HelperFramework::escape($this->statusLabel($status)) . '</span>' : '
                <form id="' . HelperFramework::escape($formId) . '" method="post" data-ajax="true" class="actions-row actions-row-nowrap prepayment-review-form">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                    <input type="hidden" name="card_action" value="Prepayments">
                    <input type="hidden" name="intent" value="save_review">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <input type="hidden" name="source_type" value="' . HelperFramework::escape($sourceType) . '">
                    <input type="hidden" name="source_id" value="' . $sourceId . '">
                    <input type="hidden" name="prepayment_notes" value="' . HelperFramework::escape((string)($review['notes'] ?? '')) . '">
                    <select class="select" id="' . HelperFramework::escape($formId) . '-status" name="prepayment_status">' . $this->statusOptions($status) . '</select>
                    <span class="prepayment-date-actions" data-visible-when-field="prepayment_status" data-visible-when-value="prepaid"' . ($status === 'prepaid' ? '' : ' hidden aria-hidden="true"') . '>
                        <label class="prepayment-date-field" for="' . HelperFramework::escape($formId) . '-service-start-date">Service start
                            <input class="input" id="' . HelperFramework::escape($formId) . '-service-start-date" type="date" name="service_start_date" value="' . HelperFramework::escape($serviceStart) . '" required>
                        </label>
                        <label class="prepayment-date-field" for="' . HelperFramework::escape($formId) . '-service-end-date">Service end
                            <input class="input" id="' . HelperFramework::escape($formId) . '-service-end-date" type="date" name="service_end_date" value="' . HelperFramework::escape($serviceEnd) . '" required>
                        </label>
                    </span>
                    <button class="button primary" type="submit">Save decision</button>
                </form>') . '
            </td>
        </tr>';
    }

    private function currentPeriodSchedulesHtml(
        array $items,
        int $accountingPeriodId,
        array $settings,
        int $companyId,
        bool $isLocked
    ): string {
        $rows = '';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $review = (array)($item['review'] ?? []);
            if ((string)($review['status'] ?? '') !== 'prepaid') {
                continue;
            }

            $schedule = is_array($review['schedule'] ?? null) ? (array)$review['schedule'] : null;
            $allocations = is_array($schedule) ? array_values((array)($schedule['allocations'] ?? [])) : [];
            if ($allocations === []) {
                $rows .= '<tr>
                    <td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($item['source_type'] ?? ''), '_')) . '</td>
                    <td>' . HelperFramework::escape((string)($item['description'] ?? '')) . '</td>
                    <td>' . HelperFramework::escape(
                        $this->displayDate((string)($review['service_start_date'] ?? ''))
                        . '–'
                        . $this->displayDate((string)($review['service_end_date'] ?? ''))
                    ) . '</td>
                    <td class="numeric">' . HelperFramework::escape($this->money($settings, $item['amount'] ?? 0)) . '</td>
                    <td colspan="4"><span class="badge warning">Calculation required</span></td>
                    <td></td>
                </tr>';
                continue;
            }

            $rowspan = count($allocations) > 1 ? ' rowspan="' . count($allocations) . '"' : '';
            $actions = $this->scheduleActionsHtml($schedule, $review, $accountingPeriodId, $settings, $companyId, $isLocked);
            foreach ($allocations as $index => $allocation) {
                $rows .= '<tr>';
                if ($index === 0) {
                    $rows .= '<td' . $rowspan . '>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($item['source_type'] ?? ''), '_')) . '</td>
                        <td' . $rowspan . '>' . HelperFramework::escape((string)($item['description'] ?? '')) . '</td>
                        <td' . $rowspan . '>' . HelperFramework::escape(
                            $this->displayDate((string)($review['service_start_date'] ?? ''))
                            . '–'
                            . $this->displayDate((string)($review['service_end_date'] ?? ''))
                        ) . '</td>
                        <td class="numeric"' . $rowspan . '>' . HelperFramework::escape($this->money($settings, $item['amount'] ?? 0)) . '</td>';
                }
                $rows .= '<td>' . HelperFramework::escape(
                    $this->displayDate((string)($allocation['period_start'] ?? ''))
                    . '–'
                    . $this->displayDate((string)($allocation['period_end'] ?? ''))
                ) . '</td>
                    <td class="numeric">' . (int)($allocation['overlap_days'] ?? 0) . '</td>
                    <td class="numeric">' . HelperFramework::escape($this->money($settings, ((int)($allocation['expense_pence'] ?? 0)) / 100)) . '</td>
                    <td class="numeric">' . HelperFramework::escape($this->money($settings, ((int)($allocation['closing_deferred_pence'] ?? 0)) / 100)) . '</td>';
                if ($index === 0) {
                    $rows .= '<td' . $rowspan . '>' . $actions . '</td>';
                }
                $rows .= '</tr>';
            }
        }

        if ($rows === '') {
            return '<div class="panel-soft"><h4 class="card-title">Pre-Payment Schedules - During Accounting Period</h4>
                <p class="helper">No items in this accounting period are currently classified as prepaid.</p></div>';
        }

        return '<div class="panel-soft"><h4 class="card-title">Pre-Payment Schedules - During Accounting Period</h4>
            <div class="table-scroll"><table><thead><tr><th>Source</th><th>Description</th><th>Service dates</th><th>Amount</th><th>Accounting period</th><th>Days</th><th>Expense</th><th>Closing asset</th><th>Actions</th></tr></thead>
            <tbody>' . $rows . '</tbody></table></div></div>';
    }

    private function scheduleActionsHtml(array $schedule, array $review, int $accountingPeriodId, array $settings, int $companyId, bool $isLocked): string
    {
        $hasPostings = false;
        foreach ((array)($schedule['allocations'] ?? []) as $allocation) {
            if ((int)($allocation['posting_count'] ?? 0) > 0) {
                $hasPostings = true;
            }
        }

        $unallocated = (int)($schedule['unallocated_pence'] ?? 0);
        $warning = $unallocated > 0
            ? '<div class="helper"><span class="badge warning">Future period missing</span> ' . HelperFramework::escape($this->money($settings, $unallocated / 100)) . ' remains to be allocated when later accounting periods are created.</div>'
            : '';
        $reopen = $hasPostings && !$isLocked
            ? '<form method="post" data-ajax="true" class="actions-row">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Prepayments">
                <input type="hidden" name="intent" value="reopen_schedule">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="review_id" value="' . (int)($review['id'] ?? 0) . '">
                <button class="button" type="submit">Reopen schedule</button>
               </form>'
            : '';

        return $warning . $reopen;
    }

    private function carriedSchedulesHtml(array $schedules, array $settings): string
    {
        if ($schedules === []) {
            return '';
        }
        $rows = '';
        foreach ($schedules as $schedule) {
            $allocation = (array)($schedule['selected_allocation'] ?? []);
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($schedule['source_description'] ?? 'Prepayment source')) . '</td>
                <td>' . HelperFramework::escape($this->displayDate((string)($schedule['service_start_date'] ?? '')) . '–' . $this->displayDate((string)($schedule['service_end_date'] ?? ''))) . '</td>
                <td class="numeric">' . (int)($allocation['overlap_days'] ?? 0) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, ((int)($allocation['expense_pence'] ?? 0)) / 100)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, ((int)($allocation['opening_deferred_pence'] ?? 0)) / 100)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, ((int)($allocation['closing_deferred_pence'] ?? 0)) / 100)) . '</td>
                <td><span class="badge ' . ((string)($allocation['journal_state'] ?? '') === 'posted' ? 'success' : 'warning') . '">' . HelperFramework::escape(HelperFramework::labelFromKey((string)($allocation['journal_state'] ?? 'not_posted'), '_')) . '</span></td>
            </tr>';
        }

        return '<div class="panel-soft"><h4 class="card-title">Pre-Payment Schedules - Carried Forwards</h4>
            <p class="helper">These purchases originated in an earlier accounting period. Their AP allocation is released at the first service date in this period.</p>
            <div class="table-scroll"><table><thead><tr><th>Source</th><th>Service dates</th><th>Days</th><th>AP expense</th><th>Opening asset</th><th>Closing asset</th><th>Journal</th></tr></thead>
            <tbody>' . $rows . '</tbody></table></div></div>';
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
