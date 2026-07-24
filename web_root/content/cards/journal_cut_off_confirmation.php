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
            [
                'key' => 'sectionReview',
                'service' => \eel_accounts\Service\YearEndSectionApprovalService::class,
                'method' => 'fetchReview',
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
        return ['cut.off.journals', 'page.context', 'year.end.state', 'year.end.checklist'];
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
        $sectionReview = (array)($context['services']['sectionReview'] ?? []);
        $access = (array)($review['access'] ?? []);

        return '<section class="settings-stack" id="journal-cut-off">
            ' . $this->renderPostedAdjustments((array)($data['adjustments'] ?? [])) . '
            ' . $this->renderManualEntries((array)($data['adjustments'] ?? [])) . '
            ' . $this->acknowledgementHtml($sectionReview, $companyId, $accountingPeriodId, $access) . '
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
        array $review,
        int $companyId,
        int $accountingPeriodId,
        array $access
    ): string
    {
        $acknowledgement = (array)($review['acknowledgement'] ?? []);
        return \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'journal cut-off position',
            'companyId' => $companyId,
            'accountingPeriodId' => $accountingPeriodId,
            'acknowledged' => !empty($review['acknowledgement_current']),
            'acknowledgementState' => (string)($review['acknowledgement_state'] ?? 'absent'),
            'acknowledgedAt' => (string)($acknowledgement['acknowledged_at'] ?? ''),
            'acknowledgedBy' => (string)($acknowledgement['acknowledged_by'] ?? ''),
            'note' => (string)($acknowledgement['note'] ?? ''),
            'locked' => !empty($access['is_locked']),
            'intent' => 'approve_section_review',
            'revokeIntent' => 'revoke_section_review',
            'approveFields' => ['check_code' => 'cut_off_journals_review'],
            'revokeFields' => ['check_code' => 'cut_off_journals_review'],
            'noteName' => 'approval_note',
            'noteId' => 'journal-cut-off-review-note',
            'questions' => (array)($review['questions'] ?? []),
            'answers' => (array)($review['answers'] ?? []),
            'disabled' => empty($review['can_approve']),
            'disabledReason' => (string)(($review['approval_errors'] ?? [])[0] ?? ''),
            'missingContextHtml' => '<div class="helper">Select a company and accounting period before approving journal cut-off review.</div>',
        ]);
    }
}
