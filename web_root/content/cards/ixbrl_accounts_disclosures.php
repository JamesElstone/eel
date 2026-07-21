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
            'key' => 'corporation_tax_filing_scope',
            'service' => \eel_accounts\Service\CorporationTaxFilingScopeService::class,
            'method' => 'fetch',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ], [
            'key' => 'ixbrl_filing_approval',
            'service' => \eel_accounts\Service\IxbrlAccountsFilingApprovalService::class,
            'method' => 'status',
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
        $filingScope = (array)($context['services']['corporation_tax_filing_scope'] ?? []);
        $approvalStatus = (array)($context['services']['ixbrl_filing_approval'] ?? []);
        foreach (self::EXPLICIT_SIMPLE_NOTE_FIELDS as $field) {
            unset($suggestions[$field], $suggestionSources[$field]);
        }
        $display = !empty($result['stored'])
            ? $disclosures
            : array_replace($disclosures, $suggestions);

        if (empty($result['available'])) {
            $errors = (array)($result['errors'] ?? ['Select a valid company and accounting period.']);
            return '<div class="standout helper">' . HelperFramework::escape(implode(' ', $errors)) . '</div>';
        }

        $complete = !empty($result['complete']);
        $yearEndLocked = !empty($result['year_end_locked']);
        $controlDisabled = !$yearEndLocked;
        $disabledAttribute = $controlDisabled ? ' disabled aria-disabled="true"' : '';
        $coreButtonDisabled = ' disabled' . ($controlDisabled ? ' aria-disabled="true"' : '');
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
        $directorOptions = '<option value="">Select approving director</option>';
        foreach ((array)($result['director_suggestions'] ?? []) as $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $directorOptions .= '<option value="' . HelperFramework::escape($name) . '"'
                . ((string)($display['approving_director_name'] ?? '') === $name ? ' selected' : '')
                . '>' . HelperFramework::escape($name) . '</option>';
        }
        $sourceSummary = $this->sourceSummary($suggestionSources, !empty($result['stored']));
        $profileErrors = '';
        foreach ((array)($result['profile_errors'] ?? []) as $profileError) {
            $profileErrors .= '<div class="standout helper">'
                . HelperFramework::escape((string)$profileError)
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
                : '<a class="button" href="' . HelperFramework::escape($sourceUrl) . '" target="_blank" rel="noopener noreferrer">GOV.UK guidance</a>';
            $thresholdStart = trim((string)($thresholdPeriod['start'] ?? ''));
            $thresholdEnd = trim((string)($thresholdPeriod['end'] ?? ''));
            $thresholdDates = $formatCompanyDate($thresholdStart) . ' to '
                . ($thresholdEnd !== '' ? $formatCompanyDate($thresholdEnd) : 'Current');
            $checkedAt = $formatCompanyDate(trim((string)($smallCompanies['threshold_source_checked_at'] ?? '')));
            $smallCompaniesSummary = '<div class="ixbrl-small-companies-detail table-scroll"><table><thead><tr>
                <th>FRS 105 tests</th><th>Turnover</th><th>Balance sheet total</th><th>Average employees</th><th>Source</th><th>Validity Period</th><th>Last Checked</th>
            </tr></thead><tbody><tr>
                <td>' . (int)($smallCompanies['pass_count'] ?? 0) . ' of 3 passed; all required</td>
                <td>' . HelperFramework::escape($testValue('turnover')) . '<div class="helper">Base ' . HelperFramework::escape($money($baseThresholds['turnover'] ?? 0)) . '; ' . (int)($smallCompanies['period_days'] ?? 0) . ' days</div></td>
                <td>' . HelperFramework::escape($testValue('balance_sheet_total')) . '</td>
                <td>' . (int)($metrics['employees'] ?? 0) . ' / ' . (int)($thresholds['employees'] ?? 0) . ' (' . (!empty($passes['employees']) ? 'Pass' : 'Fail') . ')</td>
                <td>' . $source . '</td>
                <td>' . HelperFramework::escape($thresholdDates) . '</td>
                <td>' . HelperFramework::escape($checkedAt) . '</td>
            </tr></tbody></table></div>';
        } else {
            $smallCompaniesSummary = '<div class="helper">' . HelperFramework::escape((string)($smallCompanies['error'] ?? 'Enter the accounting figures and refresh to calculate this status.')) . '</div>';
        }
        $updatedAt = trim((string)($disclosures['updated_at'] ?? ''));
        $updatedBy = trim((string)($result['updated_by_display_name'] ?? ''));
        $updatedAtDisplay = $updatedAt !== '' ? $updatedAt : 'Not yet saved';
        $updatedByDisplay = $updatedBy !== '' ? $updatedBy : 'Not yet saved';
        return '<div class="settings-stack">
            <form method="post" action="?page=disclosures" data-ajax="true" data-ixbrl-trading-form="true">
            <input type="hidden" name="card_action" value="Ixbrl">
            ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="intent" value="save_ixbrl_core_details">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <input type="hidden" name="accounting_standard" value="FRS_105">
            <section class="panel-soft">
                <div class="status-head">
                    <h3 class="card-title">Account Period Basic Information</h3>
                    <span class="badge ' . ($complete ? 'success' : 'danger') . '">' . ($complete ? 'Complete' : 'Required') . '</span>
                </div>
                ' . $sourceSummary . '
                ' . ($missing !== []
                    ? '<div class="helper">Still required: ' . HelperFramework::escape(implode(', ', $missing)) . '.</div>'
                    : '') . '
                ' . $profileErrors . '
            ' . (!$yearEndLocked
                ? '<div class="standout helper">Complete and lock Year End before confirming the accounts disclosures.</div>'
                : '') . '
                    <div class="form-grid" data-state-fields="ixbrl_average_number_employees,ixbrl_accounts_approval_date,ixbrl_approving_director_name" data-state-target="save_ixbrl_core_details">
                    <div class="form-row full table-scroll">
                        <table><tbody>
                            <tr><th scope="row"><label>Accounting standard</label></th><td><input class="input" value="FRS 105" readonly' . $disabledAttribute . '></td></tr>
                            <tr><th scope="row"><label for="ixbrl_average_number_employees">Average number of employees</label></th><td><input class="input" id="ixbrl_average_number_employees" name="average_number_employees" type="number" min="0" step="1" required value="' . HelperFramework::escape($this->nullableValue($display['average_number_employees'] ?? null)) . '" data-state-default="' . HelperFramework::escape($this->nullableValue($display['average_number_employees'] ?? null)) . '"' . $disabledAttribute . '></td></tr>
                            <tr><th scope="row"><label for="ixbrl_accounts_approval_date">Accounts approval date</label></th><td><div class="actions-row actions-row-nowrap"><input class="input" id="ixbrl_accounts_approval_date" name="accounts_approval_date" type="date" required value="' . HelperFramework::escape((string)($display['accounts_approval_date'] ?? '')) . '" data-state-default="' . HelperFramework::escape((string)($display['accounts_approval_date'] ?? '')) . '"' . $disabledAttribute . '><button class="button primary" type="button" data-set-today-for="ixbrl_accounts_approval_date"' . $disabledAttribute . '>Today</button></div></td></tr>
                            <tr><th scope="row">Last updated on</th><td>' . HelperFramework::escape($updatedAtDisplay) . '</td></tr>
                            <tr><th scope="row">Last updated by</th><td>' . HelperFramework::escape($updatedByDisplay) . '</td></tr>
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
                        <div class="helper">If a company is marked as not trading on ' . HelperFramework::escape($periodEndDisplay) . ', it automatically calculates Never Traded versus No Longer Trading status based on any historical Sales posted.</div>
                    </div>
                    <div class="form-row full">
                        <div class="actions-row actions-row-nowrap ixbrl-core-details-actions">
                            <div class="mini-field">
                                <label for="ixbrl_approving_director_name">Approving Director</label>
                                <select class="select" id="ixbrl_approving_director_name" name="approving_director_name" required data-state-default="' . HelperFramework::escape((string)($display['approving_director_name'] ?? '')) . '"' . $disabledAttribute . '>
                                    ' . $directorOptions . '
                                </select>
                            </div>
                            <button class="button primary" id="save_ixbrl_core_details" type="submit"' . $coreButtonDisabled . '>Save Basic Information</button>
                        </div>
                    </div>
                    </div>
                </section>
            </form>
                <div class="settings-stack">
                    <section class="panel-soft ixbrl-dormancy-summary">
                        <div class="status-head">
                            <h4 class="card-title">Was the company dormant for this accounting period?</h4>
                        </div>
                        <div class="helper">Automatically calculated from posted credits to the configured Sales nominal. ' . HelperFramework::escape($dormancyDetail) . '</div>
                        <div class="card-title">' . HelperFramework::escape($dormancyLabel) . '</div>
                    </section>
                    <section class="panel-soft">
                        <div class="status-head">
                            <h4 class="card-title">Were these accounts prepared under the small companies regime?</h4>
                            <span class="badge ' . ($smallCompaniesAvailable && !empty($smallCompanies['qualifies']) ? 'success' : 'danger') . '">' . HelperFramework::escape($smallCompaniesLabel) . '</span>
                        </div>
                        <div class="ixbrl-small-companies-detail">
                            ' . $smallCompaniesSummary . '
                        </div>
                        ' . $this->yesNo('audit_exempt_section_477', 'Is the company claiming audit exemption under section 477 of the Companies Act 2006?', $display['audit_exempt_section_477'] ?? null, $controlDisabled, true, $companyId, $accountingPeriodId) . '
                        ' . $this->yesNo('directors_acknowledge_responsibilities', 'Do the directors acknowledge their Companies Act responsibilities for the records and accounts?', $display['directors_acknowledge_responsibilities'] ?? null, $controlDisabled, true, $companyId, $accountingPeriodId) . '
                        ' . $this->yesNo('members_have_not_required_audit', 'Do the relevant business voting parties confirm that no audit is required under section 476?', $display['members_have_not_required_audit'] ?? null, $controlDisabled, true, $companyId, $accountingPeriodId) . '
                    </section>
                    <section class="panel-soft">
                        <h4 class="card-title">Eligibility and accounting basis</h4>
                        <div class="helper ixbrl-eligibility-helper">Sending of Accounts and Returns using this software will be blocked if either of the following two questions are No, as they are not supported.</div>
                        ' . $this->yesNo('micro_entity_eligibility_confirmed', 'Is the company eligible to prepare these accounts as a micro-entity?', $display['micro_entity_eligibility_confirmed'] ?? null, $controlDisabled, true, $companyId, $accountingPeriodId) . '
                        ' . $this->yesNo('going_concern_basis_appropriate', 'Is the business still a going-concern and continue to operate for the foreseeable future?', $display['going_concern_basis_appropriate'] ?? null, $controlDisabled, true, $companyId, $accountingPeriodId) . '
                    </section>
                    <section class="panel-soft">
                        <h4 class="card-title ixbrl-frs105-notes-title">FRS 105 Notes</h4>
                        ' . $this->yesNo('has_material_off_balance_sheet_arrangements', 'Are there any material off-balance-sheet arrangements requiring disclosure?', $display['has_material_off_balance_sheet_arrangements'] ?? null, $controlDisabled, true, $companyId, $accountingPeriodId, 'Director and Participant Advances are calculated automatically from transactions. This is confirming no other legal agreements exist which create a liability.') . '
                        ' . $this->directorLoanDisclosure($directorLoanDisclosure) . '
                        ' . $this->yesNo('has_director_advances_credits_or_guarantees', 'Were there any director guarantees requiring disclosure?', $display['has_director_advances_credits_or_guarantees'] ?? null, $controlDisabled, true, $companyId, $accountingPeriodId) . '
                        ' . $this->yesNo('has_financial_commitments_guarantees_or_contingencies', 'Are there any financial commitments, guarantees or contingencies requiring disclosure?', $display['has_financial_commitments_guarantees_or_contingencies'] ?? null, $controlDisabled, true, $companyId, $accountingPeriodId) . '
                    </section>
                </div>
                ' . $this->corporationTaxScope($filingScope, $companyId, $accountingPeriodId) . '
                ' . $this->approvalPanel($approvalStatus, $companyId, $accountingPeriodId) . '
        </div>';
    }

    private function corporationTaxScope(array $scope, int $companyId, int $accountingPeriodId): string
    {
        if (empty($scope['available'])) {
            return '<section class="panel-soft"><h3 class="card-title">Corporation Tax filing scope</h3><div class="standout helper">'
                . HelperFramework::escape((string)(($scope['errors'] ?? [])[0] ?? 'The Corporation Tax scope review is unavailable.')) . '</div></section>';
        }
        $answers = (array)($scope['answers'] ?? []);
        $rows = '';
        foreach ((array)($scope['definitions'] ?? []) as $key => $definition) {
            $answer = (string)($answers[$key] ?? 'unresolved');
            $rows .= '<form class="panel-soft" method="post" action="?page=disclosures" data-ajax="true">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="card_action" value="Ixbrl"><input type="hidden" name="intent" value="save_ct_filing_scope_answer">'
                . '<input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
                . '<input type="hidden" name="scope_field" value="' . HelperFramework::escape((string)$key) . '">'
                . '<div class="status-head"><div><strong>' . HelperFramework::escape((string)$definition['page']) . ': ' . HelperFramework::escape((string)$definition['label']) . '</strong>'
                . '<div class="helper">' . HelperFramework::escape((string)$definition['question']) . '</div></div>'
                . '<a class="button button-inline" target="_blank" rel="noopener noreferrer" href="' . HelperFramework::escape((string)$definition['url']) . '">HMRC guidance</a></div>'
                . '<div class="actions-row">' . $this->scopeRadio((string)$key, 'no', 'No', $answer)
                . $this->scopeRadio((string)$key, 'yes', 'Yes', $answer)
                . $this->scopeRadio((string)$key, 'unsure', 'Unsure', $answer) . '</div></form>';
        }
        $status = !empty($scope['complete'])
            ? '<span class="badge success">Confirmed in scope</span>'
            : '<span class="badge danger">Incomplete or unsupported</span>';
        return '<section class="panel-soft settings-stack"><div class="status-head"><h3 class="card-title">Corporation Tax filing scope</h3>' . $status . '</div>'
            . '<div class="helper">CT600A is supported separately. Confirm that none of the other supplementary-page circumstances applies. A Yes or Unsure answer blocks filing.</div>'
            . $rows . '</section>';
    }

    private function scopeRadio(string $field, string $value, string $label, string $selected): string
    {
        $id = 'ct_scope_' . $field . '_' . $value;
        return '<label for="' . $id . '"><input id="' . $id . '" type="radio" name="scope_answer" value="' . $value
            . '" required data-submit-on-change="true"' . ($selected === $value ? ' checked' : '') . '> ' . $label . '</label>';
    }

    private function approvalPanel(array $status, int $companyId, int $accountingPeriodId): string
    {
        $state = (string)($status['state'] ?? 'absent');
        $approval = is_array($status['approval'] ?? null) ? (array)$status['approval'] : [];
        $current = $state === 'current';
        $label = $current ? 'Current' : ($state === 'stale' ? 'Stale' : 'Not approved');
        $badge = $current ? 'success' : 'danger';
        $detail = $current
            ? 'The approved basis matches the current Year End lock, disclosures, accounts mapping and CT calculation seals.'
            : ($state === 'stale'
                ? 'The previous approval is retained as evidence, but the current filing basis has changed.'
                : 'Approve the complete filing basis to create immutable CT bases and the accounts fact snapshot.');
        $evidence = '';
        if ($approval !== []) {
            $evidence = '<div class="helper">' . ($current ? 'Approval #' : 'Previous approval #') . (int)$approval['id']
                . ' by ' . HelperFramework::escape((string)$approval['approved_by'])
                . ' at ' . HelperFramework::escape((string)$approval['approved_at'])
                . '; disclosure revision ' . (int)$approval['disclosure_revision']
                . '; basis ' . HelperFramework::escape(substr((string)$approval['basis_hash'], 0, 16)) . '…</div>';
        }
        $errors = '';
        foreach ((array)($status['errors'] ?? []) as $error) {
            $errors .= '<div class="standout helper">' . HelperFramework::escape((string)$error) . '</div>';
        }
        $disabled = empty($status['can_approve']) ? ' disabled aria-disabled="true"' : '';

        return '<section class="panel-soft ixbrl-approval-panel">
            <div class="status-head"><h3 class="card-title">Disclosure Approval</h3><span class="badge ' . $badge . '">' . $label . '</span></div>
            <div class="helper ixbrl-approval-detail">' . HelperFramework::escape($detail) . '</div>' . $evidence . $errors . '
            <form method="post" action="?page=disclosures" data-ajax="true">
                <input type="hidden" name="card_action" value="Ixbrl">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="intent" value="approve_ixbrl_accounts_filing_basis">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <div class="form-row"><label for="ixbrl_filing_approval_note">Approval note (optional)</label>
                    <textarea class="input" id="ixbrl_filing_approval_note" name="approval_note" rows="2"></textarea></div>
                <div class="helper ixbrl-approval-confirmation">I here by confirm that the information on this page is a true and accurate reflection of this business.</div>
                <div class="actions-row"><button class="button primary" type="submit"' . $disabled . '>I Approve this Statement of Fact</button></div>
            </form>
        </section>';
    }

    private function yesNo(string $name, string $label, mixed $value, bool $disabled = false, bool $ajaxField = false, int $companyId = 0, int $accountingPeriodId = 0, string $helper = ''): string
    {
        $yesId = 'ixbrl_' . $name . '_yes';
        $noId = 'ixbrl_' . $name . '_no';
        $normalised = $value === null || $value === '' ? null : (int)$value;
        $submitOnChange = $ajaxField ? ' data-submit-on-change="true"' : '';

        $fieldset = '<fieldset class="panel-soft">
            <legend>' . HelperFramework::escape($label) . '</legend>
            ' . ($helper !== '' ? '<div class="helper ixbrl-question-helper">' . HelperFramework::escape($helper) . '</div>' : '') . '
            <div class="actions-row">
                <label for="' . $yesId . '"><input id="' . $yesId . '" type="radio" name="' . HelperFramework::escape($name) . '" value="1" required' . ($normalised === 1 ? ' checked' : '') . ($disabled ? ' disabled aria-disabled="true"' : '') . $submitOnChange . '> Yes</label>
                <label for="' . $noId . '"><input id="' . $noId . '" type="radio" name="' . HelperFramework::escape($name) . '" value="0" required' . ($normalised === 0 ? ' checked' : '') . ($disabled ? ' disabled aria-disabled="true"' : '') . $submitOnChange . '> No</label>
            </div>
        </fieldset>';
        if (!$ajaxField) {
            return $fieldset;
        }

        return '<form method="post" action="?page=disclosures" data-ajax="true">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="Ixbrl">
                <input type="hidden" name="intent" value="save_ixbrl_disclosure_field">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="disclosure_field" value="' . HelperFramework::escape($name) . '">'
            . $fieldset
            . '</form>';
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

        return '<fieldset class="panel-soft">
            <legend>Director or Participant Advances and Credits requiring disclosure</legend>
            <div class="helper">Automatically calculated from the chronological Director Loan Statement. ' . HelperFramework::escape($detail) . '</div>
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
        return '<option value="' . HelperFramework::escape($value) . '"'
            . ((string)$selected === $value ? ' selected' : '')
            . '>' . HelperFramework::escape($label) . '</option>';
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
            . ($filingDates !== [] ? ' dated ' . HelperFramework::escape(implode(', ', $filingDates)) : '')
            . '. Review the suggested core details and save them explicitly before facts can be built.</div>';
    }
}
