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
        $missing = (array)($result['missing_labels'] ?? []);
        $periodEnd = (string)(($result['accounting_period'] ?? [])['period_end'] ?? '');
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
        $dormancyLabel = $dormancyCalculated
            ? ((int)($dormancy['entity_dormant'] ?? 0) === 1
                ? 'Dormant during Accounting Period'
                : 'Not Dormant during Accounting Period')
            : 'Not available';
        $dormancyDetail = $dormancyCalculated
            ? 'Based on gross posted sales of ' . number_format((float)($dormancy['gross_sales'] ?? 0), 2) . ' on '
                . (string)($dormancy['sales_nominal_code'] ?? 'the configured Sales nominal') . '.'
            : (string)($dormancy['error'] ?? 'Configure a default Sales nominal to calculate this status.');
        $smallCompanies = (array)($result['small_companies_regime'] ?? []);
        $smallCompaniesAvailable = !empty($smallCompanies['available']);
        $smallCompaniesLabel = $smallCompaniesAvailable
            ? (!empty($smallCompanies['qualifies']) ? 'Yes' : 'No')
            : 'Not available';
        $smallCompaniesDetail = $smallCompaniesAvailable
            ? (new \eel_accounts\Service\IxbrlMicroEntityEligibilityService())->detail($smallCompanies)
            : (string)($smallCompanies['error'] ?? 'Enter the accounting figures and refresh to calculate this status.');
        $thresholdPeriod = (array)($smallCompanies['threshold_effective_period'] ?? []);
        $thresholdMeta = '';
        if ($smallCompaniesAvailable) {
            $thresholdMeta = '<div class="helper">Source: ' . HelperFramework::escape((string)($smallCompanies['threshold_source'] ?? ''))
                . '; effective from ' . HelperFramework::escape((string)($thresholdPeriod['start'] ?? ''))
                . ' to ' . HelperFramework::escape((string)($thresholdPeriod['end'] ?? 'current'))
                . '; checked ' . HelperFramework::escape((string)($smallCompanies['threshold_source_checked_at'] ?? '')) . '.</div>';
        }

        return '<div class="settings-stack">
            <section class="panel-soft">
                <div class="status-head">
                    <h3 class="card-title">Period-specific filing statements</h3>
                    <span class="badge ' . ($complete ? 'success' : 'danger') . '">' . ($complete ? 'Complete' : 'Required') . '</span>
                </div>
                ' . $sourceSummary . '
                ' . ($missing !== []
                    ? '<div class="helper">Still required: ' . HelperFramework::escape(implode(', ', $missing)) . '.</div>'
                    : '<div class="helper">Last updated ' . HelperFramework::escape((string)($disclosures['updated_at'] ?? '')) . ' by ' . HelperFramework::escape((string)($disclosures['updated_by'] ?? '')) . '.</div>') . '
                ' . $profileErrors . '
            </section>
            ' . (!$yearEndLocked
                ? '<div class="standout helper">Complete and lock Year End before confirming the accounts disclosures.</div>'
                : '') . '
            <form method="post" action="?page=ixbrl_builder" data-ajax="true" data-ixbrl-trading-form="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Ixbrl">
                <input type="hidden" name="intent" value="save_ixbrl_disclosures">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="accounting_standard" value="FRS_105">
                <section class="panel-soft">
                    <div class="form-grid">
                    <div class="form-row">
                        <label>Accounting standard</label>
                        <input class="input" value="FRS 105" readonly' . $disabledAttribute . '>
                    </div>
                    <div class="form-row">
                        <label for="ixbrl_average_number_employees">Average number of employees</label>
                        <input class="input" id="ixbrl_average_number_employees" name="average_number_employees" type="number" min="0" step="1" required value="' . HelperFramework::escape($this->nullableValue($display['average_number_employees'] ?? null)) . '"' . $disabledAttribute . '>
                    </div>
                    <div class="form-row">
                        <label for="ixbrl_accounts_approval_date">Accounts approval date</label>
                        <div class="actions-row actions-row-nowrap">
                            <input class="input" id="ixbrl_accounts_approval_date" name="accounts_approval_date" type="date" required value="' . HelperFramework::escape((string)($display['accounts_approval_date'] ?? '')) . '"' . $disabledAttribute . '>
                            <button class="button primary" type="button" data-set-today-for="ixbrl_accounts_approval_date"' . $disabledAttribute . '>Today</button>
                        </div>
                    </div>
                    <div class="form-row full">
                        ' . $this->yesNo(
                            'is_still_trading',
                            'Was the company still trading on ' . ($periodEnd !== '' ? $periodEnd : 'the accounting period end') . '?',
                            $tradingAnswers['is_still_trading'] ?? null,
                            $controlDisabled
                        ) . '
                        ' . ($hasTradingEvidence
                            ? '<div class="helper">Previous trading is evidenced automatically by ' . HelperFramework::escape($this->tradingEvidenceSummary($tradingEvidence)) . '. A No answer will therefore be stored as No longer trading.</div>'
                            : '<div data-ixbrl-ever-traded-panel="true">'
                                . $this->yesNo(
                                    'has_ever_traded',
                                    'Has the company ever traded?',
                                    $tradingAnswers['has_ever_traded'] ?? null,
                                    $controlDisabled
                                )
                                . '</div>') . '
                        <div class="helper">Trading status is calculated from these answers and available ledger or filed-account evidence. Dormancy is assessed separately.</div>
                    </div>
                    <div class="form-row">
                        <label for="ixbrl_approving_director_name">Approving director</label>
                        <select class="select" id="ixbrl_approving_director_name" name="approving_director_name" required' . $disabledAttribute . '>
                            ' . $directorOptions . '
                        </select>
                    </div>
                    </div>
                    <div class="actions-row"><button class="button primary" type="submit"' . $disabledAttribute . '>Save Accounts Disclosures</button></div>
                </section>
                <div class="settings-stack">
                    <section class="panel-soft">
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
                        <div class="helper">Calculated from the accounting-period turnover, balance-sheet total, and average employees; all three FRS 105 tests are required. This result is read-only.</div>
                        <div class="helper">' . HelperFramework::escape($smallCompaniesDetail) . '</div>
                        ' . $thresholdMeta . '
                        ' . $this->yesNo('audit_exempt_section_477', 'Is the company claiming audit exemption under section 477 of the Companies Act 2006?', $display['audit_exempt_section_477'] ?? null, $controlDisabled) . '
                        ' . $this->yesNo('directors_acknowledge_responsibilities', 'Do the directors acknowledge their Companies Act responsibilities for the records and accounts?', $display['directors_acknowledge_responsibilities'] ?? null, $controlDisabled) . '
                        ' . $this->yesNo('members_have_not_required_audit', 'Have the members not required an audit under section 476?', $display['members_have_not_required_audit'] ?? null, $controlDisabled) . '
                    </section>
                    <section class="panel-soft">
                        <h4 class="card-title">Eligibility and accounting basis</h4>
                        <div class="helper">Confirm both answers directly; they are not inferred or prefilled from Companies House. The current profile requires Yes. A No answer is saved and audited, but blocks the facts build because that accounts profile is not yet supported.</div>
                        ' . $this->yesNo('micro_entity_eligibility_confirmed', 'Is the company eligible to prepare these accounts as a micro-entity?', $display['micro_entity_eligibility_confirmed'] ?? null, $controlDisabled) . '
                        ' . $this->yesNo('going_concern_basis_appropriate', 'Is the going-concern basis appropriate for these accounts?', $display['going_concern_basis_appropriate'] ?? null, $controlDisabled) . '
                    </section>
                    <section class="panel-soft">
                        <h4 class="card-title">FRS 105 simple-note scope</h4>
                        <div class="helper">Answer each question explicitly. These answers are not inferred or prefilled from Companies House. No is supported by the current simple-note profile. Yes is saved, but blocks the facts build until the required note detail and taxonomy tagging are supported.</div>
                        ' . $this->yesNo('has_material_off_balance_sheet_arrangements', 'Are there any material off-balance-sheet arrangements requiring disclosure?', $display['has_material_off_balance_sheet_arrangements'] ?? null, $controlDisabled) . '
                        ' . $this->yesNo('has_director_advances_credits_or_guarantees', 'Were there any director advances, credits or guarantees requiring disclosure?', $display['has_director_advances_credits_or_guarantees'] ?? null, $controlDisabled) . '
                        ' . $this->yesNo('has_financial_commitments_guarantees_or_contingencies', 'Are there any financial commitments, guarantees or contingencies requiring disclosure?', $display['has_financial_commitments_guarantees_or_contingencies'] ?? null, $controlDisabled) . '
                    </section>
                </div>
            </form>
        </div>';
    }

    private function yesNo(string $name, string $label, mixed $value, bool $disabled = false): string
    {
        $yesId = 'ixbrl_' . $name . '_yes';
        $noId = 'ixbrl_' . $name . '_no';
        $normalised = $value === null || $value === '' ? null : (int)$value;

        return '<fieldset class="panel-soft">
            <legend>' . HelperFramework::escape($label) . '</legend>
            <div class="actions-row">
                <label for="' . $yesId . '"><input id="' . $yesId . '" type="radio" name="' . HelperFramework::escape($name) . '" value="1" required' . ($normalised === 1 ? ' checked' : '') . ($disabled ? ' disabled aria-disabled="true"' : '') . '> Yes</label>
                <label for="' . $noId . '"><input id="' . $noId . '" type="radio" name="' . HelperFramework::escape($name) . '" value="0" required' . ($normalised === 0 ? ' checked' : '') . ($disabled ? ' disabled aria-disabled="true"' : '') . '> No</label>
            </div>
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
            . '. Review every value and save it explicitly before facts can be built.</div>';
    }
}
