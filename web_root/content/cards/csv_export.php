<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _csv_exportCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'csv_export';
    }

    public function title(): string
    {
        return 'CSV Export';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'csv_export_months',
                'service' => StatementCsvExportService::class,
                'method' => 'fetchExportMonths',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
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
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);
        $months = (array)($context['services']['csv_export_months'] ?? []);

        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return '<div class="helper">Select a company and accounting period before exporting CSV uploads.</div>';
        }

        if ($months === []) {
            return '<div class="helper">No uploaded CSV files are available for this accounting period.</div>';
        }

        $html = '<div class="stack">';
        foreach ($months as $month) {
            if (!is_array($month)) {
                continue;
            }

            $uploads = array_values(array_filter(
                (array)($month['uploads'] ?? []),
                static fn(mixed $upload): bool => is_array($upload)
            ));

            if ($uploads === []) {
                continue;
            }

            $rowsHtml = '';
            $monthKey = (string)($month['month_key'] ?? '');
            foreach ($uploads as $upload) {
                $hiddenInputs = '
                            <input type="hidden" name="card_action" value="Uploads">
                            <input type="hidden" name="company_id" value="' . $companyId . '">
                            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                            <input type="hidden" name="upload_id" value="' . (int)($upload['id'] ?? 0) . '">
                            <input type="hidden" name="export_month" value="' . HelperFramework::escape($monthKey) . '">';

                $rowsHtml .= '<tr>
                    <td>' . HelperFramework::escape((string)($upload['account_name'] ?? 'No account selected')) . '</td>
                    <td>' . (int)($upload['export_rows'] ?? 0) . '</td>
                    <td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($upload['workflow_status'] ?? ''), '_')) . '</td>
                    <td>
                        <div class="actions-row-nowrap">
                        <form method="post" action="?page=uploads" class="inline-form">
                            ' . $hiddenInputs . '
                            <input type="hidden" name="intent" value="export_csv_upload">
                            <button class="button primary" type="submit">Export CSV</button>
                        </form>
                        <form method="post" action="?page=uploads" class="inline-form">
                            ' . $hiddenInputs . '
                            <input type="hidden" name="intent" value="export_xlsx_upload">
                            <button class="button" type="submit">Export XLSX</button>
                        </form>
                        </div>
                    </td>
                </tr>';
            }

            $html .= '<div class="panel-soft">
                <h3 class="card-title">' . HelperFramework::escape((string)($month['label'] ?? '')) . '</h3>'
                . '<div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Rows</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>' . $rowsHtml . '</tbody>
                    </table>
                </div>
            </div>';
        }

        if ($html === '<div class="stack">') {
            return '<div class="helper">No uploaded CSV files are available for this accounting period.</div>';
        }

        return $html . '</div>';
    }
}
