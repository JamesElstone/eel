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
        $html = '<section class="settings-stack" id="ct-period-facts"><div class="helper">Enter the number of associated companies excluding this company. This is a human-confirmed CT-period fact and is never inferred from transaction names.</div>';
        foreach ((array)$facts['periods'] as $period) {
            $confirmed = !empty($period['confirmed']);
            $html .= '<form method="post" data-ajax="true" class="panel-soft settings-stack">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="card_action" value="Tax"><input type="hidden" name="intent" value="save_ct_period_facts">'
                . '<input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
                . '<input type="hidden" name="ct_period_id" value="' . (int)$period['ct_period_id'] . '">'
                . '<div class="actions-row"><h4 class="card-title">Tax Period ' . (int)$period['sequence_no'] . ': ' . HelperFramework::escape((string)$period['period_start']) . ' to ' . HelperFramework::escape((string)$period['period_end']) . '</h4>'
                . '<span class="badge ' . ($confirmed ? 'success' : 'warning') . '">' . ($confirmed ? 'Confirmed' : 'Confirmation required') . '</span></div>'
                . '<div class="form-grid"><div class="form-row"><label>Associated companies excluding this company</label><input class="input" type="number" min="0" name="associated_company_count" value="' . max(0, (int)($period['associated_company_count'] ?? 0)) . '" required></div>'
                . '<div class="form-row"><label>Review note</label><input class="input" name="confirmation_note" value="' . HelperFramework::escape((string)($period['confirmation_note'] ?? '')) . '"></div></div>'
                . '<label class="checkbox-row"><input type="checkbox" name="confirmed" value="1"' . ($confirmed ? ' checked' : '') . '> I have reviewed the associated-company position for this CT period</label>'
                . '<div class="helper">' . ($confirmed ? 'Confirmed by ' . HelperFramework::escape((string)$period['confirmed_by']) . ' at ' . HelperFramework::escape((string)$period['confirmed_at']) : 'Year End cannot lock until this is confirmed.') . '</div>'
                . '<div class="actions-row"><button class="button primary" type="submit">Save CT-period fact</button></div></form>';
        }
        return $html . '</section>';
    }
}
