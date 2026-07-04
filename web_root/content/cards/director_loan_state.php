<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _director_loan_stateCard extends CardBaseFramework
{
    private const PAGE_SIZE = 15;

    public function key(): string
    {
        return 'director_loan_state';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'directorLoanStatement',
                'service' => \eel_accounts\Service\DirectorLoanService::class,
                'method' => 'fetchStatement',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Director Loan Statement';
    }

    public function helper(array $context): string
    {
        return 'Shown below is the Director Loan position. Director Loan entries are categorised on the Transactions page using the row-level Director Loan button.';
    }

    public function tables(array $context): array
    {
        return [$this->configuredStatementTable($context)];
    }

    protected function additionalInvalidationFacts(): array
    {
        return [];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $statement = (array)($context['services']['directorLoanStatement'] ?? []);

        if (empty($statement['success'])) {
            return $this->renderErrors((array)($statement['errors'] ?? ['Director loan statement is not available for the selected period.']));
        }

        $assetNominal = (array)($statement['asset_nominal'] ?? []);
        $liabilityNominal = (array)($statement['liability_nominal'] ?? []);
        $hasMovements = !empty($statement['has_movements_in_period']);
        $statementTable = $this->configuredStatementTable($context);

        return '
            <div class="month-grid">
                ' . $this->statCard('Director Loan Asset balance', $this->money($statement, $statement['asset_receivable'] ?? 0)) . '
                ' . $this->statCard('Director Loan Liability balance', $this->money($statement, $statement['liability_payable'] ?? 0)) . '
                ' . $this->statCard('Net director loan position', $this->money($statement, $statement['net_position'] ?? $statement['closing_balance'] ?? 0)) . '
                ' . $this->statCard('Status', (string)($statement['net_position_label'] ?? $statement['balance_direction_label'] ?? '')) . '
            </div>
            <section class="settings-stack director-loan-control-helper">
                <div class="helper">Using ' . HelperFramework::escape(FormattingFramework::nominalLabel($assetNominal, ' ')) . ' and ' . HelperFramework::escape(FormattingFramework::nominalLabel($liabilityNominal, ' ')) . ' as the Director Loan control accounts.</div>
            </section>
            ' . $statementTable->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]) . '
            ' . (!$hasMovements ? '<div class="helper">No Director Loan movements were found for this accounting period.</div>' : '') . '
        ';
    }

    private function configuredStatementTable(array $context): TableFramework
    {
        $statement = (array)($context['services']['directorLoanStatement'] ?? []);
        $table = $this->statementTable((array)($statement['statement_rows'] ?? []), $statement);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Director loan rows',
                $this->paginationPageField(),
                [
                    'page' => (string)($context['page']['page_id'] ?? ''),
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => [$this->key()],
                ]
            );
    }

    private function statementTable(array $rows, array $statement): TableFramework
    {
        return TableFramework::make($this->key(), $this->statementTableRows($rows))
            ->filename('director-loan-statement')
            ->exportLimit(1000)
            ->empty('No director loan movements were found for this period.')
            ->textColumn('date_display', 'Date processed / transaction date')
            ->textColumn('description', 'Description')
            ->textColumn('account_display', 'Account')
            ->column(
                'source_display',
                'Source',
                html: static fn(array $row): string => !empty($row['is_opening'])
                    ? '<span class="helper">Opening</span>'
                    : HelperFramework::escape((string)($row['source_display'] ?? '')),
                export: static fn(array $row): string => (string)($row['source_display'] ?? '')
            )
            ->column(
                'signed_amount',
                'Amount',
                html: fn(array $row): string => HelperFramework::escape($this->nullableMoney($statement, $row['signed_amount'] ?? null)),
                export: fn(array $row): string => $this->numberExport($row['signed_amount'] ?? null),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'running_balance',
                'Balance',
                html: fn(array $row): string => HelperFramework::escape($this->money($statement, $row['running_balance'] ?? 0)),
                export: fn(array $row): string => $this->numberExport($row['running_balance'] ?? null),
                cellClass: 'numeric',
                exportType: 'number'
            );
    }

    private function statementTableRows(array $rows): array
    {
        $tableRows = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $isOpening = (string)($row['row_type'] ?? '') === 'opening_balance';
            $accountLabel = (string)($row['account_label'] ?? '');
            if ($accountLabel === '' && !$isOpening) {
                $accountLabel = trim((string)($row['nominal_code'] ?? '') . ' - ' . (string)($row['nominal_name'] ?? ''), ' -');
            }

            $row['date_display'] = HelperFramework::displayDate((string)($row['journal_date'] ?? ''));
            $row['description'] = (string)($row['description'] ?? '');
            $row['account_display'] = $isOpening ? 'Combined' : $accountLabel;
            $row['source_display'] = $isOpening ? 'Opening' : HelperFramework::labelFromKey((string)($row['source_type'] ?? ''), '_');
            $row['is_opening'] = $isOpening;
            $tableRows[] = $row;
        }

        return $tableRows;
    }

    private function statCard(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function money(array $statement, mixed $value): string
    {
        return (new \eel_accounts\Service\MoneyFormatService())->format($this->moneySettings($statement), $value);
    }

    private function nullableMoney(array $statement, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $this->money($statement, $value);
    }

    private function moneySettings(array $statement): array
    {
        return ['default_currency_symbol' => (string)($statement['default_currency_symbol'] ?? '&#163;')];
    }

    private function numberExport(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float)$value, 2, '.', '');
    }

    private function renderErrors(array $errors): string
    {
        $html = '';
        foreach ($errors as $error) {
            $html .= '<div class="helper">' . HelperFramework::escape((string)$error) . '</div>';
        }

        return $html;
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'director.loan.state');
    }
}
