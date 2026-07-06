<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _journal_cut_off_confirmationCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'journal_cut_off_confirmation';
    }

    public function title(): string
    {
        return 'Journal Cut-Off';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'cutOffJournals',
                'service' => \eel_accounts\Service\YearEndAdjustmentService::class,
                'method' => 'fetchContext',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
            [
                'key' => 'journalCutOffAcknowledgement',
                'service' => \eel_accounts\Service\YearEndChecklistService::class,
                'method' => 'fetchReviewAcknowledgement',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'checkCode' => 'cut_off_journals_review',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['year.end.state'];
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
        $data = (array)($context['services']['cutOffJournals'] ?? []);
        $acknowledgement = $context['services']['journalCutOffAcknowledgement'] ?? null;

        if (!is_array($acknowledgement)) {
            $acknowledgement = null;
        }

        return '<section class="settings-stack" id="journal-cut-off">
            ' . $this->renderPostedAdjustments((array)($data['adjustments'] ?? [])) . '
            ' . $this->acknowledgementHtml($acknowledgement, $companyId, $accountingPeriodId) . '
        </section>';
    }

    private function renderPostedAdjustments(array $adjustments): string
    {
        if ($adjustments === []) {
            return '<section class="panel-soft settings-stack"><h4 class="card-title">Posted cut-off journals</h4><div class="helper">No cut-off journals have been posted for this accounting period yet.</div></section>';
        }

        $rows = '';
        foreach ($adjustments as $adjustment) {
            $rows .= '<tr>
                <td>' . HelperFramework::escape(HelperFramework::displayDate((string)($adjustment['journal_date'] ?? ''))) . '</td>
                <td>' . HelperFramework::escape((string)($adjustment['description'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($adjustment['journal_tag'] ?? ''), '_')) . '</td>
                <td>' . count((array)($adjustment['lines'] ?? [])) . '</td>
            </tr>';
        }

        return '<section class="panel-soft settings-stack"><h4 class="card-title">Posted cut-off journals</h4><div class="table-scroll"><table><thead><tr><th>Date</th><th>Description</th><th>Type</th><th>Lines</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
    }

    private function acknowledgementHtml(?array $acknowledgement, int $companyId, int $accountingPeriodId): string
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return '<div class="helper">Select a company and accounting period before confirming journal cut-off review.</div>';
        }

        $acknowledged = $acknowledgement !== null;
        $acknowledgedAt = $acknowledged ? trim((string)($acknowledgement['acknowledged_at'] ?? '')) : '';
        $acknowledgedBy = $acknowledged ? trim((string)($acknowledgement['acknowledged_by'] ?? '')) : '';
        $note = $acknowledged ? trim((string)($acknowledgement['note'] ?? '')) : '';

        if (!$acknowledged) {
            return '<section class="panel-soft warn full settings-stack">
                <div class="eyebrow">Acknowledgement</div>
                <form method="post" data-ajax="true" class="form-grid">
                    <input type="hidden" name="card_action" value="YearEnd">
                    <input type="hidden" name="intent" value="acknowledge_review_check">
                    <input type="hidden" name="company_id" value="' . $companyId . '">
                    <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                    <input type="hidden" name="check_code" value="cut_off_journals_review">
                    <div class="helper full">Confirm this after reviewing whether accruals, deferred income, prepayments, or other year-end cut-off journals are required.</div>
                    <div class="form-row full">
                        <label for="journal-cut-off-review-note">Confirmation notes</label>
                        <textarea class="input" id="journal-cut-off-review-note" name="review_acknowledgement_note" rows="3"></textarea>
                    </div>
                    <div class="actions-row"><button class="button primary" type="submit">Mark reviewed</button></div>
                </form>
            </section>';
        }

        $confirmationFoot = $this->confirmationFoot($acknowledgedAt, $acknowledgedBy);

        return '<section class="panel-soft success settings-stack">
            <div class="eyebrow">Acknowledgement</div>
            ' . ($note !== '' ? '<div class="summary-value">' . HelperFramework::escape($note) . '</div>' : '') . '
            ' . ($confirmationFoot !== '' ? '<div class="stat-foot">' . HelperFramework::escape($confirmationFoot) . '</div>' : '') . '
            <div class="actions-row">
                <div class="year-end-related-workflow">
                    <form method="post" data-ajax="true">
                        <input type="hidden" name="card_action" value="YearEnd">
                        <input type="hidden" name="intent" value="reopen_review_check">
                        <input type="hidden" name="company_id" value="' . $companyId . '">
                        <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                        <input type="hidden" name="check_code" value="cut_off_journals_review">
                        <button class="button" type="submit">Reopen review</button>
                    </form>
                </div>
            </div>
        </section>';
    }

    private function confirmationFoot(string $confirmedAt, string $confirmedBy): string
    {
        if ($confirmedAt === '' && $confirmedBy === '') {
            return '';
        }

        return 'Reviewed'
            . ($confirmedAt !== '' ? ' at ' . $confirmedAt : '')
            . ($confirmedBy !== '' ? ' by ' . $confirmedBy : '')
            . '.';
    }
}
