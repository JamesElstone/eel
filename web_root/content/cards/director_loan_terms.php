<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _director_loan_termsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'director_loan_terms';
    }

    public function title(): string
    {
        return 'Terms';
    }

    public function helper(array $context): string
    {
        return 'Record the statutory repayment presentation and supporting director-loan terms for the selected period.';
    }

    public function services(): array
    {
        return [[
            'key' => 'directorLoanReportingPresentation',
            'service' => \eel_accounts\Service\DirectorLoanReportingPresentationService::class,
            'method' => 'fetchPresentation',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ]];
    }

    protected function additionalInvalidationFacts(): array
    {
        return [
            'year.end.director.loan.offset',
            'year.end.checklist',
            'companies.house.snapshot',
            'year.end.companies.house.comparison',
            'ixbrl.readiness',
            'ixbrl.accounts.mapping',
            'ixbrl.facts.preview',
            'ixbrl.generation',
        ];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $presentation = (array)($context['services']['directorLoanReportingPresentation'] ?? []);
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);

        if (empty($presentation['success'])) {
            return '<section class="panel-soft settings-stack"><div class="eyebrow">Statutory repayment presentation</div>'
                . $this->errors((array)($presentation['errors'] ?? ['The Director Loan reporting presentation is unavailable.']))
                . '</section>';
        }

        $classification = (string)($presentation['requested_classification']
            ?? $presentation['classification']
            ?? \eel_accounts\Service\DirectorLoanReportingPresentationService::WITHIN_ONE_YEAR);
        $withinOneYear = \eel_accounts\Service\DirectorLoanReportingPresentationService::WITHIN_ONE_YEAR;
        $afterMoreThanOneYear = \eel_accounts\Service\DirectorLoanReportingPresentationService::AFTER_MORE_THAN_ONE_YEAR;
        $nominal = (array)($presentation['liability_nominal'] ?? []);
        $nominalLabel = trim((string)($nominal['code'] ?? '') . ' - ' . (string)($nominal['name'] ?? ''), ' -');
        $schemaReady = !empty($presentation['schema_ready']);
        $isLocked = !empty($presentation['is_locked']);
        $lockedHtml = $isLocked ? '<span class="badge warning">Period locked - reporting choice is read only</span>' : '';
        $basisHtml = !empty($presentation['explicit'])
            ? '<span class="badge success">Saved reporting choice</span>'
            : '<span class="badge muted">Default: within one year</span>';
        $schemaHtml = $schemaReady ? '' : '<div class="helper">The reporting-presentation database migration must be applied before this choice can be saved.</div>';
        $currentNominal = (array)($presentation['current_liability_nominal'] ?? []);
        $currentNominalLabel = trim((string)($currentNominal['code'] ?? '') . ' - ' . (string)($currentNominal['name'] ?? ''), ' -');
        $mappingHtml = !empty($presentation['nominal_mapping_changed'])
            ? '<div class="helper"><span class="badge warning">Historic nominal retained</span> This period remains tied to '
                . HelperFramework::escape($nominalLabel)
                . ($currentNominalLabel !== '' ? ', while current Company Nominals points to ' . HelperFramework::escape($currentNominalLabel) : '')
                . '. This prevents a later settings change from rewriting the period\'s statutory presentation.</div>'
            : '';
        $disabled = $isLocked ? ' disabled' : '';
        $setOffRightChecked = !empty($presentation['set_off_right_confirmed']) ? ' checked' : '';
        $setOffIntentionChecked = !empty($presentation['set_off_net_settlement_intended']) ? ' checked' : '';
        $defermentChecked = !empty($presentation['deferment_right_confirmed']) ? ' checked' : '';
        $validationErrors = (array)($presentation['validation_errors'] ?? []);
        $validationHtml = $validationErrors !== []
            ? '<div class="panel-soft warn"><div class="helper">' . implode('<br>', array_map(
                static fn(mixed $error): string => HelperFramework::escape((string)$error),
                $validationErrors
            )) . ' The effective statutory classification remains due within one year until this is corrected.</div></div>'
            : '';
        $periodEnd = (string)(($presentation['accounting_period'] ?? [])['period_end'] ?? '');
        $periodEndLabel = $periodEnd !== '' ? HelperFramework::displayDate($periodEnd) : 'the balance-sheet date';

        return '<section class="panel-soft settings-stack director-loan-reporting-presentation">
            <div class="status-head"><div><div class="eyebrow">Statutory Repayment Presentation</div><h3 class="card-title">When is money lent to the company due back?</h3></div><div class="pill-row">' . $basisHtml . $lockedHtml . '</div></div>
            <div class="helper">This applies to the full gross balance in ' . HelperFramework::escape($nominalLabel !== '' ? $nominalLabel : 'the Director Loan Liability control account') . '. It changes only the Companies House and iXBRL presentation; it does not alter journals, transactions, balances, nominal accounts, or the Year End lock. Balances are never netted merely because they relate to the same party.</div>
            ' . $validationHtml . '
            <form method="post" data-ajax="true" class="settings-stack">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="DirectorLoan">
                <input type="hidden" name="intent" value="save_director_loan_reporting_presentation">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <div class="segmented-control">
                    <label class="segmented-option"><input type="radio" name="classification" value="' . $withinOneYear . '"' . ($classification === $withinOneYear ? ' checked' : '') . ' required' . $disabled . '><span>Due within one year</span></label>
                    <label class="segmented-option"><input type="radio" name="classification" value="' . $afterMoreThanOneYear . '"' . ($classification === $afterMoreThanOneYear ? ' checked' : '') . ' required' . $disabled . '><span>Due after more than one year</span></label>
                </div>
                <section class="panel-soft settings-stack">
                    <div class="eyebrow">Long-term maturity evidence</div>
                    <label class="checkbox-row"><input type="checkbox" name="deferment_right_confirmed" value="1"' . $defermentChecked . $disabled . '><span>At ' . HelperFramework::escape($periodEndLabel) . ', the company had an unconditional right to defer payment for at least twelve months.</span></label>
                    <label><span class="helper">Supporting contractual or other evidence</span><textarea class="input" name="deferment_evidence" rows="3" maxlength="2000"' . $disabled . '>' . HelperFramework::escape((string)($presentation['deferment_evidence'] ?? '')) . '</textarea></label>
                </section>
                <section class="panel-soft settings-stack">
                    <div class="eyebrow">Legal set-off evidence</div>
                    <label class="checkbox-row"><input type="checkbox" name="set_off_right_confirmed" value="1"' . $setOffRightChecked . $disabled . '><span>A legally enforceable right of set-off exists.</span></label>
                    <label class="checkbox-row"><input type="checkbox" name="set_off_net_settlement_intended" value="1"' . $setOffIntentionChecked . $disabled . '><span>The company intends to settle net, or to realise the asset and settle the liability simultaneously.</span></label>
                    <label><span class="helper">Supporting legal or contractual evidence</span><textarea class="input" name="set_off_evidence" rows="3" maxlength="2000"' . $disabled . '>' . HelperFramework::escape((string)($presentation['set_off_evidence'] ?? '')) . '</textarea></label>
                </section>
                <section class="panel-soft settings-stack">
                    <div class="eyebrow">Director-loan note terms</div>
                    <label><span class="helper">Interest rate (%)</span><input class="input" type="number" name="interest_rate_percent" min="0" max="100" step="0.0001" value="' . HelperFramework::escape(number_format((float)($presentation['interest_rate_percent'] ?? 0), 4, '.', '')) . '"' . $disabled . '></label>
                    <label><span class="helper">Main terms</span><textarea class="input" name="main_terms" rows="2" maxlength="1000" required' . $disabled . '>' . HelperFramework::escape((string)($presentation['main_terms'] ?? 'Unsecured.')) . '</textarea></label>
                    <label><span class="helper">Repayment conditions</span><textarea class="input" name="repayment_conditions" rows="2" maxlength="1000" required' . $disabled . '>' . HelperFramework::escape((string)($presentation['repayment_conditions'] ?? 'Repayable on demand.')) . '</textarea></label>
                </section>
                <div><button class="button primary" type="submit"' . ($schemaReady && !$isLocked ? '' : ' disabled') . '>Save reporting presentation</button></div>
            </form>
            ' . $mappingHtml . $schemaHtml . '
        </section>';
    }

    private function errors(array $errors): string
    {
        return implode('', array_map(
            static fn(mixed $error): string => '<div class="helper">' . HelperFramework::escape((string)$error) . '</div>',
            $errors
        ));
    }
}
