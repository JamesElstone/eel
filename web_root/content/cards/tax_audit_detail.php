<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _tax_audit_detailCard extends CardBaseFramework
{
    public function key(): string { return 'tax_audit_detail'; }
    public function title(): string { return 'Tax Area Detail'; }
    public function helper(array $context): string
    {
        $label = trim((string)($context['tax_audit']['selected_area_label'] ?? ''));
        return $label === '' ? 'Choose an area in Tax Areas.' : 'Showing the sources behind ' . $label . '.';
    }
    public function services(): array
    {
        return [[
            'key' => 'taxAuditAreaDetail',
            'service' => \eel_accounts\Service\TaxAuditBasisService::class,
            'method' => 'fetchAreaDetail',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
                'ctPeriodId' => ':tax_audit.selected_ct_period_id',
                'areaCode' => ':tax_audit.selected_area',
                'page' => ':tax_audit.detail_page',
            ],
        ]];
    }
    public function handle(RequestFramework $request, PageServiceFramework $services, array $pageContext, ActionResultFramework $actionResult): array
    {
        $audit = (array)($pageContext['tax_audit'] ?? []);
        $audit['selected_ct_period_id'] = (int)($audit['selected_ct_period_id'] ?? $pageContext['company']['ct_period_id'] ?? 0);
        $audit['selected_area'] = (string)($audit['selected_area'] ?? '');
        $audit['detail_page'] = max(1, (int)($audit['detail_page'] ?? 1));
        $pageContext['tax_audit'] = $audit;
        return $pageContext;
    }
    protected function additionalInvalidationFacts(): array { return ['tax.audit.selection']; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $detail = (array)($context['services']['taxAuditAreaDetail'] ?? []);
        if (!empty($detail['empty_selection'])) {
            return '<div class="helper">Select a tax area above to load its linked accounting evidence.</div>';
        }
        if (empty($detail['available'])) {
            $message = (string)(($detail['errors'] ?? [])[0] ?? 'The selected Tax Audit detail is unavailable.');
            return '<div class="helper">' . HelperFramework::escape($message) . '</div>';
        }

        $mode = (string)($detail['mode'] ?? 'live');
        $status = (string)($detail['reconciliation_status'] ?? 'discrepancy');
        $rows = '';
        foreach ((array)($detail['rows'] ?? []) as $row) {
            $rule = trim((string)($row['rule_code'] ?? ''));
            if (($row['rule_version'] ?? '') !== '') {
                $rule .= ($rule !== '' ? ' ' : '') . 'v' . (string)$row['rule_version'];
            }
            if ($rule === '') {
                $rule = (string)($row['tax_treatment'] ?? 'Derived computation');
            }
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($row['source_date'] ?? '')) . '</td>
                <td><strong>' . HelperFramework::escape((string)($row['label'] ?? '')) . '</strong><br><span class="helper">' . HelperFramework::escape((string)($row['source_label'] ?? $row['source_type'] ?? '')) . '</span></td>
                <td>' . HelperFramework::escape(trim((string)($row['nominal_code'] ?? '') . ' ' . (string)($row['nominal_name'] ?? ''))) . '</td>
                <td>' . HelperFramework::escape($this->money($context, $row['accounting_amount'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape($this->money($context, $row['tax_adjustment_amount'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape($rule) . '</td>
                <td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($row['allocation_method'] ?? 'actual_date'))) . '</td>
                <td class="cell-fit">' . $this->sourceLink($row, $context) . '</td>
            </tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="8">No source rows are required for this area.</td></tr>';
        }

        return '<div class="summary-grid">
                <div class="summary-card"><div class="summary-label">Area total</div><div class="summary-value">' . HelperFramework::escape($this->money($context, $detail['amount'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">Computation total</div><div class="summary-value">' . HelperFramework::escape($this->money($context, $detail['expected_amount'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">Difference</div><div class="summary-value">' . HelperFramework::escape($this->money($context, $detail['reconciliation_difference'] ?? 0)) . '</div></div>
            </div>
            <div class="helper"><span class="badge ' . ($mode === 'frozen' ? 'success' : ($mode === 'reconstructed' ? 'warning' : 'info')) . '">' . HelperFramework::escape((string)($detail['mode_label'] ?? 'Audit preview')) . '</span>
            <span class="badge ' . ($status === 'reconciled' ? 'success' : 'danger') . '">' . HelperFramework::escape(HelperFramework::labelFromKey($status)) . '</span></div>
            <div class="table-scroll"><table><thead><tr><th>Date</th><th>Source</th><th>Nominal</th><th>Accounting amount</th><th>Tax amount</th><th>Treatment / rule</th><th>Allocation</th><th>Open</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>'
            . $this->pagination($detail, $context);
    }

    private function sourceLink(array $row, array $context): string
    {
        $type = (string)($row['source_type'] ?? '');
        $id = (int)($row['source_id'] ?? 0);
        $companyId = (int)($context['company']['id'] ?? 0);
        $periodId = (int)($context['company']['accounting_period_id'] ?? 0);
        if ($type === 'transaction' && $id > 0) {
            $date = (string)($row['source_date'] ?? '');
            return \eel_accounts\Renderer\WorkflowHandoffRenderer::button('transactions', 'Open transaction', [
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
                'show_card' => 'transactions_imported',
                'month_key' => preg_match('/^\d{4}-\d{2}/', $date, $matches) === 1 ? $matches[0] : '',
                'category_filter' => 'all',
                'transaction_id' => $id,
            ], 'button button-inline');
        }
        if ($type === 'expense_claim' && $id > 0) {
            return \eel_accounts\Renderer\WorkflowHandoffRenderer::button('expense_claims', 'Open claim', [
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
                'show_card' => 'expense_claim_editor',
                'claim_id' => $id,
            ], 'button button-inline');
        }
        if ($type === 'asset' || $type === 'depreciation') {
            return \eel_accounts\Renderer\WorkflowHandoffRenderer::button('assets', 'Open asset', [
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
                'show_card' => 'asset_register',
                'asset_id' => $id,
            ], 'button button-inline');
        }
        if ($type === 'journal' && $id > 0) {
            return \eel_accounts\Renderer\WorkflowHandoffRenderer::button('journal', 'Open journal', [
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
                'journal_id' => $id,
            ], 'button button-inline');
        }
        return '<span class="helper">Calculated source</span>';
    }

    private function pagination(array $detail, array $context): string
    {
        $pagination = (array)($detail['pagination'] ?? []);
        $page = (int)($pagination['page'] ?? 1);
        $pageCount = (int)($pagination['page_count'] ?? 1);
        if ($pageCount <= 1) {
            return '';
        }
        $audit = (array)($context['tax_audit'] ?? []);
        $buttons = '';
        foreach ([max(1, $page - 1) => 'Previous', min($pageCount, $page + 1) => 'Next'] as $target => $label) {
            $buttons .= '<form method="post" action="?page=tax_audit" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="action" value="select-tax-audit-area">
                <input type="hidden" name="ct_period_id" value="' . (int)($audit['selected_ct_period_id'] ?? 0) . '">
                <input type="hidden" name="tax_audit_area" value="' . HelperFramework::escape((string)($audit['selected_area'] ?? '')) . '">
                <input type="hidden" name="tax_audit_page" value="' . $target . '">
                <button class="button button-inline" type="submit"' . ($target === $page ? ' disabled' : '') . '>' . $label . '</button>
            </form>';
        }
        return '<div class="card-toolbar"><span class="helper">Page ' . $page . ' of ' . $pageCount . ' — ' . (int)($pagination['total_rows'] ?? 0) . ' sources</span><div class="actions-row">' . $buttons . '</div></div>';
    }

    private function money(array $context, mixed $amount): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money((array)($context['company']['settings'] ?? []), $amount);
    }
}
