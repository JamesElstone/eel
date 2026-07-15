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
                'key' => 'prepaymentsReview',
                'service' => \eel_accounts\Service\PrepaymentReviewService::class,
                'method' => 'fetchContext',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
            [
                'key' => 'historicalCorrection',
                'service' => \eel_accounts\Service\PrepaymentHistoricalCorrectionService::class,
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
            $rowsHtml = '<tr><td colspan="7">No direct transaction, split line or expense claim line uses a nominal marked as a prepayment candidate for this accounting period.</td></tr>';
        }
        $carriedHtml = $this->carriedSchedulesHtml((array)($review['carried_schedules'] ?? []), $companySettings);
        $excludedHtml = $this->excludedCandidatesHtml((array)($review['excluded_items'] ?? []), $companySettings);
        $historicalHtml = $this->historicalCorrectionHtml(
            (array)($context['services']['historicalCorrection'] ?? []),
            $companyId,
            $accountingPeriodId,
            $companySettings,
            $isLocked
        );

        return '<section class="settings-stack" id="prepayments-review">
            <div class="month-grid">
                ' . $this->summaryCard('Potential items', (string)(int)($review['total_count'] ?? 0)) . '
                ' . $this->summaryCard('Reviewed', (string)(int)($review['reviewed_count'] ?? 0)) . '
                ' . $this->summaryCard('Prepaid', (string)(int)($review['prepaid_count'] ?? 0)) . '
                ' . $this->summaryCard('Awaiting decision', (string)(int)($review['pending_count'] ?? 0)) . '
                ' . $this->summaryCard('Carried schedules', (string)(int)($review['carried_schedule_count'] ?? 0)) . '
            </div>
            ' . ($isLocked ? '<div class="helper"><span class="badge warning">Period locked</span> Prepayment decisions are read only.</div>' : '') . '
            ' . $historicalHtml . '
            ' . $excludedHtml . '
            <div class="panel-soft">
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Source</th><th>Date</th><th>Nominal</th><th>Description</th><th>Amount</th><th>Status</th><th>Schedule</th></tr></thead>
                        <tbody>' . $rowsHtml . '</tbody>
                    </table>
                </div>
            </div>
            ' . $carriedHtml . '
        </section>';
    }

    private function historicalCorrectionHtml(
        array $context,
        int $companyId,
        int $accountingPeriodId,
        array $settings,
        bool $isLocked
    ): string {
        if (empty($context['available'])) {
            return '';
        }
        $repair = (array)($context['repair'] ?? []);
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
        $repairHtml = '';
        if ($missingRows !== '') {
            $button = $isLocked ? '' : '<form method="post" data-ajax="true" class="actions-row">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Prepayments">
                <input type="hidden" name="intent" value="recalculate_schedule">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <button class="button" type="submit">Recalculate schedule</button>
            </form>';
            $repairHtml = '<div class="panel-soft warn"><h4 class="card-title">Saved prepayments missing automated schedules</h4>
                <p class="helper">This read-only preview reconstructs the schedule from the posted source and saved service dates. Recalculation creates append-only schedule snapshots only; it does not post journals.</p>
                <div class="table-scroll"><table><thead><tr><th>Review</th><th>Source</th><th>Date</th><th>Amount</th><th>Service dates</th><th>AP days</th><th>AP expense</th><th>Closing asset</th></tr></thead><tbody>'
                . $missingRows . '</tbody></table></div>' . $button . '</div>';
        }

        if (empty($context['companies_house_filed']) || empty($context['has_prepayment_work'])) {
            return $repairHtml;
        }
        $documents = [];
        foreach ((array)$context['companies_house_documents'] as $document) {
            $documents[] = trim((string)($document['filing_date'] ?? '')) . ' — '
                . trim((string)($document['filing_description'] ?? 'Filed accounts'))
                . ' (' . trim((string)($document['document_id'] ?? '')) . ')';
        }
        $hmrc = (array)($context['hmrc_filing'] ?? []);
        $hmrcState = (string)($hmrc['state'] ?? 'unknown');
        $approval = (array)($context['acknowledgement'] ?? []);
        $profitPence = (int)($context['expected_profit_change_pence'] ?? 0);
        $profitLabel = ($profitPence >= 0 ? '+' : '−') . $this->money($settings, abs($profitPence) / 100);
        $evidenceForm = '';
        if (!$isLocked && $hmrcState === 'unknown') {
            $evidenceForm = '<form method="post" data-ajax="true" class="form-grid">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Prepayments">
                <input type="hidden" name="intent" value="confirm_hmrc_filing_status">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <label>HMRC CT return status<select class="select" name="hmrc_filing_status" required><option value="">Select status</option><option value="not_filed">Not filed</option><option value="filed">Filed</option></select></label>
                <label>Evidence or submission reference<input class="input" name="hmrc_filing_reference" required></label>
                <label>Notes<input class="input" name="hmrc_filing_notes"></label>
                <div class="actions-row"><button class="button" type="submit">Record HMRC filing evidence</button></div>
            </form>';
        }
        $approvalForm = '';
        if (!$isLocked && $hmrcState !== 'unknown' && (int)($repair['missing_count'] ?? 0) === 0 && empty($approval['current'])) {
            $approvalForm = '<form method="post" data-ajax="true" class="form-grid">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Prepayments">
                <input type="hidden" name="intent" value="acknowledge_historical_correction">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <label>Correction note<input class="input" name="historical_correction_note"></label>
                <label class="checkbox-row"><input type="checkbox" name="historical_correction_confirmed" value="1" required> I reviewed the filed Companies House accounts, the current prepayment schedule and the recorded HMRC filing evidence.</label>
                <div class="actions-row"><button class="button" type="submit">Approve historical correction</button></div>
            </form>';
        }

        return $repairHtml . '<div class="panel-soft warn"><h4 class="card-title">Filed-period prepayment correction</h4>
            <p class="helper">Companies House accounts already exist for this period. Prepayment journals remain blocked until the missing schedules, HMRC filing evidence and this hashed correction acknowledgement are current.</p>
            <dl class="definition-list"><dt>Filed accounts</dt><dd>' . HelperFramework::escape(implode('; ', $documents)) . '</dd>
            <dt>Expected accounting-profit change</dt><dd>' . HelperFramework::escape($profitLabel) . '</dd>
            <dt>HMRC CT status</dt><dd><span class="badge ' . ($hmrcState === 'unknown' ? 'warning' : 'info') . '">' . HelperFramework::escape(HelperFramework::labelFromKey($hmrcState, '_')) . '</span></dd>
            <dt>Correction approval</dt><dd><span class="badge ' . (!empty($approval['current']) ? 'success' : 'warning') . '">' . (!empty($approval['current']) ? 'Current' : 'Required') . '</span></dd></dl>'
            . $evidenceForm . $approvalForm . '</div>';
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
        $autosaveButtonClass = 'prepayment-autosave-' . preg_replace('/[^a-z0-9_-]/i', '-', $sourceType) . '-' . $sourceId;
        $sourceValid = !array_key_exists('source_valid', $item) || !empty($item['source_valid']);
        $schedule = is_array($review['schedule'] ?? null) ? (array)$review['schedule'] : null;
        $scheduleHtml = $this->scheduleHtml($schedule, $review, $accountingPeriodId, $companySettings, $companyId, $isLocked);

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
                    <select class="select" id="' . HelperFramework::escape($formId) . '-status" name="prepayment_status" data-autosave-submit-target=".' . HelperFramework::escape($autosaveButtonClass) . '" data-initial-value="' . HelperFramework::escape($status) . '">' . $this->statusOptions($status) . '</select>
                    <span class="prepayment-date-actions" data-visible-when-field="prepayment_status" data-visible-when-value="prepaid"' . ($status === 'prepaid' ? '' : ' hidden aria-hidden="true"') . '>
                        <label class="prepayment-date-field" for="' . HelperFramework::escape($formId) . '-service-start-date">Service start
                            <input class="input" id="' . HelperFramework::escape($formId) . '-service-start-date" type="date" name="service_start_date" value="' . HelperFramework::escape($serviceStart) . '" data-autosave-submit-target=".' . HelperFramework::escape($autosaveButtonClass) . '" data-initial-value="' . HelperFramework::escape($serviceStart) . '" data-autosave-require-value="1">
                        </label>
                        <label class="prepayment-date-field" for="' . HelperFramework::escape($formId) . '-service-end-date">Service end
                            <input class="input" id="' . HelperFramework::escape($formId) . '-service-end-date" type="date" name="service_end_date" value="' . HelperFramework::escape($serviceEnd) . '" data-autosave-submit-target=".' . HelperFramework::escape($autosaveButtonClass) . '" data-initial-value="' . HelperFramework::escape($serviceEnd) . '" data-autosave-require-value="1">
                        </label>
                    </span>
                    <button class="' . HelperFramework::escape($autosaveButtonClass) . '" type="submit" hidden>Autosave decision</button>
                </form>') . '
            </td>
            <td>' . $scheduleHtml . '</td>
        </tr>';
    }

    private function scheduleHtml(?array $schedule, array $review, int $accountingPeriodId, array $settings, int $companyId, bool $isLocked): string
    {
        if ((string)($review['status'] ?? '') !== 'prepaid') {
            return '<span class="helper">Not applicable</span>';
        }
        if (!is_array($schedule)) {
            return '<span class="badge warning">Calculation required</span>';
        }

        $selected = null;
        $hasPostings = false;
        $allocationRows = '';
        foreach ((array)($schedule['allocations'] ?? []) as $allocation) {
            if ((int)$allocation['accounting_period_id'] === $accountingPeriodId) {
                $selected = $allocation;
            }
            if ((int)($allocation['posting_count'] ?? 0) > 0) {
                $hasPostings = true;
            }
            $allocationRows .= '<tr>
                <td>' . HelperFramework::escape($this->displayDate((string)$allocation['period_start']) . '–' . $this->displayDate((string)$allocation['period_end'])) . '</td>
                <td class="numeric">' . (int)$allocation['overlap_days'] . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, ((int)$allocation['expense_pence']) / 100)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, ((int)$allocation['closing_deferred_pence']) / 100)) . '</td>
            </tr>';
        }

        $summary = '';
        if (is_array($selected)) {
            $summary = '<div><strong>' . (int)$selected['overlap_days'] . '/' . (int)$schedule['total_days'] . ' days</strong></div>
                <div class="helper">Expense ' . HelperFramework::escape($this->money($settings, ((int)$selected['expense_pence']) / 100))
                . '; closing Prepayments ' . HelperFramework::escape($this->money($settings, ((int)$selected['closing_deferred_pence']) / 100)) . '.</div>';
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

        return $summary . $warning . '<details><summary>Full AP schedule</summary>
            <div class="table-scroll"><table><thead><tr><th>Accounting period</th><th>Days</th><th>Expense</th><th>Closing asset</th></tr></thead>
            <tbody>' . $allocationRows . '</tbody></table></div></details>' . $reopen;
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

        return '<div class="panel-soft"><h4 class="card-title">Carried-forward prepayment schedules</h4>
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
