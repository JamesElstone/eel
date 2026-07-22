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
                'key' => 'journalCutOffReview',
                'service' => \eel_accounts\Service\JournalCutOffReviewService::class,
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
        return ['page.context', 'year.end.state', 'year.end.checklist'];
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
        $review = (array)($context['services']['journalCutOffReview'] ?? []);
        $acknowledgement = $review['acknowledgement'] ?? null;
        $access = (array)($review['access'] ?? []);

        if (!is_array($acknowledgement)) {
            $acknowledgement = null;
        }

        return '<section class="settings-stack" id="journal-cut-off">
            ' . $this->renderPostedAdjustments((array)($data['adjustments'] ?? [])) . '
            ' . $this->renderManualEntries((array)($data['adjustments'] ?? [])) . '
            ' . $this->acknowledgementHtml($acknowledgement, $companyId, $accountingPeriodId, $access) . '
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

    private function renderManualEntries(array $adjustments): string
    {
        $rows = '';
        foreach ($adjustments as $journal) {
            if (!str_starts_with((string)($journal['journal_key'] ?? ''), 'manual-cutoff-')) continue;
            $rows .= '<tr><td>' . HelperFramework::escape(HelperFramework::displayDate((string)($journal['journal_date'] ?? ''))) . '</td><td>' . HelperFramework::escape((string)($journal['description'] ?? '')) . '</td><td>' . count((array)($journal['lines'] ?? [])) . '</td></tr>';
        }

        return '<section class="panel-soft settings-stack"><h4 class="card-title">Posted Manual Journal Entries</h4>' . ($rows !== ''
            ? '<div class="table-scroll"><table><thead><tr><th>Date</th><th>Description</th><th>Lines</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            : '<div class="helper">No manual journal entries have been posted for this accounting period yet.</div>') . '</section>';
    }

    private function acknowledgementHtml(
        ?array $acknowledgement,
        int $companyId,
        int $accountingPeriodId,
        array $access
    ): string
    {
        $acknowledged = !empty($acknowledgement['current']);
        return \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'journal cut-off position',
            'companyId' => $companyId,
            'accountingPeriodId' => $accountingPeriodId,
            'acknowledged' => $acknowledged,
            'acknowledgementState' => (string)($acknowledgement['state'] ?? ''),
            'acknowledgedAt' => (string)($acknowledgement['acknowledged_at'] ?? ''),
            'acknowledgedBy' => (string)($acknowledgement['acknowledged_by'] ?? ''),
            'note' => (string)($acknowledgement['note'] ?? ''),
            'locked' => !empty($access['is_locked']),
            'intent' => 'acknowledge_review_check',
            'revokeIntent' => 'reopen_review_check',
            'approveFields' => ['check_code' => 'cut_off_journals_review'],
            'revokeFields' => ['check_code' => 'cut_off_journals_review'],
            'noteName' => 'review_acknowledgement_note',
            'noteId' => 'journal-cut-off-review-note',
            'missingContextHtml' => '<div class="helper">Select a company and accounting period before approving journal cut-off review.</div>',
        ]);
    }
}
