<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _pl_monthly_trendCard extends CardBaseFramework
{
    public function key(): string { return 'pl_monthly_trend'; }

    public function title(): string { return 'Monthly P&L Trend'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function tables(array $context): array
    {
        return [$this->monthlyTrendTable($context)];
    }

    public function render(array $context): string
    {
        $rows = (array)($context['profit_loss']['monthly_trend'] ?? []);
        if ($rows === []) {
            return '<div class="helper">No monthly Profit & Loss data is available for the selected period.</div>';
        }

        $table = $this->monthlyTrendTable($context);

        return '<section class="panel-soft">
            <div class="pl-monthly-trend-layout">
                <div class="pl-monthly-trend-table">
                    ' . $table->render($context, [
                        'cards[]' => (array)($context['page']['page_cards'] ?? []),
                    ]) . '
                </div>
                <div class="pl-monthly-trend-chart">
                    ' . $this->trendChart($rows) . '
                </div>
            </div>
        </section>';
    }

    private function monthlyTrendTable(array $context): TableFramework
    {
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);

        return TableFramework::make(
            $this->key(),
            $this->tableRows((array)($context['profit_loss']['monthly_trend'] ?? []))
        )
            ->filename('monthly-profit-loss-trend')
            ->exportLimit(500)
            ->empty('No monthly Profit & Loss data is available for the selected period.')
            ->textColumn('month_label', 'Month')
            ->column(
                'income_total',
                'Income',
                html: fn(array $row): string => HelperFramework::escape($this->money($companySettings, $row['income_total'] ?? 0)),
                export: fn(array $row): string => $this->numberExport($row['income_total'] ?? 0),
                exportType: 'number'
            )
            ->column(
                'cost_of_sales_total',
                'Cost of sales',
                html: fn(array $row): string => HelperFramework::escape($this->money($companySettings, $row['cost_of_sales_total'] ?? 0)),
                export: fn(array $row): string => $this->numberExport($row['cost_of_sales_total'] ?? 0),
                exportType: 'number'
            )
            ->column(
                'operating_expense_total',
                'Operating expenses',
                html: fn(array $row): string => HelperFramework::escape($this->money($companySettings, $row['operating_expense_total'] ?? 0)),
                export: fn(array $row): string => $this->numberExport($row['operating_expense_total'] ?? 0),
                exportType: 'number'
            )
            ->column(
                'depreciation_expense',
                'Depreciation preview',
                html: fn(array $row): string => HelperFramework::escape($this->money($companySettings, $row['depreciation_expense'] ?? 0)),
                export: fn(array $row): string => $this->numberExport($row['depreciation_expense'] ?? 0),
                exportType: 'number'
            )
            ->column(
                'posted_corporation_tax_charge',
                'Posted CT',
                html: fn(array $row): string => HelperFramework::escape($this->money($companySettings, $row['posted_corporation_tax_charge'] ?? 0)),
                export: fn(array $row): string => $this->numberExport($row['posted_corporation_tax_charge'] ?? 0),
                exportType: 'number'
            )
            ->column(
                'estimated_corporation_tax_adjustment',
                'Estimated total tax adjustment',
                html: fn(array $row): string => HelperFramework::escape($this->money($companySettings, $row['estimated_corporation_tax_adjustment'] ?? 0)),
                export: fn(array $row): string => $this->numberExport($row['estimated_corporation_tax_adjustment'] ?? 0),
                exportType: 'number'
            )
            ->column(
                'corporation_tax_expense_total',
                'Estimated total tax charge',
                html: fn(array $row): string => HelperFramework::escape($this->money($companySettings, $row['corporation_tax_expense_total'] ?? 0)),
                export: fn(array $row): string => $this->numberExport($row['corporation_tax_expense_total'] ?? 0),
                exportType: 'number'
            )
            ->column(
                'profit_before_tax',
                'Profit before tax',
                html: fn(array $row): string => HelperFramework::escape($this->money($companySettings, $row['profit_before_tax'] ?? 0)),
                export: fn(array $row): string => $this->numberExport($row['profit_before_tax'] ?? 0),
                exportType: 'number'
            )
            ->column(
                'profit_after_tax',
                'After estimated tax',
                html: fn(array $row): string => '<span class="badge '
                    . ((float)($row['profit_after_tax'] ?? 0) >= 0 ? 'success' : 'danger')
                    . '">' . HelperFramework::escape($this->money($companySettings, $row['profit_after_tax'] ?? 0)) . '</span>',
                export: fn(array $row): string => $this->numberExport($row['profit_after_tax'] ?? 0),
                exportType: 'number'
            );
    }

    private function tableRows(array $rows): array
    {
        $tableRows = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $row['operating_expense_total'] = (float)($row['operating_expense_total'] ?? ($row['expense_total'] ?? 0));
            $row['depreciation_expense'] = (float)($row['depreciation_expense'] ?? 0);
            $row['posted_corporation_tax_charge'] = (float)($row['posted_corporation_tax_charge'] ?? 0);
            $row['estimated_corporation_tax_adjustment'] = (float)($row['estimated_corporation_tax_adjustment'] ?? 0);
            $row['corporation_tax_expense_total'] = (float)($row['corporation_tax_expense_total'] ?? 0);
            $row['profit_after_tax'] = (float)($row['profit_after_tax'] ?? ($row['net_profit'] ?? 0));
            $tableRows[] = $row;
        }

        return $tableRows;
    }

    private function trendChart(array $rows): string
    {
        $series = [
            [
                'label' => 'Income',
                'color' => '#1d4ed8',
                'points' => $this->points($rows, 'income_total'),
            ],
            [
                'label' => 'Cost of sales',
                'color' => '#d97706',
                'points' => $this->points($rows, 'cost_of_sales_total'),
            ],
            [
                'label' => 'Operating expenses',
                'color' => '#7c3aed',
                'points' => $this->points($rows, 'operating_expense_total'),
            ],
            [
                'label' => 'Profit before tax',
                'color' => '#0f766e',
                'points' => $this->points($rows, 'profit_before_tax'),
            ],
            [
                'label' => 'After estimated tax',
                'color' => '#16a34a',
                'points' => $this->points($rows, 'profit_after_tax'),
            ],
        ];

        return (new ChartService())->line($series, [
            'title' => 'Monthly Profit and Loss trend',
            'width' => 760,
            'height' => 320,
        ]);
    }

    private function points(array $rows, string $valueKey): array
    {
        return array_map(
            fn(array $row): array => [
                'label' => $this->chartMonthNumber($row),
                'value' => (float)($row[$valueKey] ?? 0),
            ],
            $rows
        );
    }

    private function chartMonthNumber(array $row): string
    {
        $monthStart = trim((string)($row['month_start'] ?? ''));
        if ($monthStart !== '') {
            try {
                return (new DateTimeImmutable($monthStart))->format('n');
            } catch (Throwable) {
            }
        }

        $monthLabel = trim((string)($row['month_label'] ?? ''));
        if ($monthLabel !== '') {
            try {
                return (new DateTimeImmutable($monthLabel))->format('n');
            } catch (Throwable) {
            }
        }

        return '';
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function numberExport(float|int|string|null $value): string
    {
        return number_format((float)$value, 2, '.', '');
    }
}
