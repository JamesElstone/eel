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
    public function services(): array
    {
        return [
            [
                'key' => 's455',
                'service' => \eel_accounts\Service\S455ReviewService::class,
                'method' => 'fetchForAccountingPeriod',
                'params' => ['companyId' => ':company.id', 'accountingPeriodId' => ':company.accounting_period_id'],
            ],
            [
                'key' => 'ownership',
                'service' => \eel_accounts\Service\OwnershipPartyService::class,
                'method' => 'fetchSummary',
                'params' => ['companyId' => ':company.id'],
            ],
        ];
    }
    protected function additionalInvalidationFacts(): array { return ['tax.s455', 'tax.workings', 'year.end.checklist']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);
        $s455 = (array)($context['services']['s455'] ?? []);
        $ownership = (array)($context['services']['ownership'] ?? []);
        if (empty($s455['available'])) {
            return '<div class="helper">' . HelperFramework::escape((string)(($s455['errors'] ?? [])[0] ?? 's455 review is unavailable.')) . '</div>';
        }
        $settings = (array)($context['company']['settings'] ?? []);
        $partyOptions = '';
        foreach ((array)($ownership['parties'] ?? []) as $party) {
            $partyOptions .= '<option value="' . (int)$party['id'] . '">' . HelperFramework::escape((string)$party['legal_name']) . '</option>';
        }
        $html = '<section class="settings-stack" id="director-loan-s455">'
            . '<div class="helper">Only attributed source-payment transactions are used. Non-cash offsets and unsupported journals remain blockers in v1.</div>'
            . $this->transactionForm($companyId, $accountingPeriodId, $partyOptions);
        foreach ((array)$s455['periods'] as $period) {
            if (empty($period['available'])) { continue; }
            $errors = (array)($period['errors'] ?? []);
            $html .= '<form method="post" data-ajax="true" class="panel-soft settings-stack">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="card_action" value="DirectorLoan"><input type="hidden" name="intent" value="save_s455_review">'
                . '<input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
                . '<input type="hidden" name="ct_period_id" value="' . (int)$period['ct_period_id'] . '">'
                . '<div class="actions-row"><h4 class="card-title">Tax Period ' . (int)$period['sequence_no'] . ': ' . HelperFramework::escape((string)$period['period_start']) . ' to ' . HelperFramework::escape((string)$period['period_end']) . '</h4>'
                . '<span class="badge ' . (!empty($period['confirmed']) ? 'success' : 'warning') . '">' . (!empty($period['confirmed']) ? 'Confirmed' : 'Review required') . '</span></div>'
                . '<div class="summary-grid">'
                . $this->stat('Closing principal', $this->money($settings, $period['gross_principal']))
                . $this->stat('Gross s455 tax', $this->money($settings, $period['gross_tax']))
                . $this->stat('Cash repayments known', $this->money($settings, $period['qualifying_repayments']))
                . $this->stat('Net s455 tax', $this->money($settings, $period['net_tax']))
                . $this->stat('Repayment deadline', (string)$period['repayment_deadline'])
                . $this->stat('Evidence cutoff', (string)$period['evidence_cutoff'])
                . '</div>'
                . '<div class="form-grid"><div class="form-row"><label>Close company status</label><select class="select" name="close_company_status" required><option value="">Select</option>'
                . '<option value="yes"' . ((string)$period['close_company_status'] === 'yes' ? ' selected' : '') . '>Yes</option>'
                . '<option value="no"' . ((string)$period['close_company_status'] === 'no' ? ' selected' : '') . '>No</option></select></div>'
                . '<div class="form-row"><label>Review note</label><input class="input" name="confirmation_note" value="' . HelperFramework::escape((string)($period['confirmation_note'] ?? '')) . '"></div></div>'
                . '<label class="checkbox-row"><input type="checkbox" name="confirmed" value="1"' . (!empty($period['confirmed']) ? ' checked' : '') . '> I confirm the company/party classifications and source-payment evidence</label>'
                . ($errors !== [] ? '<div class="panel-soft warn"><strong>Blocking issues</strong><div class="helper">' . HelperFramework::escape(implode(' ', array_map('strval', $errors))) . '</div></div>' : '')
                . '<div class="helper">Window status: ' . HelperFramework::escape(HelperFramework::labelFromKey((string)$period['window_status'], '_'))
                . (!empty($period['ct600a_required']) ? '. CT600A is indicated.' : '. CT600A is not indicated by the current cash evidence.') . '</div>'
                . '<div class="actions-row"><button class="button primary" type="submit">Save s455 review</button></div></form>';
        }
        return $html . '</section>';
    }

    private function transactionForm(int $companyId, int $accountingPeriodId, string $partyOptions): string
    {
        return '<form method="post" data-ajax="true" class="panel-soft settings-stack">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="DirectorLoan"><input type="hidden" name="intent" value="mark_participator_loan_transaction">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
            . '<h4 class="card-title">Identify a participator-loan source payment</h4><div class="form-grid">'
            . '<div class="form-row"><label>Transaction ID</label><input class="input" type="number" min="1" name="transaction_id" required></div>'
            . '<div class="form-row"><label>Ownership party</label><select class="select" name="party_id" required><option value="">Select</option>' . $partyOptions . '</select></div></div>'
            . '<div class="helper">The party must have an effective shareholder, participator, or associate role on the transaction date.</div>'
            . '<div class="actions-row"><button class="button primary" type="submit">Mark source payment</button></div></form>';
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
