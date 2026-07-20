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
    public function title(): string { return 'Participator loans (s455)'; }
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
        $html = '<section class="settings-stack" id="director-loan-s455">';
        foreach ((array)$s455['periods'] as $period) {
            if (empty($period['available'])) { continue; }
            $html .= '<section class="panel-soft settings-stack">'
                . '<div class="actions-row"><h4 class="card-title">Tax Period ' . (int)$period['sequence_no'] . ': ' . HelperFramework::escape((string)$period['period_start']) . ' to ' . HelperFramework::escape((string)$period['period_end']) . '</h4>'
                . '<span class="badge ' . (!empty($period['close_status_calculated']) ? 'success' : 'warning') . '">' . (!empty($period['close_status_calculated']) ? 'Close status calculated' : 'Ownership data needed') . '</span></div>'
                . '<div class="summary-grid">'
                . $this->stat('Closing principal', $this->money($settings, $period['gross_principal']))
                . $this->stat('Gross s455 tax', $this->money($settings, $period['gross_tax']))
                . $this->stat('Cash repayments known', $this->money($settings, $period['qualifying_repayments']))
                . $this->stat('Net s455 tax', $this->money($settings, $period['net_tax']))
                . $this->stat('Repayment deadline', (string)$period['repayment_deadline'])
                . $this->stat('Evidence cutoff', (string)$period['evidence_cutoff'])
                . '</div>'
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

    private function stat(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }
    private function money(array $settings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($settings, $value);
    }
}
