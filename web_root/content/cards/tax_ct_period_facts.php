<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _tax_ct_period_factsCard extends CardBaseFramework
{
    public function key(): string { return 'tax_ct_period_facts'; }
    public function title(): string { return 'CT Period Facts'; }
    public function services(): array
    {
        return [[
            'key' => 'ct_period_facts',
            'service' => \eel_accounts\Service\CorporationTaxPeriodFactService::class,
            'method' => 'fetchForAccountingPeriod',
            'params' => ['companyId' => ':company.id', 'accountingPeriodId' => ':company.accounting_period_id'],
        ]];
    }
    protected function additionalInvalidationFacts(): array { return ['tax.workings', 'year.end.checklist']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);
        $facts = (array)($context['services']['ct_period_facts'] ?? []);
        if (empty($facts['available'])) {
            return '<div class="helper">' . HelperFramework::escape((string)(($facts['errors'] ?? [])[0] ?? 'CT-period facts are unavailable.')) . '</div>';
        }
        $html = '<section class="settings-stack" id="ct-period-facts"><div class="helper">Enter the number of associated companies excluding this company. The application uses 0 until you change it. Close-company status is calculated from the effective ownership and relationship records at the CT-period end.</div>';
        foreach ((array)$facts['periods'] as $period) {
            $html .= '<form method="post" data-ajax="true" class="panel-soft settings-stack">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="card_action" value="Tax"><input type="hidden" name="intent" value="save_ct_period_facts">'
                . '<input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
                . '<input type="hidden" name="ct_period_id" value="' . (int)$period['ct_period_id'] . '">'
                . '<div class="actions-row"><h4 class="card-title">Tax Period ' . (int)$period['sequence_no'] . ': ' . HelperFramework::escape((string)$period['period_start']) . ' to ' . HelperFramework::escape((string)$period['period_end']) . '</h4>'
                . '</div>'
                . $this->closeCompanyStatement((array)($period['close_company'] ?? []))
                . '<div class="form-grid"><div class="form-row"><label>Associated companies excluding this company</label><input class="input" type="number" min="0" name="associated_company_count" value="' . max(0, (int)($period['associated_company_count'] ?? 0)) . '" required></div></div>'
                . '<div class="actions-row"><button class="button primary" type="submit">Save CT-period fact</button></div></form>';
        }
        return $html . '</section>';
    }

    private function closeCompanyStatement(array $closeCompany): string
    {
        $status = (string)($closeCompany['status'] ?? 'unconfirmed');
        $label = match ($status) {
            'yes' => 'Close company — Yes',
            'no' => 'Close company — No',
            default => 'Close company — Cannot calculate',
        };
        $class = $status === 'yes' ? 'success' : ($status === 'no' ? 'info' : 'warning');
        $counts = (int)($closeCompany['shareholder_party_count'] ?? 0) . ' shareholder party/parties and '
            . (int)($closeCompany['non_shareholder_party_count'] ?? 0) . ' non-shareholder participator or associate party/parties.';

        return '<div class="panel-soft ' . $class . '"><strong>' . HelperFramework::escape($label) . '</strong><div class="helper">'
            . HelperFramework::escape((string)($closeCompany['detail'] ?? 'Close-company status is unavailable.'))
            . ' ' . HelperFramework::escape($counts)
            . ' <a class="button compact" href="?page=incorporation&amp;show_card=incorporation_relationships">Review ownership and relationships</a></div></div>';
    }
}
