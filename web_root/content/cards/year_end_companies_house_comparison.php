<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_companies_house_comparisonCard extends CardBaseFramework
{
    private const CHECK_CODE = 'companies_house_mismatch_acknowledgement';

    public function key(): string
    {
        return 'year_end_companies_house_comparison';
    }

    public function title(): string
    {
        return 'Year End Companies House Comparison';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['companies.house.accounts.submission', 'page.context', 'year.end.checklist'];
    }

    public function services(): array
    {
        return [
            [
                'key' => 'companiesHouseComparisonReview',
                'service' => \eel_accounts\Service\CompaniesHouseComparisonReviewService::class,
                'method' => 'fetchContext',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
            [
                'key' => 'companiesHouseAccountsFiling',
                'service' => \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                'method' => 'fetchContext',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $review = (array)($context['services']['companiesHouseComparisonReview'] ?? []);
        $comparison = (array)($review['comparison'] ?? []);
        $access = (array)($review['access'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $companySettings = (array)($company['settings'] ?? []);
        $acknowledgement = is_array($review['acknowledgement'] ?? null)
            ? $review['acknowledgement']
            : null;
        $mismatchCount = (int)($review['mismatch_count'] ?? 0);
        $filing = (array)($context['services']['companiesHouseAccountsFiling'] ?? []);

        return '<section class="settings-stack" id="year-end-companies-house-comparison">
            ' . $this->renderAccountsFilingPanel($companyId, $accountingPeriodId, $filing, $access) . '
            ' . $this->renderComparisonPanel($comparison, $companySettings) . '
            ' . $this->renderAcknowledgementPanel($companyId, $accountingPeriodId, $comparison, $acknowledgement, $access, $mismatchCount, $review) . '
        </section>';
    }

    private function renderAccountsFilingPanel(
        int $companyId,
        int $accountingPeriodId,
        array $filing,
        array $comparisonAccess
    ): string {
        if ($filing === []) {
            return $this->filingPanel(
                'Unavailable',
                'muted',
                '<div class="helper">The revised-accounts filing context is not available.</div>'
            );
        }

        $locked = array_key_exists('locked', $filing)
            ? !empty($filing['locked'])
            : !empty($comparisonAccess['is_locked']);
        $feature = (array)($filing['feature'] ?? []);
        $mode = strtoupper(trim((string)($feature['mode'] ?? 'DISABLED')));
        $eligibility = (array)($filing['eligibility'] ?? []);
        $decision = strtolower(trim((string)($eligibility['decision'] ?? 'pending')));
        $submission = is_array($filing['submission'] ?? null) ? $filing['submission'] : null;
        $status = $this->submissionStatus($submission);

        $summary = '<div class="summary-grid four">'
            . $this->metric('Accounting period', $this->periodLabel((array)($filing['accounting_period'] ?? [])))
            . $this->metric('Original filing document', $this->originalDocumentLabel($eligibility))
            . $this->metric('Detected filing channel', HelperFramework::labelFromKey((string)($eligibility['detected_channel'] ?? $eligibility['original_filing_channel'] ?? 'unknown'), '_'))
            . $this->metric('Gateway mode', $mode)
            . '</div>';

        if (!$locked) {
            return $this->filingPanel(
                'Year End not locked',
                'warning',
                $summary . '<div class="helper">Lock Year End before preparing or filing revised accounts.</div>'
            );
        }

        if ($decision === 'ineligible') {
            return $this->filingPanel(
                'Electronic filing unavailable',
                'danger',
                $summary
                    . $this->eligibilityEvidence($eligibility)
                    . '<div class="helper">Companies House has marked this filing as ineligible for electronic revision. Use the paper amendment route.</div>'
            );
        }

        if ($decision !== 'eligible') {
            return $this->filingPanel(
                'Eligibility confirmation required',
                'warning',
                $summary
                    . '<div class="helper">Record Companies House&rsquo;s written decision for this exact original filing before preparing revised accounts.</div>'
                    . $this->eligibilityForm($companyId, $accountingPeriodId, $eligibility, $locked)
            );
        }

        $summary .= $this->eligibilityEvidence($eligibility);

        if ($status === 'accepted') {
            return $this->filingPanel(
                'Accepted',
                'success',
                $summary . $this->submissionSummary($submission)
                    . '<div class="helper">Companies House accepted the revised accounts. The comparison will update after the revised filing appears on the public register.</div>'
            );
        }

        if (in_array($status, ['submitting', 'pending', 'parked', 'transport_unknown'], true)) {
            $detail = $status === 'transport_unknown'
                ? 'The submission outcome is uncertain. Refresh this submission; do not send a duplicate.'
                : 'Companies House has not issued a terminal response. Refresh this existing submission rather than sending another copy.';

            return $this->filingPanel(
                HelperFramework::labelFromKey($status, '_'),
                in_array($status, ['submitting', 'pending'], true) ? 'info' : 'warning',
                $summary . $this->submissionSummary($submission)
                    . '<div class="helper">' . HelperFramework::escape($detail) . '</div>'
                    . $this->refreshForm($companyId, $accountingPeriodId, (int)($submission['id'] ?? 0), $locked)
            );
        }

        if (in_array($status, ['rejected', 'reject', 'internal_failure', 'failed'], true)) {
            return $this->filingPanel(
                match ($status) {
                    'internal_failure' => 'Companies House internal failure',
                    'failed' => 'Submission failed',
                    default => 'Submission rejected',
                },
                'danger',
                $summary . $this->submissionSummary($submission)
                    . $this->messageList($this->submissionErrors($submission), 'Companies House response')
                    . '<div class="helper">Correct the reported issue and prepare a new submission. Do not reuse the rejected submission number.</div>'
                    . (!empty($filing['can_prepare'])
                        ? $this->prepareForm($companyId, $accountingPeriodId, $eligibility, $locked)
                        : $this->messageList($this->blockers($filing), 'Preparation blockers'))
            );
        }

        $preparedArtifact = is_array($filing['prepared_artifact'] ?? null)
            ? $filing['prepared_artifact']
            : (array)($submission['artifact'] ?? []);
        if ($status === 'prepared' || $preparedArtifact !== []) {
            return $this->filingPanel(
                'Prepared for submission',
                !empty($filing['can_submit']) ? 'success' : 'warning',
                $summary
                    . $this->submissionSummary($submission)
                    . $this->artifactSummary($preparedArtifact)
                    . $this->messageList($this->blockers($filing), 'Submission blockers')
                    . (!empty($filing['can_submit'])
                        ? $this->submitForm($companyId, $accountingPeriodId, (int)($submission['id'] ?? 0), $mode, $locked)
                        : '')
            );
        }

        $blockers = $this->blockers($filing);
        if (empty($filing['can_prepare'])) {
            return $this->filingPanel(
                !empty(($filing['readiness'] ?? [])['ready_for_filing']) ? 'Filing disabled' : 'Accounts not ready',
                'warning',
                $summary . $this->messageList($blockers, 'Preparation blockers')
            );
        }

        return $this->filingPanel(
            'Ready to prepare',
            'success',
            $summary
                . '<div class="helper">Preparing creates an immutable revised-accounts artifact for review. It does not submit anything to Companies House.</div>'
                . $this->prepareForm($companyId, $accountingPeriodId, $eligibility, $locked)
        );
    }

    private function renderComparisonPanel(array $comparison, array $companySettings): string
    {
        if (empty($comparison['available'])) {
            return $this->panel('Companies House Comparison', $this->renderErrors((array)($comparison['errors'] ?? ['No Companies House comparison is available.'])));
        }

        $warningsHtml = '';
        foreach ((array)($comparison['warnings'] ?? []) as $warning) {
            $warningsHtml .= '<div class="helper">' . HelperFramework::escape((string)$warning) . '</div>';
        }

        $rowsHtml = '';
        foreach ((array)($comparison['rows'] ?? []) as $row) {
            $status = (string)($row['status'] ?? '');
            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['label'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($this->nullableMoney($companySettings, $row['app_value'] ?? null)) . '</td>
                <td>' . HelperFramework::escape($this->nullableMoney($companySettings, $row['filed_value'] ?? null)) . '</td>
                <td>' . HelperFramework::escape($this->nullableMoney($companySettings, $row['variance'] ?? null)) . '</td>
                <td><span class="badge ' . $this->badgeClass($status) . '">' . HelperFramework::escape(HelperFramework::labelFromKey($status, '_')) . '</span></td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="5">No Companies House comparison rows were found for this accounting period.</td></tr>';
        }

        return '<section class="panel-soft" id="companies-house-comparison">
            <div class="status-head"><h3 class="card-title">Companies House Comparison</h3></div>
            <div class="helper">' . HelperFramework::escape((string)($comparison['comparison_note'] ?? '')) . '</div>
            ' . $warningsHtml . '
            <div class="helper">Stored filing date: ' . HelperFramework::escape((string)($comparison['filing']['filing_date'] ?? '')) . '</div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Metric</th><th>App</th><th>Filed</th><th>Variance</th><th>Status</th></tr></thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>
        </section>';
    }

    private function renderAcknowledgementPanel(
        int $companyId,
        int $accountingPeriodId,
        array $comparison,
        ?array $acknowledgement,
        array $access,
        int $mismatchCount,
        array $review
    ): string {
        if (empty($comparison['available'])) {
            return $this->panel('Approval', '<div class="helper">A Companies House filing must be available before a mismatch can be approved.</div>');
        }

        if ($mismatchCount <= 0) {
            return $this->panel('Approval', '<div class="helper">No Companies House mismatch approval is needed for this accounting period.</div>');
        }

        $isAcknowledged = !empty($acknowledgement['current']);
        $disabledReason = (string)($review['acknowledgement_blocked_reason'] ?? '');
        $form = \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'Companies House comparison',
            'companyId' => $companyId,
            'accountingPeriodId' => $accountingPeriodId,
            'locked' => !empty($access['is_locked']),
            'disabled' => empty($review['can_acknowledge']),
            'disabledReason' => $disabledReason,
            'acknowledged' => $isAcknowledged,
            'acknowledgementState' => (string)($acknowledgement['state'] ?? ''),
            'acknowledgedAt' => (string)($acknowledgement['acknowledged_at'] ?? ''),
            'acknowledgedBy' => (string)($acknowledgement['acknowledged_by'] ?? ''),
            'note' => (string)($acknowledgement['note'] ?? ''),
            'intent' => 'acknowledge_review_check',
            'revokeIntent' => 'reopen_review_check',
            'checkboxName' => 'companies_house_mismatch_acknowledgement',
            'approveFields' => ['check_code' => self::CHECK_CODE],
            'revokeFields' => ['check_code' => self::CHECK_CODE],
            'noteName' => 'review_acknowledgement_note',
            'noteId' => 'companies-house-mismatch-note',
        ]);

        return '<section class="settings-stack" id="companies-house-mismatch-acknowledgement">
            <div class="status-head">
                <h3 class="card-title">Approval</h3>
                <span class="badge ' . HelperFramework::escape($isAcknowledged ? 'success' : 'warning') . '">' . HelperFramework::escape($isAcknowledged ? 'Approved' : 'Approval pending') . '</span>
            </div>
            ' . $form . '
        </section>';
    }

    private function eligibilityForm(int $companyId, int $accountingPeriodId, array $eligibility, bool $locked = false): string
    {
        $originalDocumentId = (int)($eligibility['original_document_id'] ?? 0);
        $disabled = $locked ? ' disabled' : '';

        return '<form method="post" action="?page=companies_house" data-ajax="true" class="settings-stack">'
            . $this->actionHiddenFields($companyId, $accountingPeriodId, 'record_gateway_eligibility')
            . '<input type="hidden" name="original_document_id" value="' . $originalDocumentId . '">
                <label>Companies House decision
                    <select name="eligibility_decision" required data-no-submit-on-change="true"' . $disabled . '>
                        <option value="eligible">Eligible for XML revised-accounts filing</option>
                        <option value="ineligible">Ineligible — paper amendment required</option>
                    </select>
                </label>
                <label>Written evidence
                    <textarea name="eligibility_evidence" rows="5" required placeholder="Paste or summarise the Companies House response for this filing."' . $disabled . '></textarea>
                </label>
                <label>Companies House response reference (optional)
                    <input type="text" name="response_reference" maxlength="255"' . $disabled . '>
                </label>
                <button class="button" type="submit"' . $disabled . '>Record eligibility decision</button>
            </form>';
    }

    private function prepareForm(int $companyId, int $accountingPeriodId, array $eligibility, bool $locked = false): string
    {
        $disabled = $locked ? ' disabled' : '';
        return '<form method="post" action="?page=companies_house" data-ajax="true" class="settings-stack">'
            . $this->actionHiddenFields($companyId, $accountingPeriodId, 'prepare_revised_accounts')
            . '<input type="hidden" name="original_document_id" value="' . (int)($eligibility['original_document_id'] ?? 0) . '">
                <label>How the original accounts did not comply with the Companies Act 2006
                    <textarea name="non_compliance_explanation" rows="4" required' . $disabled . '></textarea>
                </label>
                <label>Significant amendments made
                    <textarea name="significant_amendments" rows="4" required' . $disabled . '></textarea>
                </label>
                <label>Revision approval date
                    <input type="date" name="revision_approval_date" required' . $disabled . '>
                </label>
                <label class="checkbox-row">
                    <input type="checkbox" name="original_software_filing_confirmed" value="1" required' . $disabled . '>
                    <span>I confirm that the recorded Companies House response applies to this exact original filing and accounting period.</span>
                </label>
                <button class="button primary" type="submit"' . $disabled . '>Prepare revised accounts</button>
            </form>';
    }

    private function submitForm(int $companyId, int $accountingPeriodId, int $submissionId, string $mode, bool $locked = false): string
    {
        if ($submissionId <= 0) {
            return '<div class="helper">The prepared submission record is missing. Prepare the revised accounts again before filing.</div>';
        }

        $disabled = $locked ? ' disabled' : '';
        $liveConfirmation = $mode === 'LIVE'
            ? '<label class="checkbox-row">
                    <input type="checkbox" name="authority_confirmed" value="1" required' . $disabled . '>
                    <span>I am authorised to file these revised statutory accounts for this company.</span>
                </label>
                <label>Type <strong>SUBMIT LIVE REVISED ACCOUNTS</strong> to confirm
                    <input type="text" name="live_confirmation_phrase" required autocomplete="off"' . $disabled . '>
                </label>'
            : '';

        return '<form method="post" action="?page=companies_house" data-ajax="true" class="settings-stack">'
            . $this->actionHiddenFields($companyId, $accountingPeriodId, 'submit_revised_accounts')
            . '<input type="hidden" name="submission_id" value="' . $submissionId . '">
                <label>Company authentication code
                    <input type="password" name="company_auth_code" minlength="6" maxlength="8" pattern="[A-Za-z0-9]{6,8}" required autocomplete="off"' . $disabled . '>
                </label>'
            . $liveConfirmation
            . '<button class="button danger" type="submit"' . $disabled . '>Submit ' . HelperFramework::escape($mode) . ' revised accounts</button>
            </form>';
    }

    private function refreshForm(int $companyId, int $accountingPeriodId, int $submissionId, bool $locked = false): string
    {
        if ($submissionId <= 0) {
            return '<div class="helper">The submission record could not be identified.</div>';
        }

        $disabled = $locked ? ' disabled' : '';
        return '<form method="post" action="?page=companies_house" data-ajax="true" class="actions-row">'
            . $this->actionHiddenFields($companyId, $accountingPeriodId, 'refresh_revised_accounts_status')
            . '<input type="hidden" name="submission_id" value="' . $submissionId . '">
                <button class="button" type="submit"' . $disabled . '>Refresh submission status</button>
            </form>';
    }

    private function actionHiddenFields(int $companyId, int $accountingPeriodId, string $intent): string
    {
        return HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="CompaniesHouseAccounts">'
            . '<input type="hidden" name="intent" value="' . HelperFramework::escape($intent) . '">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '">'
            . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">';
    }

    private function filingPanel(string $status, string $badgeClass, string $body): string
    {
        return '<section class="panel-soft" id="companies-house-revised-accounts-filing">
            <div class="status-head">
                <h3 class="card-title">Companies House Revised Accounts Filing</h3>
                <span class="badge ' . HelperFramework::escape($badgeClass) . '">' . HelperFramework::escape($status) . '</span>
            </div>' . $body . '
        </section>';
    }

    private function metric(string $label, string $value): string
    {
        $value = trim($value);

        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label)
            . '</div><div class="summary-value">' . HelperFramework::escape($value !== '' ? $value : '-') . '</div></div>';
    }

    private function periodLabel(array $period): string
    {
        $start = trim((string)($period['period_start'] ?? $period['start'] ?? ''));
        $end = trim((string)($period['period_end'] ?? $period['end'] ?? ''));
        if ($start !== '' && $end !== '') {
            return $start . ' to ' . $end;
        }

        return trim((string)($period['label'] ?? ''));
    }

    private function originalDocumentLabel(array $eligibility): string
    {
        $documentId = (int)($eligibility['original_document_id'] ?? 0);
        $transactionId = trim((string)($eligibility['original_transaction_id'] ?? ''));

        if ($documentId <= 0) {
            return 'Not identified';
        }

        return '#' . $documentId . ($transactionId !== '' ? ' / ' . $transactionId : '');
    }

    private function eligibilityEvidence(array $eligibility): string
    {
        $evidence = $eligibility['evidence'] ?? $eligibility['evidence_text'] ?? [];
        $reference = trim((string)(
            $eligibility['response_reference']
            ?? $eligibility['evidence_reference']
            ?? (is_array($evidence) ? ($evidence['reference'] ?? '') : '')
        ));
        if (is_array($evidence) && !array_is_list($evidence)) {
            $text = trim((string)($evidence['text'] ?? ''));
            $productionSoftware = trim((string)($evidence['production_software'] ?? ''));
            $filingDate = trim((string)($evidence['filing_date'] ?? ''));
            $receivedAt = trim((string)($evidence['received_at'] ?? ''));
            $html = '<div class="summary-grid four">'
                . $this->metric('Original filing software', $productionSoftware)
                . $this->metric('Original filing date', $filingDate)
                . $this->metric('Eligibility response reference', $reference)
                . $this->metric('Eligibility recorded', $receivedAt)
                . '</div>';
            if ($text !== '') {
                $html .= '<div class="helper"><strong>Recorded eligibility evidence</strong><div>'
                    . nl2br(HelperFramework::escape($text)) . '</div></div>';
            }

            return $html;
        }

        $html = $reference !== '' ? '<div class="helper">Companies House response reference: ' . HelperFramework::escape($reference) . '</div>' : '';

        return $html . $this->messageList($this->normaliseMessages($evidence), 'Recorded eligibility evidence');
    }

    private function artifactSummary(array $artifact): string
    {
        if ($artifact === []) {
            return '';
        }

        return '<div class="summary-grid four">'
            . $this->metric('Prepared file', (string)($artifact['filename'] ?? $artifact['generated_filename'] ?? ''))
            . $this->metric('Artifact SHA-256', (string)($artifact['sha256'] ?? $artifact['revised_artifact_sha256'] ?? $artifact['output_sha256'] ?? ''))
            . $this->metric('Basis SHA-256', (string)($artifact['basis_hash'] ?? ''))
            . $this->metric('Internal validation', (string)($artifact['validation_status'] ?? ''))
            . $this->metric('Arelle validation', (string)($artifact['external_validation_status'] ?? ''))
            . '</div>';
    }

    private function submissionSummary(?array $submission): string
    {
        if ($submission === null) {
            return '';
        }

        return '<div class="summary-grid four">'
            . $this->metric('Submission number', (string)($submission['submission_number'] ?? ''))
            . $this->metric('Gateway reference', (string)($submission['gateway_reference'] ?? $submission['gateway_submission_reference'] ?? $submission['companies_house_reference'] ?? ''))
            . $this->metric('Gateway status', (string)($submission['gateway_status'] ?? $submission['raw_gateway_status'] ?? $submission['raw_status'] ?? $submission['lifecycle'] ?? $submission['status'] ?? ''))
            . $this->metric('Submitted at', (string)($submission['submitted_at'] ?? ''))
            . $this->metric('Last checked', (string)($submission['last_polled_at'] ?? $submission['status_checked_at'] ?? ''))
            . '</div>';
    }

    private function submissionErrors(array $submission): array
    {
        $messages = [];
        foreach (['errors', 'rejection_errors', 'rejection_code', 'rejection_description', 'gateway_status_summary', 'status_details', 'examiner_comments', 'error_message'] as $key) {
            $messages = array_merge($messages, $this->normaliseMessages($submission[$key] ?? []));
        }

        return array_values(array_unique($messages));
    }

    private function blockers(array $filing): array
    {
        $readiness = (array)($filing['readiness'] ?? []);
        $blockers = array_merge(
            $this->normaliseMessages($filing['blockers'] ?? []),
            $this->normaliseMessages($readiness['filing_errors'] ?? [])
        );

        return array_values(array_unique($blockers));
    }

    private function messageList(array $messages, string $title): string
    {
        if ($messages === []) {
            return '';
        }

        $items = '';
        foreach ($messages as $message) {
            $items .= '<li>' . HelperFramework::escape($message) . '</li>';
        }

        return '<div class="helper"><strong>' . HelperFramework::escape($title) . '</strong><ul>' . $items . '</ul></div>';
    }

    private function normaliseMessages(mixed $value): array
    {
        if (is_string($value) || is_numeric($value)) {
            $message = trim((string)$value);
            return $message !== '' ? [$message] : [];
        }
        if (!is_array($value)) {
            return [];
        }

        $messages = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $message = trim((string)($item['message'] ?? $item['description'] ?? $item['detail'] ?? ''));
            } elseif (is_scalar($item)) {
                $message = trim((string)$item);
            } else {
                $message = '';
            }
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function submissionStatus(?array $submission): string
    {
        if ($submission === null) {
            return '';
        }

        $status = strtolower(trim((string)(
            $submission['lifecycle_status']
            ?? $submission['lifecycle']
            ?? $submission['normalized_status']
            ?? $submission['status']
            ?? $submission['gateway_status']
            ?? ''
        )));

        return match ($status) {
            'accept' => 'accepted',
            'reject' => 'rejected',
            'internal failure' => 'internal_failure',
            default => str_replace([' ', '-'], '_', $status),
        };
    }

    private function panel(string $title, string $body): string
    {
        return '<section class="panel-soft"><div class="status-head"><h3 class="card-title">' . HelperFramework::escape($title) . '</h3></div>' . $body . '</section>';
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'pass', 'success', 'matches' => 'success',
            'fail', 'danger', 'differs' => 'danger',
            'warning' => 'warning',
            default => 'info',
        };
    }

    private function nullableMoney(array $companySettings, mixed $value): string
    {
        return $value === null || $value === '' ? '-' : $this->money($companySettings, $value);
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
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
