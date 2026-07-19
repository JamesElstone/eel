<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _tax_audit_areasCard extends CardBaseFramework
{
    public function key(): string { return 'tax_audit_areas'; }
    public function title(): string { return 'Tax Areas'; }
    public function helper(array $context): string
    {
        return 'Select a CT period and an area. The detail card loads only the evidence for that area.';
    }
    public function services(): array
    {
        return [[
            'key' => 'taxAuditAreaIndex',
            'service' => \eel_accounts\Service\TaxAuditBasisService::class,
            'method' => 'fetchAreaIndex',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
                'ctPeriodId' => ':tax_audit.selected_ct_period_id',
            ],
        ]];
    }
    public function handle(RequestFramework $request, PageServiceFramework $services, array $pageContext, ActionResultFramework $actionResult): array
    {
        $audit = (array)($pageContext['tax_audit'] ?? []);
        $audit['selected_ct_period_id'] = (int)($audit['selected_ct_period_id'] ?? $pageContext['company']['ct_period_id'] ?? 0);
        $audit['selected_area'] = (string)($audit['selected_area'] ?? '');
        $audit['detail_page'] = max(1, (int)($audit['detail_page'] ?? 1));
        $audit['handled_by_cards'] = array_values(array_unique(array_merge(
            (array)($audit['handled_by_cards'] ?? []),
            [$this->key()]
        )));
        $pageContext['tax_audit'] = $audit;
        return $pageContext;
    }
    protected function additionalInvalidationFacts(): array { return ['tax.audit.selection']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $audit = (array)($context['tax_audit'] ?? []);
        $index = (array)($context['services']['taxAuditAreaIndex'] ?? []);
        $periods = (array)($audit['ct_periods'] ?? []);
        $selectedPeriodId = (int)($audit['selected_ct_period_id'] ?? 0);
        $selectedArea = (string)($audit['selected_area'] ?? '');
        $hiddenCards = $this->hiddenCardFields($context);
        $periodOptions = '';
        foreach ($periods as $period) {
            $id = (int)($period['id'] ?? 0);
            $label = (string)($period['display_label'] ?? ('CT Period ' . (int)($period['sequence_no'] ?? 0)));
            if (($period['period_start'] ?? '') !== '' && ($period['period_end'] ?? '') !== '') {
                $label .= ' — ' . (string)$period['period_start'] . ' to ' . (string)$period['period_end'];
            }
            $periodOptions .= '<option value="' . $id . '"' . ($id === $selectedPeriodId ? ' selected' : '') . '>'
                . HelperFramework::escape($label) . '</option>';
        }
        $selector = '<form method="post" action="?page=tax_audit" data-ajax="true" class="toolbar">
            ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            ' . $hiddenCards . '
            <input type="hidden" name="action" value="select-tax-audit-area">
            <input type="hidden" name="tax_audit_area" value="">
            <label for="tax-audit-period">CT period</label>
            <select class="select" id="tax-audit-period" name="ct_period_id">' . $periodOptions . '</select>
            <button class="button primary" type="submit">Show period</button>
        </form>';

        if (empty($index['available'])) {
            $message = (string)(($index['errors'] ?? [])[0] ?? 'Select a CT period to inspect its audit areas.');
            return $selector . '<div class="helper">' . HelperFramework::escape($message) . '</div>';
        }

        $rows = '';
        foreach ((array)($index['areas'] ?? []) as $area) {
            $code = (string)($area['area_code'] ?? '');
            $status = (string)($area['reconciliation_status'] ?? 'reconciled');
            $selected = $code === $selectedArea;
            $sourceCount = $area['source_count'] ?? null;
            $rows .= '<tr' . ($selected ? ' class="is-selected"' : '') . '>
                <td><strong>' . HelperFramework::escape((string)($area['area_label'] ?? $code)) . '</strong></td>
                <td>' . HelperFramework::escape($this->money($context, $area['amount'] ?? 0)) . '</td>
                <td><span class="badge ' . ($status === 'reconciled' ? 'success' : 'danger') . '">' . HelperFramework::escape(HelperFramework::labelFromKey($status)) . '</span></td>
                <td>' . ($sourceCount === null ? '<span class="helper">On demand</span>' : (int)$sourceCount) . '</td>
                <td class="cell-fit">
                    <form method="post" action="?page=tax_audit" data-ajax="true" class="actions-row actions-row-nowrap">
                        ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                        ' . $hiddenCards . '
                        <input type="hidden" name="action" value="select-tax-audit-area">
                        <input type="hidden" name="ct_period_id" value="' . $selectedPeriodId . '">
                        <input type="hidden" name="tax_audit_area" value="' . HelperFramework::escape($code) . '">
                        <button class="button button-inline' . ($selected ? ' primary' : '') . '" type="submit">View details</button>
                    </form>
                </td>
            </tr>';
        }
        $mode = (string)($index['mode'] ?? 'live');
        $modeClass = $mode === 'frozen' ? 'success' : ($mode === 'reconstructed' ? 'warning' : 'info');
        return $selector
            . '<div class="helper"><span class="badge ' . $modeClass . '">' . HelperFramework::escape((string)($index['mode_label'] ?? 'Audit preview')) . '</span></div>'
            . '<div class="table-scroll"><table><thead><tr><th>Tax area</th><th>Amount</th><th>Reconciliation</th><th>Sources</th><th>Action</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
    }

    private function hiddenCardFields(array $context): string
    {
        $html = '';
        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }
        return $html;
    }

    private function money(array $context, mixed $amount): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money(
            (array)($context['company']['settings'] ?? []),
            $amount
        );
    }
}
