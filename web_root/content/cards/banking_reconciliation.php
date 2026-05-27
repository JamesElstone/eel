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
    private const PAGE_SIZE = 12;

    public function key(): string
    {
        return 'banking_reconciliation';
    }

    public function title(): string
    {
        return 'Account Checks';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'reconciliationPanels',
                'service' => BankingReconciliationService::class,
                'method' => 'fetchAccountPanels',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'bankNominalId' => ':company.settings.default_bank_nominal_id',
                ],
            ],
            [
                'key' => 'accounting_period',
                'service' => AccountingPeriodRepository::class,
                'method' => 'fetchAccountingPeriod',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    public function helper(array $context): string
    {
        if (((array)($context['services']['reconciliationPanels'] ?? [])) === []) {
            return 'Add a company account to start bank statement checks or trade account ledger checks.';
        }

        return 'Bank accounts show statement checks; trade accounts show tagged ledger activity until supplier statement matching is added.';
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
        $panels = (array)($context['services']['reconciliationPanels'] ?? []);
        $accountingPeriodLabel = (string)($context['services']['accounting_period']['label'] ?? '');
        $panelsHtml = '';

        foreach ($panels as $index => $panel) {
            $account = is_array($panel['account'] ?? null) ? $panel['account'] : [];
            $accountName = trim((string)($account['account_name'] ?? 'Company account'));
            $accountType = (string)($panel['account_type'] ?? $account['account_type'] ?? '');
            $accountTypeLabel = CompanyAccountService::accountTypes()[$accountType] ?? ucfirst($accountType);
            $statusBadges = $accountType === CompanyAccountService::TYPE_TRADE
                ? $this->tradeStatusBadges($panel)
                : $this->bankStatusBadges($panel);

            $panelsHtml .= '
                <section class="indexed-section">
                    <div class="indexed-section-marker">
                        <div class="indexed-section-number">' . HelperFramework::escape(sprintf('%02d', $index + 1)) . '</div>
                        <div class="indexed-section-label">' . HelperFramework::escape($accountTypeLabel) . '</div>
                    </div>
                    <div class="indexed-section-main">
                        <header class="indexed-section-header">
                            <div>
                                <h3 class="indexed-section-title">' . HelperFramework::escape($accountName) . '</h3>
                                <div class="indexed-section-helper">' . HelperFramework::escape($this->accountHelper($account)) . '</div>
                            </div>
                            <div class="indexed-section-status">' . $statusBadges . '</div>
                        </header>
                        <div class="indexed-section-body">'
                            . ($accountType === CompanyAccountService::TYPE_TRADE
                                ? $this->renderTradePanel($panel, $accountingPeriodLabel)
                                : $this->renderBankPanel($panel, $accountingPeriodLabel, $index, $context))
                        . '</div>
                    </div>
                </section>';
        }

        return $panelsHtml;
    }

    public function tables(array $context): array
    {
        $tables = [];

        foreach ((array)($context['services']['reconciliationPanels'] ?? []) as $index => $panel) {
            if (!is_array($panel)) {
                continue;
            }

            $account = is_array($panel['account'] ?? null) ? $panel['account'] : [];
            $accountType = (string)($panel['account_type'] ?? $account['account_type'] ?? '');
            if ($accountType !== CompanyAccountService::TYPE_TRADE) {
                $tables[] = $this->bankUploadsTable($panel, (int)$index);
            }
        }

        return $tables;
    }

    private function renderBankPanel(array $panel, string $accountingPeriodLabel, int $index, array $context): string
    {
        $account = is_array($panel['account'] ?? null) ? $panel['account'] : [];
        $ledgerSummary = is_array($panel['ledger_summary'] ?? null) ? $panel['ledger_summary'] : [];

        return '
            <div class="summary-grid four">
                <div class="summary-card">
                    <div class="summary-label">Selected accounting period</div>
                    <div class="summary-value"><span class="badge info">' . HelperFramework::escape($accountingPeriodLabel) . '</span></div>
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
            ' . $this->configuredBankUploadsTable($panel, $index, $context)->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]) . '
            <div class="panel-soft">
                <h4 class="card-title">Ledger Reconciliation</h4>
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
            </div>';
    }

    private function renderTradePanel(array $panel, string $accountingPeriodLabel): string
    {
        $summary = is_array($panel['trade_summary'] ?? null) ? $panel['trade_summary'] : [];

        return '
            <div class="summary-grid four">
                <div class="summary-card">
                    <div class="summary-label">Selected accounting period</div>
                    <div class="summary-value"><span class="badge info">' . HelperFramework::escape($accountingPeriodLabel) . '</span></div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Tagged ledger lines</div>
                    <div class="summary-value">' . HelperFramework::escape((string)(int)($summary['line_count'] ?? 0)) . '</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Debits</div>
                    <div class="summary-value">' . HelperFramework::escape(FormattingFramework::nullableMoney($summary['debit_total'] ?? null)) . '</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Credits</div>
                    <div class="summary-value">' . HelperFramework::escape(FormattingFramework::nullableMoney($summary['credit_total'] ?? null)) . '</div>
                </div>
            </div>
            <h4 class="card-title">Trade Ledger Check</h4>
            <div class="panel-soft">
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">Closing balance</div>
                        <div class="summary-value">' . HelperFramework::escape(FormattingFramework::nullableMoney($summary['net_balance'] ?? null)) . '</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Balance side</div>
                        <div class="summary-value">' . HelperFramework::escape((string)($summary['balance_label'] ?? 'None')) . '</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Last activity</div>
                        <div class="summary-value">' . HelperFramework::escape((string)($summary['last_journal_date'] ?? '')) . '</div>
                    </div>
                </div>
            </div>
            <div class="panel-soft">
                <div class="helper">' . HelperFramework::escape((string)($summary['note'] ?? '')) . '</div>
                <div class="helper">' . HelperFramework::escape((string)($summary['scope_note'] ?? '')) . '</div>
            </div>
            <div class="standout">
                <form method="post" action="?page=journals">
                    <button class="button" type="submit">View Journals</button>
                </form>
            </div>';
    }

    private function bankUploadsTable(array $panel, int $index): TableFramework
    {
        $account = is_array($panel['account'] ?? null) ? $panel['account'] : [];
        $accountName = trim((string)($account['account_name'] ?? 'Bank account'));
        $key = $this->key() . '_uploads_' . $this->panelTableSuffix($panel, $index);
        $filename = 'bank-statement-checks-' . $this->panelTableSuffix($panel, $index);

        return TableFramework::make($key, $this->bankUploadRows($panel))
            ->filename($filename)
            ->exportLimit(1000)
            ->empty('No bank statement uploads are available for ' . $accountName . ' in the selected accounting period.')
            ->classes(wrapperClass: 'table-scroll panel-soft')
            ->primarySecondaryColumn(
                'statement_month',
                'Statement',
                secondaryKey: 'upload_filename'
            )
            ->column(
                'opening_balance',
                'Opening',
                html: static fn(array $row): string => HelperFramework::escape(FormattingFramework::nullableMoney($row['opening_balance'] ?? null)),
                export: static fn(array $row): string => FormattingFramework::nullableMoney($row['opening_balance'] ?? null)
            )
            ->column(
                'closing_balance',
                'Closing',
                html: static fn(array $row): string => HelperFramework::escape(FormattingFramework::nullableMoney($row['closing_balance'] ?? null)),
                export: static fn(array $row): string => FormattingFramework::nullableMoney($row['closing_balance'] ?? null)
            )
            ->column(
                'previous_statement_closing_balance',
                'Previous closing',
                html: static fn(array $row): string => HelperFramework::escape(FormattingFramework::nullableMoney($row['previous_statement_closing_balance'] ?? null)),
                export: static fn(array $row): string => FormattingFramework::nullableMoney($row['previous_statement_closing_balance'] ?? null)
            )
            ->column(
                'continuity_label',
                'Continuity',
                html: fn(array $row): string => $this->statusWithNoteHtml(
                    (string)($row['continuity_status'] ?? 'not_available'),
                    (string)($row['continuity_note'] ?? '')
                ),
                export: fn(array $row): string => $this->joinExportParts([
                    (string)($row['continuity_label'] ?? ''),
                    (string)($row['continuity_note'] ?? ''),
                ])
            )
            ->column(
                'running_balance_label',
                'Running check',
                html: fn(array $row): string => $this->statusWithNoteHtml(
                    (string)($row['running_balance_status'] ?? 'not_available'),
                    (string)($row['running_balance_note'] ?? '')
                ),
                export: fn(array $row): string => $this->joinExportParts([
                    (string)($row['running_balance_label'] ?? ''),
                    (string)($row['running_balance_note'] ?? ''),
                ])
            )
            ->column(
                'actions',
                '',
                html: static fn(array $row): string => '<form method="post" action="?page=uploads">
                    <button class="button" type="submit">Open Upload</button>
                </form>',
                exportable: false
            );
    }

    private function configuredBankUploadsTable(array $panel, int $index, array $context): TableFramework
    {
        $rows = $this->bankUploadRows($panel);
        $pagination = HelperFramework::paginateArray($rows, $this->paginationPage($context), self::PAGE_SIZE);

        return $this->bankUploadsTable($panel, $index)
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Statement uploads',
                $this->paginationPageField(),
                [
                    'page' => (string)($context['page']['page_id'] ?? ''),
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => [$this->key()],
                ]
            );
    }

    private function bankUploadRows(array $panel): array
    {
        $rows = [];

        foreach ((array)($panel['uploads'] ?? []) as $uploadCheck) {
            if (!is_array($uploadCheck)) {
                continue;
            }

            $upload = is_array($uploadCheck['upload'] ?? null) ? $uploadCheck['upload'] : [];
            $uploadCheck['upload_filename'] = (string)($upload['original_filename'] ?? '');
            $uploadCheck['continuity_label'] = $this->reconciliationStatusLabel((string)($uploadCheck['continuity_status'] ?? 'not_available'));
            $uploadCheck['running_balance_label'] = $this->reconciliationStatusLabel((string)($uploadCheck['running_balance_status'] ?? 'not_available'));
            $rows[] = $uploadCheck;
        }

        return $rows;
    }

    private function statusWithNoteHtml(string $status, string $note): string
    {
        return '<span class="badge ' . HelperFramework::escape($this->reconciliationStatusBadgeClass($status)) . '">'
            . HelperFramework::escape($this->reconciliationStatusLabel($status))
            . '</span>'
            . '<div class="helper">' . HelperFramework::escape($note) . '</div>';
    }

    private function panelTableSuffix(array $panel, int $index): string
    {
        $account = is_array($panel['account'] ?? null) ? $panel['account'] : [];
        $accountId = (int)($account['id'] ?? 0);
        if ($accountId > 0) {
            return 'account_' . $accountId;
        }

        return 'panel_' . ($index + 1);
    }

    private function joinExportParts(array $parts): string
    {
        $parts = array_values(array_filter(
            array_map(static fn(mixed $part): string => trim((string)$part), $parts),
            static fn(string $part): bool => $part !== ''
        ));

        return implode(' | ', $parts);
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
    }

    private function accountHelper(array $account): string
    {
        $institutionName = trim((string)($account['institution_name'] ?? ''));
        $accountIdentifier = trim((string)($account['account_identifier'] ?? ''));
        $accountHelperParts = array_values(array_filter([$institutionName, $accountIdentifier], static fn(string $value): bool => $value !== ''));

        return $accountHelperParts !== []
            ? implode(' · ', $accountHelperParts)
            : 'No institution or account identifier recorded.';
    }

    private function bankStatusBadges(array $panel): string
    {
        return $this->statusBadge('Continuity', (string)($panel['statement_continuity_status'] ?? 'not_available'))
            . $this->statusBadge('Running', (string)($panel['running_balance_status'] ?? 'not_available'))
            . $this->statusBadge('Ledger', (string)($panel['ledger_reconciliation_status'] ?? 'not_available'));
    }

    private function tradeStatusBadges(array $panel): string
    {
        return $this->statusBadge('Ledger', (string)($panel['ledger_reconciliation_status'] ?? 'not_available'));
    }

    private function statusBadge(string $label, string $status): string
    {
        return '<span class="badge ' . HelperFramework::escape($this->reconciliationStatusBadgeClass($status)) . '">'
            . HelperFramework::escape($label . ' ' . strtolower($this->reconciliationStatusLabel($status)))
            . '</span>';
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
