<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _banking_reconciliationCard extends CardBaseFramework
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
                    'companyId' => ':company.id',
                    'taxYearId' => ':company.tax_year_id',
                    'bankNominalId' => ':company.settings.default_bank_nominal_id',
                ],
            ],
            [
                'key' => 'tax_year',
                'service' => TaxYearRepository::class,
                'method' => 'fetchTaxYear',
                'params' => [
                    'companyId' => ':company.id',
                    'taxYearId' => ':company.tax_year_id',
                ],
            ],
        ];
    }

    public function helper(array $context) : string {
        if (((array)$context['services']['reconciliationPanels'] ?? []) === []) {
            return 'Add a bank account and upload statements to start continuity, running balance, and ledger reconciliation checks.';
        }
        return 'Drill down to uploads and committed transactions from here. Ledger reconciliation is currently company-bank-wide because journals still post into one generic Bank nominal.';
    }

    protected function additionalInvalidationFacts(): array
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
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $taxYearId = (int)($company['tax_year_id'] ?? 0);
        $panels = (array)($context['services']['reconciliationPanels'] ?? []);


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
                    <td>
                        <form method="post" action="?page=uploads">
                            <button class="button" type="submit">Open Upload</button>
                        </form>
                    </td>
                </tr>';
            }

            if ($uploadsHtml === '') {
                $uploadsHtml = '<tr><td colspan="7">No bank statement uploads are available for this bank account in the selected tax year.</td></tr>';
            }

            $ledgerSummary = is_array($panel['ledger_summary'] ?? null) ? $panel['ledger_summary'] : [];
            $panelsHtml .= '
                <div class="summary-grid four">
                    <div class="summary-card">
                        <div class="summary-label">Selected tax year</div>
                        <div class="summary-value"><span class="badge info">' . ($context['services']['tax_year']['label'] ?? '') . '.</span></div>
                    </div>
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
                <div class="table-scroll panel-soft">
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
                <h4 class="card-title standout">Ledger Reconciliation</h4>
                <div class="panel-soft">
                    <div class="summary-grid">
                        <div class="summary-card">
                            <div class="summary-label">Latest statement closing balance</div>
                            <div class="summary-value">' . HelperFramework::escape(FormattingFramework::nullableMoney($ledgerSummary['statement_closing_balance'] ?? null)) . '</div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-label">Ledger bank balance</div>
                            <div class="summary-value">' . HelperFramework::escape(FormattingFramework::nullableMoney($ledgerSummary['ledger_balance'] ?? null)) . '</div>
                        </div>
                        <div class="summary-card">
                            <div class="summary-label">Difference</div>
                            <div class="summary-value">' . HelperFramework::escape(FormattingFramework::nullableMoney($ledgerSummary['difference'] ?? null)) . '</div>
                        </div>
                    </div>
                </div>
                <div class="panel-soft">
                    <div class="helper">' . HelperFramework::escape((string)($ledgerSummary['note'] ?? '')) . '</div>
                    <div class="helper">' . HelperFramework::escape((string)($ledgerSummary['scope_note'] ?? '')) . '</div>
                    <div class="helper">Possible causes include missing statement imports, bank rows not yet committed, manual journals, director loan entries, and expense register repayments.</div>
                </div>
                <div class="standout">
                    <form method="post" action="?page=transactions">
                        <button class="button" type="submit">View Transactions</button>
                    </form>
                </div>
            ';
        }

        return $panelsHtml;
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
