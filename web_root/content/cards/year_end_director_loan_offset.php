<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_director_loan_offsetCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'year_end_director_loan_offset';
    }

    public function title(): string
    {
        return 'Director Loan Offset';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'directorLoanOffset',
                'service' => \eel_accounts\Service\DirectorLoanReconciliationService::class,
                'method' => 'fetchYearEndConfirmationContext',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['year.end.state', 'year.end.checklist'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $offset = (array)($context['services']['directorLoanOffset'] ?? []);
        $taxReview = (array)($offset['tax_review'] ?? ($context['services']['directorLoanTaxReview'] ?? []));
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriod = (array)($offset['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));

        if (empty($offset['available'])) {
            return '<section class="settings-stack" id="director-loan-offset">' . $this->renderErrors((array)($offset['errors'] ?? ['Director loan offset review is not available.'])) . '</section>';
        }

        $assetNominal = (array)($offset['asset_nominal'] ?? []);
        $liabilityNominal = (array)($offset['liability_nominal'] ?? []);
        $companySettings = (array)($company['settings'] ?? []);
        $warningsHtml = '';
        foreach ((array)($offset['warnings'] ?? []) as $warning) {
            $warningsHtml .= '<div class="helper">' . HelperFramework::escape((string)$warning) . '</div>';
        }

        $status = (string)($offset['offset_status'] ?? '');
        $acknowledged = !empty($offset['closing_balance_acknowledged']);
        $offsetCandidateAvailable = array_key_exists('offset_candidate_available', $offset)
            ? !empty($offset['offset_candidate_available'])
            : !empty($offset['can_post']);
        $showSetOffEvidence = $offsetCandidateAvailable
            || !empty($offset['set_off_evidence_acknowledgement'])
            || !empty($offset['existing_offset_journal']);
        $acknowledgementForm = $offsetCandidateAvailable
            ? \eel_accounts\Renderer\YearEndApprovalRenderer::render([
                'subject' => 'director loan offset',
                'companyId' => $companyId,
                'accountingPeriodId' => $accountingPeriodId,
                'acknowledged' => $acknowledged,
                'acknowledgementState' => (string)($offset['closing_balance_acknowledgement_state'] ?? ''),
                'acknowledgedAt' => (string)($offset['closing_balance_acknowledged_at'] ?? ''),
                'acknowledgedBy' => (string)($offset['closing_balance_acknowledged_by'] ?? ''),
                'note' => (string)($offset['director_loan_closing_approval_note'] ?? ''),
                'intent' => 'save_director_loan_offset_acknowledgement',
                'revokeIntent' => 'save_director_loan_offset_acknowledgement',
                'checkboxName' => 'director_loan_offset_acknowledgement',
                'approveFields' => ['director_loan_offset_acknowledgement' => '1'],
                'revokeFields' => ['director_loan_offset_acknowledgement' => '0'],
            ])
            : '';
        $setOffEvidenceHtml = $showSetOffEvidence
            ? $this->setOffEvidenceHtml($offset, $companyId, $accountingPeriodId)
            : '';
        $proposedLinesHtml = $this->proposedLinesHtml(
            $offset,
            $assetNominal,
            $liabilityNominal,
            $companySettings
        );

        return '<section class="settings-stack" id="director-loan-offset">
            <div class="status-head">
                <span class="badge ' . HelperFramework::escape($this->badgeClass($this->offsetBadgeStatus($status))) . '">' . HelperFramework::escape((string)($offset['offset_status_label'] ?? HelperFramework::labelFromKey($status, '_'))) . '</span>
            </div>
            <div class="month-grid">
                ' . $this->summaryCard(FormattingFramework::nominalLabel($assetNominal), $this->money($companySettings, $offset['asset_receivable'] ?? 0)) . '
                ' . $this->summaryCard(FormattingFramework::nominalLabel($liabilityNominal), $this->money($companySettings, $offset['liability_payable'] ?? 0)) . '
                ' . $this->summaryCard('Pending adjustment', $this->money($companySettings, $offset['pending_adjustment_amount'] ?? $offset['offset_amount'] ?? 0)) . '
                ' . $this->summaryCard('Net position', $this->money($companySettings, $offset['net_position'] ?? 0)) . '
                ' . $this->summaryCard('Net Flow', (string)($offset['net_position_label'] ?? '')) . '
                ' . $this->summaryCard('Existing posted offset', $this->money($companySettings, $offset['posted_offset_amount'] ?? 0)) . '
            </div>
            <div class="table-scroll panel-soft">
                <table>
                    <thead><tr><th>Journal line</th><th>Debit</th><th>Credit</th></tr></thead>
                    <tbody>' . $proposedLinesHtml . '</tbody>
                </table>
            </div>
            <div class="helper">FRS 105 presentation remains gross unless both set-off criteria are evidenced for the current closing balances.</div>
            ' . $warningsHtml . '
            ' . $this->taxReviewHtml($taxReview, $companySettings, $companyId, $accountingPeriodId) . '
            ' . (empty($offset['can_post']) ? '<div class="helper">' . HelperFramework::escape((string)($offset['post_blocked_reason'] ?? '')) . '</div>' : '') . '
            ' . $acknowledgementForm . '
            ' . $setOffEvidenceHtml . '
        </section>';
    }

    private function setOffEvidenceHtml(array $offset, int $companyId, int $accountingPeriodId): string
    {
        $current = !empty($offset['set_off_evidence_current']);
        $acknowledgement = (array)($offset['set_off_evidence_acknowledgement'] ?? []);
        $note = trim((string)($offset['set_off_evidence_note'] ?? $acknowledgement['note'] ?? ''));
        $locked = (new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId);
        $existingOffsetJournal = (array)($offset['existing_offset_journal'] ?? []);
        $offsetJournalPosted = array_key_exists('offset_journal_posted', $offset)
            ? !empty($offset['offset_journal_posted'])
            : (
                !empty($offset['current_offset_journal_posted'])
                || (
                    (int)($existingOffsetJournal['id'] ?? 0) > 0
                    && (int)($existingOffsetJournal['is_posted'] ?? 0) === 1
                )
            );
        $commonFields = '
            <input type="hidden" name="card_action" value="YearEnd">
            <input type="hidden" name="intent" value="save_director_loan_set_off_evidence">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">';

        if ($current) {
            return '<section class="panel-soft success settings-stack">
                <div class="eyebrow">FRS 105 set-off evidence</div>
                <div class="summary-value">' . HelperFramework::escape($note) . '</div>
                <div class="helper">Legally enforceable right confirmed. Net or simultaneous settlement intent confirmed.</div>
                <div class="stat-foot">Approved'
                    . (trim((string)($offset['set_off_evidence_acknowledged_at'] ?? '')) !== ''
                        ? ' at ' . HelperFramework::escape((string)$offset['set_off_evidence_acknowledged_at'])
                        : '')
                    . (trim((string)($offset['set_off_evidence_acknowledged_by'] ?? '')) !== ''
                        ? ' by ' . HelperFramework::escape((string)$offset['set_off_evidence_acknowledged_by'])
                        : '')
                    . '.</div>'
                . ($locked
                    ? '<div class="helper">This accounting period is locked, so this evidence cannot be revoked.</div>'
                    : ($offsetJournalPosted
                        ? '<div class="helper">This evidence cannot be revoked while a director loan offset journal remains posted. Reverse the journal first.</div>'
                        : '
                <form method="post" data-ajax="true">
                    ' . $commonFields . '
                    <input type="hidden" name="director_loan_set_off_evidence" value="0">
                    <button class="button" type="submit">Revoke set-off evidence</button>
                </form>')) . '
            </section>';
        }

        $state = (string)($offset['set_off_evidence_state'] ?? 'absent');
        $staleHtml = in_array($state, ['stale', 'unverifiable'], true)
            ? '<div class="helper"><span class="badge warning">Review required</span> The balances changed after the previous set-off evidence was recorded.</div>'
            : '';

        return '<section class="panel-soft warn settings-stack">
            <div class="eyebrow">FRS 105 set-off evidence</div>
            ' . $staleHtml . '
            <form method="post" data-ajax="true" class="form-grid">
                ' . $commonFields . '
                <input type="hidden" name="director_loan_set_off_evidence" value="1">
                <label class="checkbox-row full">
                    <input type="checkbox" name="director_loan_legally_enforceable_right" value="1" required' . ($locked ? ' disabled' : '') . '>
                    <span>I confirm the company currently has a legally enforceable right to set off these recognised balances.</span>
                </label>
                <label class="checkbox-row full">
                    <input type="checkbox" name="director_loan_net_settlement_intent" value="1" required' . ($locked ? ' disabled' : '') . '>
                    <span>I confirm the company intends to settle the balances net, or to realise the asset and settle the liability simultaneously.</span>
                </label>
                <div class="form-row full">
                    <label for="director-loan-set-off-evidence-note">Supporting evidence</label>
                    <textarea class="input" id="director-loan-set-off-evidence-note" name="director_loan_set_off_evidence_note" rows="3" required' . ($locked ? ' disabled' : '') . '></textarea>
                    <div class="helper">Identify the agreement, legal right and intended settlement arrangement supporting the set-off.</div>
                </div>
                <div class="actions-row"><button class="button primary" type="submit"' . ($locked ? ' disabled' : '') . '>Save set-off evidence</button></div>
            </form>
        </section>';
    }

    private function summaryCard(string $label, string $value): string
    {
        return '<div class="panel-soft"><div class="eyebrow">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function proposedLinesHtml(
        array $offset,
        array $assetNominal,
        array $liabilityNominal,
        array $companySettings
    ): string {
        $lines = (array)($offset['proposed_lines'] ?? []);
        if ($lines === []) {
            $pendingAdjustment = round((float)($offset['pending_adjustment_amount'] ?? $offset['offset_amount'] ?? 0), 2);
            $amount = abs($pendingAdjustment);
            $applyingSetOff = $pendingAdjustment >= 0;
            $lines = [
                [
                    'nominal_account_id' => (int)($applyingSetOff ? ($liabilityNominal['id'] ?? 0) : ($assetNominal['id'] ?? 0)),
                    'debit' => $amount,
                    'credit' => 0.0,
                ],
                [
                    'nominal_account_id' => (int)($applyingSetOff ? ($assetNominal['id'] ?? 0) : ($liabilityNominal['id'] ?? 0)),
                    'debit' => 0.0,
                    'credit' => $amount,
                ],
            ];
        }

        $nominals = [
            (int)($assetNominal['id'] ?? 0) => $assetNominal,
            (int)($liabilityNominal['id'] ?? 0) => $liabilityNominal,
        ];
        $html = '';
        foreach ($lines as $line) {
            $nominal = (array)($nominals[(int)($line['nominal_account_id'] ?? 0)] ?? []);
            $html .= '<tr><td>'
                . HelperFramework::escape(FormattingFramework::nominalLabel($nominal))
                . '</td><td>'
                . HelperFramework::escape($this->money($companySettings, $line['debit'] ?? 0))
                . '</td><td>'
                . HelperFramework::escape($this->money($companySettings, $line['credit'] ?? 0))
                . '</td></tr>';
        }

        return $html;
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function taxReviewHtml(array $taxReview, array $companySettings, int $companyId, int $accountingPeriodId): string
    {
        if (empty($taxReview['available'])) {
            $errors = (array)($taxReview['errors'] ?? []);
            return '<div class="panel-soft stack"><div class="eyebrow">Tax Review</div><div class="helper">' . HelperFramework::escape((string)($errors[0] ?? 'Director loan tax review is not available.')) . '</div></div>';
        }

        $status = (string)($taxReview['status'] ?? '');
        $itemsHtml = '';
        foreach ((array)($taxReview['review_items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemsHtml .= '<li><strong>' . HelperFramework::escape((string)($item['label'] ?? 'Review item')) . '</strong><br><span class="helper">' . HelperFramework::escape((string)($item['detail'] ?? '')) . '</span></li>';
        }

        if ($itemsHtml === '') {
            $itemsHtml = '<li><span class="helper">No director receivable tax review flags are currently raised for this period.</span></li>';
        }

        $repaymentDate = trim((string)($taxReview['repayment_review_date'] ?? ''));
        $acknowledgement = (array)($taxReview['acknowledgement'] ?? []);
        $approvalHtml = !empty($taxReview['review_required']) || $acknowledgement !== []
            ? \eel_accounts\Renderer\YearEndApprovalRenderer::render([
                'subject' => 'director loan tax position',
                'companyId' => $companyId,
                'accountingPeriodId' => $accountingPeriodId,
                'acknowledged' => !empty($taxReview['acknowledgement_current']),
                'acknowledgementState' => (string)($taxReview['acknowledgement_state'] ?? 'absent'),
                'acknowledgedAt' => (string)($acknowledgement['acknowledged_at'] ?? ''),
                'acknowledgedBy' => (string)($acknowledgement['acknowledged_by'] ?? ''),
                'note' => (string)($acknowledgement['note'] ?? ''),
                'intent' => 'acknowledge_review_check',
                'revokeIntent' => 'reopen_review_check',
                'approveFields' => ['check_code' => 'director_loan_tax_review'],
                'revokeFields' => ['check_code' => 'director_loan_tax_review'],
                'noteName' => 'review_acknowledgement_note',
                'noteId' => 'director-loan-tax-review-note',
            ])
            : '';

        return '<div class="panel-soft stack">
            <div class="status-head">
                <span class="badge ' . HelperFramework::escape($this->badgeClass($status === 'review_required' ? 'warning' : 'pass')) . '">' . HelperFramework::escape((string)($taxReview['status_label'] ?? HelperFramework::labelFromKey($status, '_'))) . '</span>
            </div>
            <div class="month-grid">
                ' . $this->summaryCard('Potential s455 exposure basis', $this->money($companySettings, $taxReview['exposure_amount'] ?? 0)) . '
                ' . $this->summaryCard('Repayment review date', $repaymentDate !== '' ? HelperFramework::displayDate($repaymentDate) : 'Not applicable') . '
            </div>
            <ul class="settings-list">' . $itemsHtml . '</ul>
            ' . $approvalHtml . '
        </div>';
    }

    private function offsetBadgeStatus(string $status): string
    {
        return match ($status) {
            'current', 'gross_presentation', 'not_required' => 'pass',
            'missing', 'stale', 'invalid' => 'warning',
            default => 'info',
        };
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'pass', 'ready', 'locked' => 'success',
            'fail', 'needs_attention' => 'danger',
            'warning', 'not_started' => 'warning',
            default => 'info',
        };
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
