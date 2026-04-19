<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _banking_reconciliationCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'banking_reconciliation';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'reconciliationPanels',
                'service' => BankingReconciliationService::class,
                'method' => 'fetchBankAccountPanels',
                'params' => [
                    'companyId' => ':company_id',
                    'taxYearId' => ':tax_year_id',
                    'bankNominalId' => ':default_bank_nominal_id',
                ],
            ],
        ];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $selectedTaxYearId = (int)($page['selected_tax_year_id'] ?? $page['tax_year_id'] ?? 0);
        $panels = (array)($context['services']['reconciliationPanels'] ?? []);

        if ($panels === []) {
            return '<div class="card">
                <div class="card-header">
                    <h2 class="card-title">Reconciliation Checks</h2>
                </div>
                <div class="card-body">
                    <div class="helper">Add a bank account and upload statements to start continuity, running balance, and ledger reconciliation checks.</div>
                </div>
            </div>';
        }

        $panelsHtml = '';
        foreach ($panels as $panel) {
            $uploadsHtml = '';
            foreach ((array)($panel['uploads'] ?? []) as $uploadCheck) {
                $uploadsHtml .= '<tr>
                    <td>
                        <div>' . HelperFramework::escape((string)($uploadCheck['statement_month'] ?? '')) . '</div>
                        <div class="helper">' . HelperFramework::escape((string)($uploadCheck['upload']['original_filename'] ?? '')) . '</div>
                    </td>
                    <td>' . HelperFramework::escape(FormattingFramework::nullableMoney($uploadCheck['opening_balance'] ?? null)) . '</td>
                    <td>' . HelperFramework::escape(FormattingFramework::nullableMoney($uploadCheck['closing_balance'] ?? null)) . '</td>
                    <td>' . HelperFramework::escape(FormattingFramework::nullableMoney($uploadCheck['previous_statement_closing_balance'] ?? null)) . '</td>
                    <td>
                        <span class="badge ' . HelperFramework::escape($this->reconciliationStatusBadgeClass((string)($uploadCheck['continuity_status'] ?? 'not_available'))) . '">' . HelperFramework::escape($this->reconciliationStatusLabel((string)($uploadCheck['continuity_status'] ?? 'not_available'))) . '</span>
                        <div class="helper">' . HelperFramework::escape((string)($uploadCheck['continuity_note'] ?? '')) . '</div>
                    </td>
                    <td>
                        <span class="badge ' . HelperFramework::escape($this->reconciliationStatusBadgeClass((string)($uploadCheck['running_balance_status'] ?? 'not_available'))) . '">' . HelperFramework::escape($this->reconciliationStatusLabel((string)($uploadCheck['running_balance_status'] ?? 'not_available'))) . '</span>
                        <div class="helper">' . HelperFramework::escape((string)($uploadCheck['running_balance_note'] ?? '')) . '</div>
                    </td>
                    <td><a class="button" href="' . HelperFramework::escape($this->buildPageUrl('uploads', ['company_id' => $selectedCompanyId, 'tax_year_id' => $selectedTaxYearId, 'upload_id' => (int)($uploadCheck['upload']['id'] ?? 0)])) . '">Open Upload</a></td>
                </tr>';
            }

            if ($uploadsHtml === '') {
                $uploadsHtml = '<tr><td colspan="7">No bank statement uploads are available for this bank account in the selected tax year.</td></tr>';
            }

            $ledgerSummary = is_array($panel['ledger_summary'] ?? null) ? $panel['ledger_summary'] : [];
            $panelsHtml .= '<div class="card" style="margin-bottom: 16px; box-shadow: none;">
                <div class="card-header">
                    <h3 class="card-title">' . HelperFramework::escape((string)($panel['account']['account_name'] ?? 'Bank account')) . '</h3>'
                    . (trim((string)($panel['account']['account_identifier'] ?? '')) !== ''
                        ? '<span class="badge info">' . HelperFramework::escape((string)$panel['account']['account_identifier']) . '</span>'
                        : '') . '
                </div>
                <div class="card-body">
                    <div class="helper" style="margin-bottom: 12px;">Selected tax year: ' . (int)($panel['selected_tax_year_id'] ?? 0) . '.</div>
                    <div class="summary-grid">
                        <div class="summary-card">
                            <div class="summary-label">Statement continuity</div>
                            <div class="summary-value"><span class="badge ' . HelperFramework::escape($this->reconciliationStatusBadgeClass((string)($panel['statement_continuity_status'] ?? 'not_available'))) . '">' . HelperFramework::escape($this->reconciliationStatusLabel((string)($panel['statement_continuity_status'] ?? 'not_available'))) . '</span></div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-label">Running balance checks</div>
                            <div class="summary-value"><span class="badge ' . HelperFramework::escape($this->reconciliationStatusBadgeClass((string)($panel['running_balance_status'] ?? 'not_available'))) . '">' . HelperFramework::escape($this->reconciliationStatusLabel((string)($panel['running_balance_status'] ?? 'not_available'))) . '</span></div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-label">Ledger reconciliation</div>
                            <div class="summary-value"><span class="badge ' . HelperFramework::escape($this->reconciliationStatusBadgeClass((string)($panel['ledger_reconciliation_status'] ?? 'not_available'))) . '">' . HelperFramework::escape($this->reconciliationStatusLabel((string)($panel['ledger_reconciliation_status'] ?? 'not_available'))) . '</span></div>
                        </div>
                    </div>
                    <div class="helper" style="margin: 14px 0;">
                        Drill down to uploads and committed transactions from here. Ledger reconciliation is currently company-bank-wide because journals still post into one generic Bank nominal.
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Statement</th>
                                    <th>Opening</th>
                                    <th>Closing</th>
                                    <th>Previous closing</th>
                                    <th>Continuity</th>
                                    <th>Running check</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>' . $uploadsHtml . '</tbody>
                        </table>
                    </div>
                    <div class="card" style="margin-top: 16px; box-shadow: none;">
                        <div class="card-header">
                            <h3 class="card-title">Ledger Reconciliation</h3>
                        </div>
                        <div class="card-body">
                            <div class="summary-grid">
                                <div class="summary-card">
                                    <div class="summary-label">Latest statement closing balance</div>
                                    <div class="summary-value">' . HelperFramework::escape($this->nullableMoney($ledgerSummary['statement_closing_balance'] ?? null)) . '</div>
                                </div>
                                <div class="summary-card">
                                    <div class="summary-label">Ledger bank balance</div>
                                    <div class="summary-value">' . HelperFramework::escape($this->nullableMoney($ledgerSummary['ledger_balance'] ?? null)) . '</div>
                                </div>
                                <div class="summary-card">
                                    <div class="summary-label">Difference</div>
                                    <div class="summary-value">' . HelperFramework::escape($this->nullableMoney($ledgerSummary['difference'] ?? null)) . '</div>
                                </div>
                            </div>
                            <div class="helper" style="margin-top: 12px;">' . HelperFramework::escape((string)($ledgerSummary['note'] ?? '')) . '</div>
                            <div class="helper" style="margin-top: 6px;">' . HelperFramework::escape((string)($ledgerSummary['scope_note'] ?? '')) . '</div>
                            <div class="helper" style="margin-top: 6px;">Possible causes include missing statement imports, bank rows not yet committed, manual journals, director loan entries, and expense register repayments.</div>
                            <div style="margin-top: 12px;">
                                <a class="button" href="' . HelperFramework::escape($this->buildPageUrl('transactions', ['company_id' => $selectedCompanyId, 'tax_year_id' => $selectedTaxYearId])) . '">View Transactions</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>';
        }

        return '<div class="card">
            <div class="card-header">
                <h2 class="card-title">Reconciliation Checks</h2>
            </div>
            <div class="card-body">' . $panelsHtml . '</div>
        </div>';
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        $query = http_build_query(['page' => $page] + $params);
        return '?' . $query;
    }

    private function reconciliationStatusLabel(string $status): string
    {
        return match ($status) {
            'pass' => 'Pass',
            'fail' => 'Fail',
            'warning' => 'Warning',
            default => 'Not available',
        };
    }

    private function reconciliationStatusBadgeClass(string $status): string
    {
        return match ($status) {
            'pass' => 'success',
            'fail' => 'danger',
            'warning' => 'warning',
            default => 'info',
        };
    }
}
