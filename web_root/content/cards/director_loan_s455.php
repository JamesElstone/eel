<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _director_loan_s455Card extends CardBaseFramework
{
    public function key(): string { return 'director_loan_s455'; }
    public function title(): string { return 'Participator Loans (S455)'; }
    public function helper(array $context): string
    {
        return 'This is a live s455 estimate based on correctly attributed cash transactions and the close-company result calculated from effective ownership and relationship records.';
    }
    public function services(): array
    {
        return [
            [
                'key' => 's455',
                'service' => \eel_accounts\Service\S455ReviewService::class,
                'method' => 'fetchForAccountingPeriod',
                'params' => ['companyId' => ':company.id', 'accountingPeriodId' => ':company.accounting_period_id'],
            ],
        ];
    }
    protected function additionalInvalidationFacts(): array { return ['tax.s455', 'tax.workings', 'year.end.checklist']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $s455 = (array)($context['services']['s455'] ?? []);
        if (empty($s455['available'])) {
            return '<div class="helper">' . HelperFramework::escape((string)(($s455['errors'] ?? [])[0] ?? 's455 review is unavailable.')) . '</div>';
        }
        $settings = (array)($context['company']['settings'] ?? []);
        $html = '<section class="settings-stack" id="director-loan-s455">
            <div class="actions-row">
                <a class="button button-inline" href="https://www.gov.uk/hmrc-internal-manuals/company-taxation-manual/ctm60102" target="_blank" rel="noopener noreferrer">HMRC: Close Company Definition</a>
                <a class="button button-inline" href="https://www.gov.uk/hmrc-internal-manuals/company-taxation-manual/ctm61505" target="_blank" rel="noopener noreferrer">HMRC: Section 455</a>
            </div>';
        foreach ((array)$s455['periods'] as $period) {
            if (empty($period['available'])) { continue; }
            $html .= '<section class="panel-soft settings-stack">'
                . '<div class="summary-grid four">'
                . $this->stat('Tax Period ' . (int)$period['sequence_no'], (string)$period['period_start'] . ' to ' . (string)$period['period_end'])
                . $this->stat('Close-Company Status', !empty($period['close_status_calculated']) ? 'Calculated' : 'Ownership data needed')
                . $this->stat('Evidence cutoff', (string)$period['evidence_cutoff'])
                . $this->stat(
                    's455 exposure',
                    (float)($period['gross_tax'] ?? 0) > 0 ? 'Exposure' : 'No exposure',
                    (float)($period['gross_tax'] ?? 0) > 0 ? 'warn' : 'success'
                )
                . $this->stat('Repayment deadline', (string)$period['repayment_deadline'])
                . '</div>'
                . $this->s455TaxTable($settings, $period)
                . ($this->repaymentGuidance($period))
                . '</section>';
        }
        return $html . '</section>';
    }

    private function repaymentGuidance(array $period): string
    {
        if ((string)($period['window_status'] ?? '') !== 'provisional_window_open') {
            return '';
        }

        return '<div class="panel-soft warn"><strong>Repayment opportunity</strong><div class="helper">Future transactions that are cash repayments, correctly categorised to the Participator Loan nominal on or before '
            . HelperFramework::escape((string)($period['repayment_deadline'] ?? 'the repayment deadline'))
            . ' may reduce this s455 position. A repayment is counted only when the party, timing, and statutory eligibility checks pass. This is guidance only and does not block Year End from closing.</div></div>';
    }

    private function stat(string $label, string $value, string $variant = ''): string
    {
        return '<div class="summary-card ' . HelperFramework::escape($variant) . '"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function s455TaxTable(array $settings, array $period): string
    {
        $rows = [
            'Participator Loan values outstanding at Year End' => $period['gross_principal'] ?? 0,
            'Corporation Tax owing arising from Section 455 (Gross s455 Tax)' => $period['gross_tax'] ?? 0,
            'Payments made after this Accounting Period before the above cut off date' => $period['qualifying_repayments'] ?? 0,
            'Corporation Tax owing arising from Section 455 taking into account future valid repayments (Net s455 Tax)' => $period['net_tax'] ?? 0,
        ];

        $html = '';
        foreach ($rows as $label => $value) {
            $html .= '<tr><th scope="row">' . HelperFramework::escape($label) . '</th><td>'
                . HelperFramework::escape($this->money($settings, $value)) . '</td></tr>';
        }

        return '<div class="table-scroll"><table><tbody>' . $html . '</tbody></table></div>';
    }

    private function money(array $settings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($settings, $value);
    }
}
