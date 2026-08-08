<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _ixbrl_accounts_disclosuresCard extends CardBaseFramework
{
    private const EXPLICIT_SIMPLE_NOTE_FIELDS = [
        'micro_entity_eligibility_confirmed',
        'going_concern_basis_appropriate',
        'has_material_off_balance_sheet_arrangements',
        'has_director_advances_credits_or_guarantees',
        'has_financial_commitments_guarantees_or_contingencies',
    ];
    private const APPROVED_DISCLOSURE_FIELDS = [
        'accounting_standard',
        'average_number_employees',
        'principal_activity_sic_code',
        'principal_activity_statement',
        'entity_dormant',
        'entity_trading_status',
        'micro_entity_eligibility_confirmed',
        'going_concern_basis_appropriate',
        'has_material_off_balance_sheet_arrangements',
        'has_director_advances_credits_or_guarantees',
        'has_financial_commitments_guarantees_or_contingencies',
        'accounts_approval_date',
        'approving_director_id',
        'approving_director_name',
        'prepared_under_small_companies_regime',
        'audit_exempt_section_477',
        'directors_acknowledge_responsibilities',
        'members_have_not_required_audit',
        'directors_report_exempt_section_415a',
        'profit_loss_not_delivered_section_444',
        'companies_house_revised_accounts_public_register_confirmed',
    ];

    public function key(): string { return 'ixbrl_accounts_disclosures'; }

    public function title(): string { return 'Accounts Disclosures'; }

    public function helper(array $context): string
    {
        return 'These values are filing facts, not assumptions. Saving them after Year End is locked is allowed, audited, and makes any earlier iXBRL run stale.';
    }

    public function services(): array
    {
        return [[
            'key' => 'ixbrl_accounts_disclosures',
            'service' => \eel_accounts\Service\IxbrlAccountsDisclosureService::class,
            'method' => 'fetch',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ], [
            'key' => 'director_loan_disclosure',
            'service' => \eel_accounts\Service\DirectorLoanService::class,
            'method' => 'fetchDisclosureSummary',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ], [
            'key' => 'ixbrl_filing_approval_workflow',
            'service' => \eel_accounts\Service\IxbrlFilingApprovalWorkflowService::class,
            'method' => 'status',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ], [
            'key' => 'ct_period_projection',
            'service' => \eel_accounts\Service\CorporationTaxPeriodService::class,
            'method' => 'projectForAccountingPeriod',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ]];
    }

    protected function additionalInvalidationFacts(): array
    {
        return [
            'ixbrl.readiness',
            'ixbrl.disclosures',
            'ixbrl.facts.preview',
            'ixbrl.generation',
            'page.context',
        ];
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
        $result = (array)($context['services']['ixbrl_accounts_disclosures']
            ?? $context['ixbrl']['disclosures']
            ?? []);
        $disclosures = (array)($result['disclosures'] ?? []);
        $suggestions = (array)($result['suggested_disclosures'] ?? []);
        $suggestionSources = (array)($result['suggestion_sources'] ?? []);
        $directorLoanDisclosure = (array)($context['services']['director_loan_disclosure'] ?? []);
        $directorReport = (array)($result['director_report'] ?? []);
        $workflowStatus = (array)($context['services']['ixbrl_filing_approval_workflow'] ?? []);
        $approvalStatus = (array)($workflowStatus['accounts']
            ?? $context['services']['ixbrl_filing_approval']
            ?? []);
        $hmrcApprovalStatus = (array)($workflowStatus['hmrc']
            ?? $context['services']['hmrc_ct_filing_approval']
            ?? []);
        $submittedInput = (array)($context['ixbrl_approval_form_input'] ?? []);
        foreach (self::EXPLICIT_SIMPLE_NOTE_FIELDS as $field) {
            unset($suggestions[$field], $suggestionSources[$field]);
        }
        $display = !empty($result['stored'])
            ? $disclosures
            : array_replace($disclosures, $suggestions);
        foreach (array_merge(self::APPROVED_DISCLOSURE_FIELDS, ['is_still_trading', 'has_ever_traded']) as $field) {
            if (array_key_exists($field, $submittedInput)) {
                $display[$field] = $submittedInput[$field];
            }
        }

        if (empty($result['available'])) {
            $errors = (array)($result['errors'] ?? ['Select a valid company and accounting period.']);
            return '<div class="standout helper">' . \eel_accounts\Support\Utf8::html(implode(' ', $errors)) . '</div>';
        }

        $complete = !empty($result['complete']);
        $yearEndLocked = !empty($result['year_end_locked']);
        $approvalCurrent = !empty($approvalStatus['current']) || (string)($approvalStatus['state'] ?? '') === 'current';
        $disclosuresMatchApproval = $this->disclosuresMatchApproval($approvalStatus, $display);
        $approvalId = (int)($approvalStatus['approval_id']
            ?? ($approvalStatus['approval'] ?? [])['id']
            ?? 0);
        $hmrcApprovalId = (int)($hmrcApprovalStatus['approval_id']
            ?? ($hmrcApprovalStatus['approval'] ?? [])['id']
            ?? ($hmrcApprovalStatus['approval'] ?? [])['legacy_combined_approval_id']
            ?? 0);
        $hasApproval = $approvalCurrent || $approvalId > 0 || $hmrcApprovalId > 0;
        $submittedEditing = $submittedInput !== []
            && (string)($submittedInput['ixbrl_approval_editing'] ?? '1') === '1';
        $disclosuresInitiallyLocked = $yearEndLocked
            && $hasApproval
            && ($approvalCurrent || $disclosuresMatchApproval)
            && !$submittedEditing;
        $controlDisabled = !$yearEndLocked || $disclosuresInitiallyLocked;
        $disabledAttribute = $controlDisabled ? ' disabled aria-disabled="true"' : '';
        $missing = (array)($result['missing_labels'] ?? []);
        $periodEnd = (string)(($result['accounting_period'] ?? [])['period_end'] ?? '');
        $dateFormat = (string)($company['settings']['date_format'] ?? 'd/m/Y');
        if (!in_array($dateFormat, ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y'], true)) {
            $dateFormat = 'd/m/Y';
        }
        $periodEndDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd);
        $periodEndDisplay = $periodEndDate instanceof \DateTimeImmutable && $periodEndDate->format('Y-m-d') === $periodEnd
            ? $periodEndDate->format($dateFormat)
            : 'the accounting period end';
        $formatCompanyDate = static function (string $date) use ($dateFormat): string {
            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            return $parsedDate instanceof \DateTimeImmutable && $parsedDate->format('Y-m-d') === $date
                ? $parsedDate->format($dateFormat)
                : $date;
        };
        $tradingEvidence = (array)($result['trading_status_evidence'] ?? []);
        $hasTradingEvidence = !empty($tradingEvidence['has_previous_trading_evidence']);
        $tradingAnswers = (array)($result['trading_status_answers'] ?? []);
        if ($tradingAnswers === []) {
            $tradingAnswers = $this->tradingStatusAnswers((string)($display['entity_trading_status'] ?? ''));
        }
        foreach (['is_still_trading', 'has_ever_traded'] as $field) {
            if (array_key_exists($field, $submittedInput)) {
                $tradingAnswers[$field] = $submittedInput[$field];
            }
        }
        $selectedDirectorId = (int)($display['approving_director_id'] ?? 0);
        $directorOptions = '<option value="">Select approving director</option>';
        foreach ((array)($result['director_suggestions'] ?? []) as $director) {
            $director = (array)$director;
            $directorId = (int)($director['id'] ?? 0);
            $directorName = trim((string)($director['full_name'] ?? ''));
            if ($directorId <= 0 || $directorName === '') {
                continue;
            }
            $directorOptions .= '<option value="' . $directorId . '"'
                . ($selectedDirectorId === $directorId ? ' selected' : '')
                . '>' . \eel_accounts\Support\Utf8::html($directorName) . '</option>';
        }
        $selectedPrincipalActivityCode = trim((string)($display['principal_activity_sic_code'] ?? ''));
        $principalActivityOptions = '<option value="" disabled'
            . ($selectedPrincipalActivityCode === '' ? ' selected' : '')
            . '>Please select activity...</option>';
        foreach ((array)($result['principal_activity_suggestions'] ?? []) as $activity) {
            $activity = (array)$activity;
            $sicCode = trim((string)($activity['sic_code'] ?? ''));
            $description = trim((string)($activity['description'] ?? ''));
            if ($sicCode === '' || $description === '') {
                continue;
            }
            $principalActivityOptions .= '<option value="' . \eel_accounts\Support\Utf8::html($sicCode) . '"'
                . ($selectedPrincipalActivityCode === $sicCode ? ' selected' : '')
                . '>' . \eel_accounts\Support\Utf8::html($sicCode . ' — ' . $description) . '</option>';
        }
        $sourceSummary = $this->sourceSummary($suggestionSources, !empty($result['stored']));
        $profileErrors = '';
        foreach ((array)($result['profile_errors'] ?? []) as $profileError) {
            $profileErrors .= '<div class="standout helper">'
                . \eel_accounts\Support\Utf8::html((string)$profileError)
                . '</div>';
        }
        $dormancy = (array)($result['dormancy'] ?? []);
        $dormancyCalculated = !empty($dormancy['calculated']);
        $salesNominalCode = trim((string)($dormancy['sales_nominal_code'] ?? ''));
        $salesNominalName = trim((string)($dormancy['sales_nominal_name'] ?? ''));
        $salesNominalLabel = $salesNominalCode !== ''
            ? 'Nominal ' . $salesNominalCode . ($salesNominalName !== '' ? ' ' . $salesNominalName : '')
            : 'the configured Sales nominal';
        $dormancyLabel = $dormancyCalculated
            ? ((int)($dormancy['entity_dormant'] ?? 0) === 1
                ? 'Dormant during Accounting Period'
                : 'Not Dormant during Accounting Period')
            : 'Not available';
        $dormancyDetail = $dormancyCalculated
            ? 'Based on gross posted sales of £' . number_format((float)($dormancy['gross_sales'] ?? 0), 2) . ' on '
                . $salesNominalLabel . '.'
            : (string)($dormancy['error'] ?? 'Configure a default Sales nominal to calculate this status.');
        $smallCompanies = (array)($result['small_companies_regime'] ?? []);
        $smallCompaniesAvailable = !empty($smallCompanies['available']);
        $smallCompaniesLabel = $smallCompaniesAvailable
            ? (!empty($smallCompanies['qualifies']) ? 'Yes' : 'No')
            : 'Not available';
        $smallCompaniesSummary = '';
        if ($smallCompaniesAvailable) {
            $metrics = (array)($smallCompanies['metrics'] ?? []);
            $thresholds = (array)($smallCompanies['thresholds'] ?? []);
            $baseThresholds = (array)($smallCompanies['base_thresholds'] ?? []);
            $passes = (array)($smallCompanies['passes'] ?? []);
            $thresholdPeriod = (array)($smallCompanies['threshold_effective_period'] ?? []);
            $money = static fn(mixed $value): string => '£' . number_format((float)$value, 2);
            $testValue = static function (string $key) use ($metrics, $thresholds, $passes, $money): string {
                return $money($metrics[$key] ?? 0)
                    . ' / ' . $money($thresholds[$key] ?? 0) . ' (' . (!empty($passes[$key]) ? 'Pass' : 'Fail') . ')';
            };
            $sourceUrl = trim((string)($smallCompanies['threshold_source'] ?? ''));
            $source = $sourceUrl === ''
                ? 'Not recorded'
                : '<a class="button" href="' . \eel_accounts\Support\Utf8::html($sourceUrl) . '" target="_blank" rel="noopener noreferrer">GOV.UK guidance</a>';
            $thresholdStart = trim((string)($thresholdPeriod['start'] ?? ''));
            $thresholdEnd = trim((string)($thresholdPeriod['end'] ?? ''));
            $thresholdDates = $formatCompanyDate($thresholdStart) . ' to '
                . ($thresholdEnd !== '' ? $formatCompanyDate($thresholdEnd) : 'Current');
            $checkedAt = $formatCompanyDate(trim((string)($smallCompanies['threshold_source_checked_at'] ?? '')));
            $smallCompaniesSummary = '<div class="ixbrl-small-companies-detail table-scroll"><table><thead><tr>
                <th>FRS 105 tests</th><th>Turnover</th><th>Balance sheet total</th><th>Average employees</th><th>Source</th><th>Validity Period</th><th>Last Checked</th>
            </tr></thead><tbody><tr>
                <td>' . (int)($smallCompanies['pass_count'] ?? 0) . ' of 3 passed; all required</td>
                <td>' . \eel_accounts\Support\Utf8::html($testValue('turnover')) . '<div class="helper">Base ' . \eel_accounts\Support\Utf8::html($money($baseThresholds['turnover'] ?? 0)) . '; ' . (int)($smallCompanies['period_days'] ?? 0) . ' days</div></td>
                <td>' . \eel_accounts\Support\Utf8::html($testValue('balance_sheet_total')) . '</td>
                <td>' . (int)($metrics['employees'] ?? 0) . ' / ' . (int)($thresholds['employees'] ?? 0) . ' (' . (!empty($passes['employees']) ? 'Pass' : 'Fail') . ')</td>
                <td>' . $source . '</td>
                <td>' . \eel_accounts\Support\Utf8::html($thresholdDates) . '</td>
                <td>' . \eel_accounts\Support\Utf8::html($checkedAt) . '</td>
            </tr></tbody></table></div>';
        } else {
            $smallCompaniesSummary = '<div class="helper">' . \eel_accounts\Support\Utf8::html((string)($smallCompanies['error'] ?? 'Enter the accounting figures and refresh to calculate this status.')) . '</div>';
        }
        $updatedAt = trim((string)($disclosures['updated_at'] ?? ''));
        $updatedBy = trim((string)($result['updated_by_display_name'] ?? ''));
        $updatedAtDisplay = $updatedAt !== '' ? $updatedAt : 'Not yet saved';
        $updatedByDisplay = $updatedBy !== '' ? $updatedBy : 'Not yet saved';
        $disclosureLockNotice = !$yearEndLocked
            ? '<div class="standout helper">Complete and lock Year End before confirming the accounts disclosures.</div>'
            : ($disclosuresInitiallyLocked
                ? '<div class="standout helper">The saved accounts disclosures still match the current approval'
                    . ($approvalId > 0 ? ' #' . $approvalId : '')
                    . '. They remain readable while locked. Select Edit only when the disclosure or Corporation Tax declaration needs to change.</div>'
                : '');
        $bothCurrent = !empty($workflowStatus['both_current']);
        $stateToken = trim((string)($workflowStatus['state_token'] ?? ''));
        $approvalNote = (string)($submittedInput['approval_note'] ?? '');
        $editing = $yearEndLocked && (!$disclosuresInitiallyLocked || $submittedEditing);
        $formBlockers = array_values((array)($workflowStatus['form_blockers'] ?? []));
        $externalBlockers = array_values((array)($workflowStatus['external_blockers'] ?? []));
        // Keep the final operation available without JavaScript whenever the
        // server can identify and secure this locked filing state. Editing can
        // resolve disclosure and declaration blockers, but prerequisites which
        // live elsewhere (scope, computation seals and Year End) must remain
        // fail-closed. JavaScript adds immediate field-validity and CT-Yes
        // gating; the workflow transaction remains authoritative.
        $approvalSubmissionAvailable = $yearEndLocked
            && (!$bothCurrent || $editing)
            && $stateToken !== ''
            && $externalBlockers === []
            && ($editing || $formBlockers === []);
        $directorReportNotesBlank = !empty($directorReport['review_notes_blank']);
        $directorReportApprovalBlocked = $directorReportNotesBlank
            && (int)($display['directors_report_exempt_section_415a'] ?? 1) === 0;

        return '<div class="settings-stack">
            <form method="post" action="?page=disclosures" data-ajax="true" data-ixbrl-trading-form="true" data-ixbrl-disclosures-form="true" data-ixbrl-approval-form="true"
                data-ixbrl-disclosures-can-edit="' . ($yearEndLocked ? '1' : '0') . '"
                data-ixbrl-disclosures-initially-locked="' . ($disclosuresInitiallyLocked ? '1' : '0') . '"
                data-ixbrl-approval-can-approve="' . ($approvalSubmissionAvailable ? '1' : '0') . '"
                data-ixbrl-directors-report-notes-blank="' . ($directorReportNotesBlank ? '1' : '0') . '">
            <input type="hidden" name="card_action" value="Ixbrl">
            ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <input type="hidden" name="accounting_standard" value="FRS_105">
            <input type="hidden" name="state_token" value="' . \eel_accounts\Support\Utf8::html($stateToken) . '">
            <input type="hidden" name="ixbrl_approval_editing" value="' . ($editing ? '1' : '0') . '" data-ixbrl-approval-editing-flag="true">
            <section class="panel-soft">
                <div class="status-head">
                    <h3 class="card-title">Accounts Disclosures</h3>
                    <span class="badge ' . ($complete ? 'success' : 'danger') . '">' . ($complete ? 'Complete' : 'Required') . '</span>
                </div>
                ' . $sourceSummary . '
                ' . ($missing !== []
                    ? '<div class="helper">Still required: ' . \eel_accounts\Support\Utf8::html(implode(', ', $missing)) . '.</div>'
                    : '') . '
                ' . $profileErrors . '
            ' . $disclosureLockNotice . '
                    <div class="form-grid">
                    <div class="form-row full table-scroll">
                        <table><tbody>
                            <tr><th scope="row"><label for="ixbrl_accounting_standard_display">Accounting standard</label></th><td><input class="input" id="ixbrl_accounting_standard_display" value="FRS 105" readonly' . $disabledAttribute . '></td></tr>
                            <tr><th scope="row"><label for="ixbrl_average_number_employees">Average number of employees</label></th><td><input class="input" id="ixbrl_average_number_employees" name="average_number_employees" type="number" min="0" step="1" required value="' . \eel_accounts\Support\Utf8::html($this->nullableValue($display['average_number_employees'] ?? null)) . '" data-state-default="' . \eel_accounts\Support\Utf8::html($this->nullableValue($display['average_number_employees'] ?? null)) . '" data-ixbrl-disclosure-control="true" data-ixbrl-approval-control="true"' . $disabledAttribute . '>' . $this->lockedMirror('average_number_employees', $this->nullableValue($display['average_number_employees'] ?? null), $controlDisabled) . '</td></tr>
                            <tr>
                                <th scope="row"><label for="ixbrl_principal_activity_sic_code">Principal activity</label></th>
                                <td>
                                    <select class="select" id="ixbrl_principal_activity_sic_code" name="principal_activity_sic_code" required data-no-submit-on-change="true" data-principal-activity-select="true" data-state-default="' . \eel_accounts\Support\Utf8::html($selectedPrincipalActivityCode) . '" data-ixbrl-disclosure-control="true" data-ixbrl-approval-control="true"' . $disabledAttribute . '>
                                        ' . $principalActivityOptions . '
                                    </select>
                                    ' . $this->lockedMirror('principal_activity_sic_code', $selectedPrincipalActivityCode, $controlDisabled) . '
                                    <div class="helper">Select the Companies House SIC activity used in the principal activity note.</div>
                                </td>
                            </tr>
                            <tr><th scope="row"><label for="ixbrl_accounts_approval_date">Accounts approval date</label></th><td><div class="actions-row actions-row-nowrap"><input class="input" id="ixbrl_accounts_approval_date" name="accounts_approval_date" type="date" required value="' . \eel_accounts\Support\Utf8::html((string)($display['accounts_approval_date'] ?? '')) . '" data-state-default="' . \eel_accounts\Support\Utf8::html((string)($display['accounts_approval_date'] ?? '')) . '" data-ixbrl-disclosure-control="true" data-ixbrl-approval-control="true"' . $disabledAttribute . '><button class="button primary" type="button" data-set-today-for="ixbrl_accounts_approval_date" data-ixbrl-disclosure-control="true" data-ixbrl-approval-control="true"' . $disabledAttribute . '>Today</button></div>' . $this->lockedMirror('accounts_approval_date', (string)($display['accounts_approval_date'] ?? ''), $controlDisabled) . '</td></tr>
                            <tr>
                                <th scope="row"><label for="ixbrl_approving_director_id">Director signing and approving the accounts</label></th>
                                <td>
                                    <select class="select" id="ixbrl_approving_director_id" name="approving_director_id" required data-state-default="' . ($selectedDirectorId > 0 ? $selectedDirectorId : '') . '" data-ixbrl-disclosure-control="true" data-ixbrl-approval-control="true"' . $disabledAttribute . '>
                                        ' . $directorOptions . '
                                    </select>
                                    ' . $this->lockedMirror('approving_director_id', $selectedDirectorId > 0 ? $selectedDirectorId : '', $controlDisabled) . '
                                    <div class="helper">The selected officer’s name is used as the approving and signing director in the generated iXBRL.</div>
                                </td>
                            </tr>
                            <tr><th scope="row">Last updated on</th><td>' . \eel_accounts\Support\Utf8::html($updatedAtDisplay) . '</td></tr>
                            <tr><th scope="row">Last updated by</th><td>' . \eel_accounts\Support\Utf8::html($updatedByDisplay) . '</td></tr>
                        </tbody></table>
                    </div>
                    <div class="form-row full">
                        ' . $this->yesNo(
                            'is_still_trading',
                            'Was the company still trading on ' . $periodEndDisplay . '?',
                            $tradingAnswers['is_still_trading'] ?? null,
                            $controlDisabled
                        ) . '
                        ' . ($hasTradingEvidence
                            ? ''
                            : '<div data-ixbrl-ever-traded-panel="true">'
                                . $this->yesNo(
                                    'has_ever_traded',
                                    'Has the company ever traded?',
                                    $tradingAnswers['has_ever_traded'] ?? null,
                                    $controlDisabled
                                )
                                . '</div>') . '
                        <div class="helper">If a company is marked as not trading on ' . \eel_accounts\Support\Utf8::html($periodEndDisplay) . ', it automatically calculates Never Traded versus No Longer Trading status based on any historical Sales posted.</div>
                    </div>
                    </div>
                </section>
                <div class="settings-stack">
                    <section class="panel-soft ixbrl-dormancy-summary">
                        <div class="status-head">
                            <h4 class="card-title">Was the company dormant for this accounting period?</h4>
                        </div>
                        <div class="helper">Automatically calculated from posted credits to the configured Sales nominal. ' . \eel_accounts\Support\Utf8::html($dormancyDetail) . '</div>
                        <div class="card-title">' . \eel_accounts\Support\Utf8::html($dormancyLabel) . '</div>
                    </section>
                    <section class="panel-soft">
                        <div class="status-head">
                            <h4 class="card-title">Were these accounts prepared under the small companies regime?</h4>
                            <span class="badge ' . ($smallCompaniesAvailable && !empty($smallCompanies['qualifies']) ? 'success' : 'danger') . '">' . \eel_accounts\Support\Utf8::html($smallCompaniesLabel) . '</span>
                        </div>
                        <div class="ixbrl-small-companies-detail">
                            ' . $smallCompaniesSummary . '
                        </div>
                        ' . $this->yesNo('audit_exempt_section_477', 'Is the company claiming audit exemption under section 477 of the Companies Act 2006?', $display['audit_exempt_section_477'] ?? null, $controlDisabled) . '
                        ' . $this->yesNo('directors_acknowledge_responsibilities', 'Do the directors acknowledge their Companies Act responsibilities for the records and accounts?', $display['directors_acknowledge_responsibilities'] ?? null, $controlDisabled) . '
                        ' . $this->yesNo('members_have_not_required_audit', 'Do the relevant business voting parties confirm that no audit is required under section 476?', $display['members_have_not_required_audit'] ?? null, $controlDisabled) . '
                    </section>
                    <section class="panel-soft">
                        <h4 class="card-title">Eligibility and accounting basis</h4>
                        <div class="helper ixbrl-eligibility-helper">Sending of Accounts and Returns using this software will be blocked if either of the following two questions are No, as they are not supported.</div>
                        ' . $this->yesNo('micro_entity_eligibility_confirmed', 'Is the company eligible to prepare these accounts as a micro-entity?', $display['micro_entity_eligibility_confirmed'] ?? null, $controlDisabled) . '
                        ' . $this->yesNo('going_concern_basis_appropriate', 'Is the business still a going-concern and continue to operate for the foreseeable future?', $display['going_concern_basis_appropriate'] ?? null, $controlDisabled) . '
                    </section>
                    <section class="panel-soft">
                        <h4 class="card-title ixbrl-frs105-notes-title">FRS 105 Notes</h4>
                        ' . $this->yesNo('has_material_off_balance_sheet_arrangements', 'Are there any material off-balance-sheet arrangements requiring disclosure?', $display['has_material_off_balance_sheet_arrangements'] ?? null, $controlDisabled, false, $companyId, $accountingPeriodId, 'Director and Participant Advances are calculated automatically from transactions. This is confirming no other legal agreements exist which create a liability.') . '
                        ' . $this->directorLoanDisclosure($directorLoanDisclosure) . '
                        ' . $this->yesNo('has_director_advances_credits_or_guarantees', 'Were there any director guarantees requiring disclosure?', $display['has_director_advances_credits_or_guarantees'] ?? null, $controlDisabled) . '
                        ' . $this->yesNo('has_financial_commitments_guarantees_or_contingencies', 'Are there any financial commitments, guarantees or contingencies requiring disclosure?', $display['has_financial_commitments_guarantees_or_contingencies'] ?? null, $controlDisabled) . '
                    </section>
                    ' . $this->companiesHouseFilingOptionsPanel(
                        $display,
                        $controlDisabled,
                        $directorReportNotesBlank
                    ) . '
                    ' . $this->companiesHouseRevisedAccountsPanel($result, $display, $controlDisabled, $companyId, $accountingPeriodId) . '
                </div>
                ' . $this->ct600AuthorisationPanel(
                    $companyId,
                    $accountingPeriodId,
                    $context,
                    $controlDisabled,
                    $submittedInput
                ) . '
                ' . $this->approvalPanel(
                    $workflowStatus,
                    $approvalStatus,
                    $hmrcApprovalStatus,
                    $approvalNote,
                    $yearEndLocked,
                    $disclosuresInitiallyLocked,
                    $approvalSubmissionAvailable && !$directorReportApprovalBlocked,
                    $bothCurrent
                ) . '
            </form>
        </div>';
    }

    private function companiesHouseFilingOptionsPanel(
        array $display,
        bool $controlDisabled,
        bool $reviewNotesBlank
    ): string {
        $question = function (string $name, string $label, string $helper) use ($display, $controlDisabled): string {
            $value = $display[$name] ?? 1;
            $normalised = $value === null || $value === '' ? 1 : (int)$value;
            $yesId = 'ixbrl_' . $name . '_yes';
            $noId = 'ixbrl_' . $name . '_no';
            $disabled = $controlDisabled ? ' disabled aria-disabled="true"' : '';
            return '<div class="form-row full ixbrl-companies-house-question">'
                . '<div class="card-title">' . \eel_accounts\Support\Utf8::html($label) . '</div>'
                . '<div class="helper ixbrl-question-helper">' . \eel_accounts\Support\Utf8::html($helper) . '</div>'
                . '<div class="actions-row">'
                . '<label for="' . $yesId . '"><input id="' . $yesId . '" type="radio" name="' . $name . '" value="1" required data-ixbrl-disclosure-control="true" data-ixbrl-approval-control="true"' . ($normalised === 1 ? ' checked' : '') . $disabled . '> Yes</label>'
                . '<label for="' . $noId . '"><input id="' . $noId . '" type="radio" name="' . $name . '" value="0" required data-ixbrl-disclosure-control="true" data-ixbrl-approval-control="true"' . ($normalised === 0 ? ' checked' : '') . $disabled . '> No</label>'
                . '</div>'
                . $this->lockedMirror($name, $normalised, $controlDisabled)
                . '</div>';
        };
        $warningHidden = $reviewNotesBlank
            && (int)($display['directors_report_exempt_section_415a'] ?? 1) === 0
                ? ''
                : ' is-hidden';

        return '<section class="panel-soft ixbrl-companies-house-filing-options">'
            . '<h4 class="card-title">Companies House Filing Options</h4>'
            . '<div class="helper">These elections affect only the Companies House copy. The HMRC accounts continue to include the Profit and Loss account and do not include Directors’ Report material.</div>'
            . '<fieldset class="ixbrl-companies-house-filing-fieldset"><legend>Companies Act exemptions</legend>'
            . $question(
                'directors_report_exempt_section_415a',
                'Are you claiming an Exemption from giving a Directors\' Report under Section 415A?',
                'Yes is the default for a micro-entity. Choose No to include a Directors\' Report composed from Year End Notes followed by the non-empty Year End confirmation-note sentences.'
            )
            . '<div class="standout helper' . $warningHidden . '" data-ixbrl-directors-report-warning="true" aria-hidden="' . ($warningHidden === '' ? 'false' : 'true') . '">Year End Notes is blank. <a class="button" href="?page=year_end&amp;show_card=year_end_notes">Open Year End Notes</a> before approving accounts which include a Directors’ Report.</div>'
            . $question(
                'profit_loss_not_delivered_section_444',
                'Are you claiming an Exemption from Public Profit & Loss Filing (Section 444)?',
                'Yes omits the complete Profit and Loss page from the Companies House iXBRL and adds the Section 444(5A) election statement. It does not change the HMRC accounts.'
            )
            . '</fieldset></section>';
    }

    private function companiesHouseRevisedAccountsPanel(
        array $result,
        array $display,
        bool $controlDisabled,
        int $companyId,
        int $accountingPeriodId
    ): string {
        if (empty($result['companies_house_revision_required'])) {
            return '<section class="panel-soft">
                <h4 class="card-title">Companies House Revised Accounts</h4>
                <div class="helper">No Companies House revised-accounts disclosure is required for this accounting period.</div>
            </section>';
        }

        return '<section class="panel-soft">
            <h4 class="card-title">Companies House Revised Accounts</h4>
            <div class="helper companies-house-revised-disclosure-helper">This confirmation is required because the Companies House comparison between what has been filed and what will be filed is different.</div>
            ' . $this->yesNo(
                'companies_house_revised_accounts_public_register_confirmed',
                'Can you confirm that if updated accounts are submitted to Companies House, both the original and the revised versions remain available for public inspection?',
                $display['companies_house_revised_accounts_public_register_confirmed'] ?? null,
                $controlDisabled,
                true,
                $companyId,
                $accountingPeriodId
            ) . '
        </section>';
    }

    private function approvalPanel(
        array $workflowStatus,
        array $accountsStatus,
        array $hmrcStatus,
        string $approvalNote,
        bool $yearEndLocked,
        bool $initiallyLocked,
        bool $canSubmitApproval,
        bool $bothCurrent
    ): string {
        $accounts = $this->approvalStatusRow('Statutory Accounts', $accountsStatus, false);
        $hmrc = $this->approvalStatusRow('HMRC Corporation Tax', $hmrcStatus, true);
        $blockers = array_values(array_filter(array_map(
            static fn(mixed $blocker): string => trim(is_array($blocker)
                ? (string)($blocker['message'] ?? $blocker['error'] ?? '')
                : (string)$blocker),
            (array)($workflowStatus['blockers'] ?? [])
        ), static fn(string $blocker): bool => $blocker !== ''));
        $blockerHtml = '';
        if ($blockers !== []) {
            $blockerHtml = '<div class="standout ixbrl-approval-blockers"><strong>Action required before approval</strong><ul>';
            foreach ($blockers as $blocker) {
                $blockerHtml .= '<li>' . \eel_accounts\Support\Utf8::html($blocker) . '</li>';
            }
            $blockerHtml .= '</ul></div>';
        }
        $editDisabled = !$yearEndLocked || !$initiallyLocked ? ' disabled aria-disabled="true"' : '';
        $cancelDisabled = !$yearEndLocked ? ' disabled aria-disabled="true"' : '';
        $draftDisabled = !$yearEndLocked || $initiallyLocked
            ? ' disabled aria-disabled="true"'
            : '';
        $approvalDisabled = !$canSubmitApproval ? ' disabled aria-disabled="true"' : '';

        return '<section class="panel-soft ixbrl-approval-panel">
            <div class="status-head"><h3 class="card-title">Accounts and Corporation Tax Approval</h3><span class="badge ' . ($bothCurrent ? 'success' : 'warning') . '">' . ($bothCurrent ? 'Current' : 'Approval required') . '</span></div>
            <div class="helper">The two filing approvals remain separate immutable evidence records even though they are confirmed together here.</div>
            <div class="form-row full table-scroll"><table><thead><tr><th>Filing basis</th><th>Status</th><th>Evidence</th></tr></thead><tbody>'
                . $accounts . $hmrc . '
            </tbody></table></div>
            <div class="form-row full"><label for="ixbrl_filing_approval_note">Approval note (optional)</label>
                <textarea class="input" id="ixbrl_filing_approval_note" name="approval_note" rows="2"' . (!$yearEndLocked ? ' disabled aria-disabled="true"' : '') . '>' . \eel_accounts\Support\Utf8::html($approvalNote) . '</textarea></div>
            <div class="helper">Save Draft records changes to the editable disclosures and Corporation Tax declaration only. It does not create filing approvals.</div>
            <div class="helper ixbrl-approval-confirmation">Approval confirms that the information on this page is a true and accurate reflection of this business. It creates filing evidence only; it does not transmit or send anything to Companies House or HMRC.</div>
            ' . $blockerHtml . '
            <div class="actions-row ixbrl-disclosure-edit-actions">
                <button class="button primary" type="submit" name="intent" value="edit_ixbrl_approval_draft" formnovalidate data-ixbrl-disclosures-edit="true"' . $editDisabled . '>Edit</button>
                <button class="button secondary" type="submit" name="intent" value="save_ixbrl_approval_draft" data-ixbrl-disclosures-save="true"' . $draftDisabled . '>Save Draft</button>
                <button class="button secondary" type="submit" name="intent" value="cancel_ixbrl_approval_edit" formnovalidate data-ixbrl-disclosures-cancel="true"' . $cancelDisabled . '>Cancel</button>
                <button class="button primary" type="submit" name="intent" value="approve_ixbrl_accounts_and_ct" data-ixbrl-approval-submit="true"' . $approvalDisabled . '>Approve Accounts and Corporation Tax Return</button>
            </div>
        </section>';
    }

    private function ct600AuthorisationPanel(
        int $companyId,
        int $accountingPeriodId,
        array $context,
        bool $controlDisabled,
        array $submittedInput
    ): string
    {
        $service = new \eel_accounts\Service\Ct600ReturnAuthorisationService();
        $saved = $service->fetch($companyId, $accountingPeriodId);
        $savedReference = (int)($saved['declarant_director_id'] ?? 0) > 0
            ? 'director:' . (int)$saved['declarant_director_id']
            : ((int)($saved['declarant_role_id'] ?? 0) > 0
                ? 'party-role:' . (int)$saved['declarant_role_id']
                : '');
        $selectedReference = array_key_exists('declarant_authority', $submittedInput)
            ? trim((string)$submittedInput['declarant_authority'])
            : $savedReference;
        $options = '<option value="">Select authoriser and capacity</option>';
        $hasEligibleAuthorisers = false;
        $renderedSelectedReference = '';
        foreach ($service->eligibleAuthorisers($companyId, (new \DateTimeImmutable('today'))->format('Y-m-d')) as $authoriser) {
            $reference = (string)$authoriser['reference'];
            if ($reference === $selectedReference) {
                $renderedSelectedReference = $reference;
            }
            $options .= '<option value="' . \eel_accounts\Support\Utf8::html($reference) . '"'
                . ($reference === $selectedReference ? ' selected' : '') . '>'
                . \eel_accounts\Support\Utf8::html((string)$authoriser['name'] . ' — ' . (string)$authoriser['status'])
                . '</option>';
            $hasEligibleAuthorisers = true;
        }
        $savedName = trim((string)($saved['declarant_name'] ?? ''));
        $savedStatus = trim((string)($saved['declarant_status'] ?? ''));
        $legacyNotice = $saved !== [] && !$service->isStructured($saved)
            ? '<div class="helper">This existing legacy authorisation remains preserved. Select a named authoriser only when replacing it.</div>'
            : '';
        $eligibilityNotice = !$hasEligibleAuthorisers
            ? '<div class="standout helper">No individual has an eligible authority effective today. Add a current director or filing-authority relationship before saving a new declaration.</div>'
            : '';
        $disabledAttribute = $controlDisabled ? ' disabled aria-disabled="true"' : '';
        $dateFormat = (string)($context['company']['settings']['date_format'] ?? 'd/m/Y');
        if (!in_array($dateFormat, ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y'], true)) {
            $dateFormat = 'd/m/Y';
        }
        $formatDate = static function (string $date) use ($dateFormat): string {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date
                ? $parsed->format($dateFormat)
                : $date;
        };
        $ctPeriodRows = '';
        foreach ((array)($context['services']['ct_period_projection']['periods'] ?? []) as $period) {
            $displaySequenceNo = (int)($period['display_sequence_no'] ?? $period['sequence_no'] ?? 0);
            $periodStart = $formatDate((string)($period['period_start'] ?? ''));
            $periodEnd = $formatDate((string)($period['period_end'] ?? ''));
            if ($displaySequenceNo <= 0 || $periodStart === '' || $periodEnd === '') {
                continue;
            }
            $ctPeriodRows .= '<tr><th scope="row">CT Period '
                . $displaySequenceNo
                . '</th><td>'
                . \eel_accounts\Support\Utf8::html($periodStart . ' to ' . $periodEnd)
                . '</td></tr>';
        }
        $answer = static function (string $key) use ($submittedInput, $saved): ?int {
            $value = array_key_exists($key, $submittedInput) ? $submittedInput[$key] : ($saved[$key] ?? null);
            return $value === null || $value === '' ? null : (int)$value;
        };
        $question = function (string $key, string $label, ?int $value) use ($disabledAttribute, $controlDisabled): string {
            return '<fieldset class="panel-soft"><legend>' . \eel_accounts\Support\Utf8::html($label) . '</legend><div class="actions-row">'
                . '<label><input type="radio" name="' . $key . '" value="1" required data-ct600-authorisation-field="true" data-ixbrl-approval-control="true"' . ($value === 1 ? ' checked' : '') . $disabledAttribute . '> Yes</label>'
                . '<label><input type="radio" name="' . $key . '" value="0" required data-ct600-authorisation-field="true" data-ixbrl-approval-control="true"' . ($value === 0 ? ' checked' : '') . $disabledAttribute . '> No</label>'
                . '</div>' . $this->lockedMirror($key, $value === null ? '' : $value, $controlDisabled) . '</fieldset>';
        };
        return '<section class="panel-soft ixbrl-ct-declaration-panel"><div class="status-head"><h3 class="card-title">Corporation Tax Return Declaration</h3></div>'
            . '<div class="helper">The declaration is saved as a draft until it is bound to the current Statutory Accounts and CT-period bases by the combined approval below.</div>'
            . $legacyNotice . $eligibilityNotice
            . '<div class="form-row full table-scroll"><table><tbody>'
            . '<tr><th scope="row"><label for="ct600_declarant_authority">Authoriser and capacity</label></th><td><select class="select" id="ct600_declarant_authority" name="declarant_authority" required data-state-default="' . \eel_accounts\Support\Utf8::html($renderedSelectedReference) . '" data-ct600-authorisation-field="true" data-ixbrl-approval-control="true"' . $disabledAttribute . '>' . $options . '</select>' . $this->lockedMirror('declarant_authority', $selectedReference, $controlDisabled) . '</td></tr>'
            . $ctPeriodRows
            . '<tr><th scope="row">Saved declarant</th><td>' . \eel_accounts\Support\Utf8::html($savedName !== '' ? $savedName : 'Not recorded in the legacy authorisation') . '</td></tr>'
            . '<tr><th scope="row">Saved capacity</th><td>' . \eel_accounts\Support\Utf8::html($savedStatus !== '' ? $savedStatus : 'Not saved') . '</td></tr>'
            . '<tr><th scope="row">Last updated on</th><td>' . \eel_accounts\Support\Utf8::html((string)($saved['saved_at'] ?? 'Not saved')) . '</td></tr>'
            . '<tr><th scope="row">Last updated by</th><td>' . \eel_accounts\Support\Utf8::html((string)($saved['saved_by_display_name'] ?? 'Not saved')) . '</td></tr>'
            . '</tbody></table></div>'
            . $question('original_unfiled_confirmed', 'Are these original returns that have not already been filed for the CT periods listed above?', $answer('original_unfiled_confirmed'))
            . $question('authority_confirmed', 'Are you authorised to file these Corporation Tax returns for the company?', $answer('authority_confirmed'))
            . $question('declaration_confirmed', 'Do you declare that the information in these returns is correct and complete to the best of your knowledge and belief?', $answer('declaration_confirmed'))
            . '</section>';
    }

    private function approvalBlockerNotice(array $status): string
    {
        if (!empty($status['can_approve'])) {
            return '';
        }

        $errors = array_values(array_filter(
            array_map('strval', (array)($status['errors'] ?? [])),
            static fn(string $error): bool => trim($error) !== ''
        ));
        if ($errors === []) {
            return '';
        }

        $reasons = '<ul>';
        foreach ($errors as $error) {
            $reasons .= '<li>' . \eel_accounts\Support\Utf8::html($error) . '</li>';
        }
        $reasons .= '</ul>';

        return '<section class="panel-soft warn ixbrl-approval-blocker">
            <h3 class="card-title">Disclosure approval unavailable</h3>
            <div class="helper">Disclosure approval cannot be entered at this time because:</div>'
            . $reasons . '
        </section>';
    }

    private function approvalStatusRow(string $label, array $status, bool $hmrc): string
    {
        $state = (string)($status['state'] ?? 'absent');
        $current = $state === 'current' || !empty($status['current']);
        $source = (string)($status['source'] ?? $status['approval_source'] ?? '');
        $legacy = $current && in_array($source, ['legacy', 'legacy_combined'], true);
        $statusLabel = $legacy
            ? 'Legacy current'
            : ($current ? 'Current' : ($state === 'stale' ? 'Stale' : 'Not approved'));
        $badge = $legacy ? 'warning' : ($current ? 'success' : 'danger');
        $approval = is_array($status['approval'] ?? null) ? (array)$status['approval'] : [];
        $approvalId = (int)($status['approval_id']
            ?? $approval['id']
            ?? $approval['legacy_combined_approval_id']
            ?? 0);
        $evidence = $approvalId > 0
            ? 'Approval #' . $approvalId
                . ($approval['approved_at'] ?? null ? ' at ' . (string)$approval['approved_at'] : '')
                . ($approval['approved_by'] ?? null ? ' by ' . (string)$approval['approved_by'] : '')
            : 'No approval evidence';

        return '<tr><th scope="row">' . \eel_accounts\Support\Utf8::html($label) . '</th><td><span class="badge '
            . $badge . '">' . \eel_accounts\Support\Utf8::html($statusLabel) . '</span></td><td>'
            . \eel_accounts\Support\Utf8::html($evidence) . '</td></tr>';
    }

    private function lockedMirror(string $name, mixed $value, bool $enabled): string
    {
        return '<input type="hidden" name="' . \eel_accounts\Support\Utf8::html($name)
            . '" value="' . \eel_accounts\Support\Utf8::html((string)$value)
            . '" data-ixbrl-approval-mirror="true"' . ($enabled ? '' : ' disabled') . '>';
    }

    private function yesNo(string $name, string $label, mixed $value, bool $disabled = false, bool $ajaxField = false, int $companyId = 0, int $accountingPeriodId = 0, string $helper = ''): string
    {
        $yesId = 'ixbrl_' . $name . '_yes';
        $noId = 'ixbrl_' . $name . '_no';
        $normalised = $value === null || $value === '' ? null : (int)$value;

        return '<fieldset class="panel-soft">
            <legend>' . \eel_accounts\Support\Utf8::html($label) . '</legend>
            ' . ($helper !== '' ? '<div class="helper ixbrl-question-helper">' . \eel_accounts\Support\Utf8::html($helper) . '</div>' : '') . '
            <div class="actions-row">
                <label for="' . $yesId . '"><input id="' . $yesId . '" type="radio" name="' . \eel_accounts\Support\Utf8::html($name) . '" value="1" required data-ixbrl-disclosure-control="true" data-ixbrl-approval-control="true"' . ($normalised === 1 ? ' checked' : '') . ($disabled ? ' disabled aria-disabled="true"' : '') . '> Yes</label>
                <label for="' . $noId . '"><input id="' . $noId . '" type="radio" name="' . \eel_accounts\Support\Utf8::html($name) . '" value="0" required data-ixbrl-disclosure-control="true" data-ixbrl-approval-control="true"' . ($normalised === 0 ? ' checked' : '') . ($disabled ? ' disabled aria-disabled="true"' : '') . '> No</label>
            </div>
            ' . $this->lockedMirror($name, $normalised === null ? '' : $normalised, $disabled) . '
        </fieldset>';
    }

    private function disclosuresMatchApproval(array $status, array $display): bool
    {
        $approval = is_array($status['approval'] ?? null) ? (array)$status['approval'] : [];
        $basis = json_decode((string)($approval['basis_json'] ?? ''), true);
        $approved = is_array($basis)
            ? (array)(($basis['disclosures'] ?? [])['values'] ?? [])
            : [];
        if ($approved === []) {
            return false;
        }

        foreach (self::APPROVED_DISCLOSURE_FIELDS as $field) {
            $approvedValue = $approved[$field] ?? null;
            $currentValue = $display[$field] ?? null;
            if (is_string($approvedValue) || is_string($currentValue)) {
                if (trim((string)$approvedValue) !== trim((string)$currentValue)) {
                    return false;
                }
                continue;
            }
            if ($approvedValue !== $currentValue) {
                return false;
            }
        }

        return true;
    }

    private function directorLoanDisclosure(array $summary): string
    {
        if (empty($summary['success'])) {
            return '<fieldset class="panel-soft"><legend>Director or Participant Advances and Credits requiring disclosure</legend><div class="helper">Unable to calculate the chronological Director Loan Statement.</div></fieldset>';
        }

        $hasExposure = !empty($summary['has_company_to_director_exposure']);
        $detail = $hasExposure
            ? 'Maximum company-to-director exposure: £' . number_format((float)($summary['disclosures'][0]['maximum_company_to_director_exposure'] ?? 0), 2)
                . '; advances: £' . number_format((float)($summary['total_advances'] ?? 0), 2)
                . '; settled: £' . number_format((float)($summary['total_repayments'] ?? 0), 2) . '.'
            : 'The chronological running balance never became negative for any attributed director.';

        $evidenceRows = '';
        foreach ((array)($summary['director_evidence'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $evidenceRows .= '<tr><th class="description" scope="row">'
                . \eel_accounts\Support\Utf8::html((string)($row['director_name'] ?? 'Director'))
                . '</th><td class="amount">£'
                . number_format((float)($row['advances'] ?? 0), 2)
                . '</td></tr>';
        }
        $evidence = $evidenceRows === '' ? ''
            : '<table class="note-table"><thead><tr><th class="description" scope="col">Director</th>'
                . '<th class="amount" scope="col">Advances during period</th></tr></thead><tbody>'
                . $evidenceRows . '</tbody></table>';

        return '<fieldset class="panel-soft">
            <legend>Director or Participant Advances and Credits requiring disclosure</legend>
            <div class="helper">Automatically calculated from the chronological Director Loan Statement. ' . \eel_accounts\Support\Utf8::html($detail) . '</div>
            ' . $evidence . '
        </fieldset>';
    }

    private function tradingStatusAnswers(string $status): array
    {
        return match ($status) {
            'trading' => ['is_still_trading' => 1, 'has_ever_traded' => 1],
            'no_longer_trading' => ['is_still_trading' => 0, 'has_ever_traded' => 1],
            'never_traded' => ['is_still_trading' => 0, 'has_ever_traded' => 0],
            default => ['is_still_trading' => null, 'has_ever_traded' => null],
        };
    }

    private function tradingEvidenceSummary(array $evidence): string
    {
        $labels = array_values(array_filter(array_map(
            static fn(array $source): string => trim((string)($source['label'] ?? '')),
            (array)($evidence['sources'] ?? [])
        ), static fn(string $label): bool => $label !== ''));

        return $labels !== [] ? implode(', ', array_unique($labels)) : 'the available accounting history';
    }

    private function nullableValue(mixed $value): string
    {
        return $value === null || $value === '' ? '' : (string)(int)$value;
    }

    private function option(string $value, string $label, mixed $selected): string
    {
        return '<option value="' . \eel_accounts\Support\Utf8::html($value) . '"'
            . ((string)$selected === $value ? ' selected' : '')
            . '>' . \eel_accounts\Support\Utf8::html($label) . '</option>';
    }

    private function sourceSummary(array $sources, bool $stored): string
    {
        if ($stored || $sources === []) {
            return '';
        }

        $filingDates = [];
        foreach ($sources as $source) {
            $date = trim((string)($source['filing_date'] ?? ''));
            if ($date !== '') {
                $filingDates[] = $date;
            }
        }
        $filingDates = array_values(array_unique($filingDates));

        return '<div class="helper"><span class="badge info">Suggested</span> The form has been prefilled from the matching stored Companies House iXBRL filing'
            . ($filingDates !== [] ? ' dated ' . \eel_accounts\Support\Utf8::html(implode(', ', $filingDates)) : '')
            . '. Review the suggested core details and save them explicitly before facts can be built.</div>';
    }
}
