<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _accounting_periodsCard extends CardBaseFramework
{
    private const PAGE_SIZE = 5;

    public function key(): string
    {
        return 'accounting_periods';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'company_detail',
                'service' => CompanyRepository::class,
                'method' => 'fetchCompanyDetails',
                'params' => ['companyId' => ':company.id'],
            ],
            [
                'key' => 'accounting_periods',
                'service' => AccountingPeriodRepository::class,
                'method' => 'fetchAccountingPeriods',
                'params' => ['companyId' => ':company.id'],
            ],
            [
                'key' => 'accounting_guidance',
                'service' => AccountingGuidanceService::class,
                'method' => 'build',
                'params' => ['companyId' => ':company.id'],
            ],
        ]
        ;
    }

    public function helper(array $context): string {
        if (!empty($context['services']['accounting_guidance']['missing_suggested_periods'])) {
           return 'Existing Accounting Periods from Companies House have been automatically added, but there are missing accounting periods.'; 
        }
        return 'Existing Accounting Periods';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function tables(array $context): array
    {
        return [$this->existingPeriodsTable($context)];
    }

    public function render(array $context): string
    {

        if ((string)($context['company']['id'] ?? 0) <= 0) {
            return '<div class="helper">No information available until a company is selected.</div>';
        }

        $accountingGuidance = (array)($context['services']['accounting_guidance'] ?? []);
        $usesFiledPeriods = (string)($context['services']['accounting_guidance']['suggestion_basis'] ?? '') === 'companies_house_filed_periods';

        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);
        $pageId = trim((string)($context['page']['page_id'] ?? ''));

        $accountingPeriods = (array)($context['services']['accounting_periods'] ?? []);
        $selectedAccountingPeriod = $this->resolveSelectedAccountingPeriod($accountingPeriods, $accountingPeriodId);
        $selectedAccountingPeriodId = (int)($selectedAccountingPeriod['id'] ?? ($accountingPeriodId > 0 ? $accountingPeriodId : 0));

        $missingHtml = '';
        if (!empty($accountingGuidance['missing_suggested_periods'])) {
            $missingHtml .= '<div class="panel-soft warn"><h4 class="card-title">Suggested Accounting Periods:</h4>';

            if ($usesFiledPeriods) {
                $missingHtml .= '
                    <span class="helper">Below are suggested accounting periods from the end of your most recently filed period with Companies House, which ended on '
                        . $this->escape(($accountingGuidance['latest_filed_period_end_display'] ?? '') !== '' 
                            ? (string)$accountingGuidance['latest_filed_period_end_display'] 
                            : 'Not available')
                        . ', to today.
                    </span>
                ';
            } else {
                $missingHtml .= '
                    <span class="helper">Below are suggested accounting periods from your incorporation date ('
                        . $this->escape(($accountingGuidance['incorporation_date_display'] ?? '') !== '' 
                            ? (string)$accountingGuidance['incorporation_date_display'] 
                            : 'Not available')
                        . ') to today.<br>Accounting periods end on the last day of the month one year after incorporation providing a consistent and practical month-end cut-off for reporting and tax purposes.
                    </span>
                ';
            }

            $missingHtml .= '
                <div class="list">';
            foreach ((array)$accountingGuidance['missing_suggested_periods'] as $period) {
                $missingHtml .= '<div class="list-item">'
                    . '<strong>Suggested period</strong>'
                    . '<span>' . $this->escape($this->periodDisplayRange((array)$period)) . '</span>'
                    . '</div>';
            }
            $missingHtml .= '
                </div>
                <div>
                    <form method="post" data-ajax="true">
                        <input type="hidden" name="card_action" value="AccountingPeriods">
                        <input type="hidden" name="intent" value="create_suggested_periods">
                        <input type="hidden" name="company_id" value="' . $companyId . '">
                        <button class="button primary" type="submit">Create Suggested Accounting Periods</button>
                    </form>
                </div>
            ';
            $missingHtml .= '</div>';
        }

        $existingPeriodsPagination = HelperFramework::paginateArray(
            $this->existingPeriodRows($context),
            $this->paginationPage($context),
            self::PAGE_SIZE
        );
        $existingPeriodsHtml = $this->existingPeriodsTable($context)
            ->visibleRows((array)$existingPeriodsPagination['items'])
            ->pagination(
                $existingPeriodsPagination,
                'Accounting periods',
                $this->paginationPageField(),
                [
                    'page' => $pageId,
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => $this->key(),
                ]
            )
            ->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]);

        $mainHtml = '
        <div class="panel-soft">
            <h4 class="card-title">Existing periods</h4>
            ' . $existingPeriodsHtml . '
        </div>
        <div class="panel-soft stack">
            <div class="form-flex-flow">
                <div class="form-row full">
                    <label for="accounting_period_id">Accounting period</label>
                    <form method="post" data-ajax="true" data-accounting-period-selector="true">
                        <input type="hidden" name="action" value="set-site-context">
                        <input type="hidden" name="_ajax" value="1">
                        <input type="hidden" name="page" value="' . $this->escape($pageId) . '">
                        <input type="hidden" name="site_context_key" value="accounting_period_id">
                        <input type="hidden" name="site_context_input_name" value="accounting_period_id">
                        <input type="hidden" name="company_id" value="' . $companyId . '">
                        <select class="select" id="accounting_period_id" name="accounting_period_id">
                        <option value="0" data-period-label="" data-period-start="" data-period-end=""' . ($accountingPeriodId === 0 ? ' selected' : '') . '>New Period</option>';
                        foreach ($accountingPeriods as $accountingPeriod) {
                            $mainHtml .= '<option value="' . 
                                ($accountingPeriod['id'] ?? 0) . 
                                '" data-period-label="' . $this->escape((string)($accountingPeriod['label'] ?? ''))
                                . '" data-period-start="' . $this->escape((string)($accountingPeriod['period_start'] ?? ''))
                                . '" data-period-end="' . $this->escape((string)($accountingPeriod['period_end'] ?? ''))
                                . '"'. (((int)($accountingPeriod['id'] ?? 0) === $accountingPeriodId) ? ' selected' : '')
                                . '>('
                                . $this->escape($this->displayDate((string)($accountingPeriod['period_start'] ?? '')))
                                . ' to '
                                . $this->escape($this->displayDate((string)($accountingPeriod['period_end'] ?? '')))
                                . 
                            ')</option>';
                        };
        $mainHtml .= '
                        </select>
                    </form>
                </div>
            </div>
            <form method="post" data-ajax="true" data-accounting-period-form="true">
                <input type="hidden" name="card_action" value="Company">
                <input type="hidden" id="accounting_period_intent" name="intent" value="' . ($selectedAccountingPeriodId > 0 ? 'update_accounting_period' : 'add_accounting_period') . '">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" id="accounting_period_selected_accounting_period_id" name="accounting_period_id" value="' . $selectedAccountingPeriodId . '" data-state-default="' . $selectedAccountingPeriodId . '">
                <section data-state-fields="accounting_period_selected_accounting_period_id,financial_period_label,period_start,period_end" data-state-target="save_accounting_period_button">
                <div class="form-flex-flow">
                    <div class="form-row half">
                        <label for="financial_period_label">Period Alias Name</label>
                        <input class="input" id="financial_period_label" name="financial_period_label" value="' . $this->escape((string)($selectedAccountingPeriod['label'] ?? '')) . '">
                    </div>
                    <div class="form-row half">
                        <label for="period_start">Period start</label>
                        <input class="input" type="date" id="period_start" name="period_start" value="' . $this->escape((string)($selectedAccountingPeriod['period_start'] ?? '')) . '">
                    </div>
                    <div class="form-row half">
                        <label for="period_end">Period end</label>
                        <input class="input" type="date" id="period_end" name="period_end" value="' . $this->escape((string)($selectedAccountingPeriod['period_end'] ?? '')) . '">
                    </div>
                    <div class="form-row full">
                        <button class="button primary" id="save_accounting_period_button" type="submit" disabled>Save Accounting Period Details</button>
                    </div>
                </div>
                </section>
            </form>
        </div>
        ';

        $guidanceHtml = '';
        if (!empty($accountingGuidance['ct_periods'])) {
            $guidanceHtml .= '<div class="panel-soft warn"><h4 class="card-title">Tax Returns required for period:</h4>';
            if (($accountingGuidance['ct600_summary'] ?? '') !== '') {
                $guidanceHtml .= '<div class="helper">' . $this->escape((string)$accountingGuidance['ct600_summary']) . '</div>';
            }

            $guidanceHtml .= '<div class="list">';
            foreach ((array)$accountingGuidance['ct_periods'] as $index => $ctPeriod) {
                $guidanceHtml .= '<div class="list-item">'
                    . '<strong>' . $this->escape('Corporation Tax Period ' . ((int)$index + 1)) . '</strong>'
                    . '<span>' . $this->escape($this->periodDisplayRange((array)$ctPeriod)) . '</span>'
                    . '</div>';
            }
            $guidanceHtml .= '</div></div>';
        }

        $coverageHtml = '';
        if (!empty($accountingGuidance['coverage']['months'])) {
            $hasMissingCoverage = (int)($accountingGuidance['coverage']['total_txn_count'] ?? 0) === 0;

            if (!$hasMissingCoverage) {
                foreach ((array)($accountingGuidance['coverage']['months'] ?? []) as $month) {
                    if ((int)($month['txn_count'] ?? 0) === 0) {
                        $hasMissingCoverage = true;
                        break;
                    }
                }
            }

            $coveragePanelClass = 'panel-soft' . ($hasMissingCoverage ? ' warn' : '');
            $coverageHtml .= '<div class="' . $coveragePanelClass . '"><h4 class="card-title">Transaction coverage for period:</h4>';
            $coverageHtml .= '<table>
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Month</th>
                                <th>Transactions</th>
                            </tr>
                        </thead>
                        <tbody>';

            foreach ((array)$accountingGuidance['coverage']['months'] as $month) {
                $isGreen = (int)($month['txn_count'] ?? 0) > 0;
                $coverageHtml .= '
                    <tr>
                        <td>
                            <span class="coverage-light-dot">
                            </span>
                        </td>
                        <td>' . $this->escape((string)($month['label'] ?? '')) . '</td>
                        <td>' . (int)($month['txn_count'] ?? 0) . '</td>
                    </tr>';
            }

            $coverageHtml .= '
                    </tbody>
                </table>
            </div>
            ';

            if ((int)($accountingGuidance['coverage']['outside_period_count'] ?? 0) > 0) {
                $count = (int)$accountingGuidance['coverage']['outside_period_count'];
                $coverageHtml .= '<div class="helper">' . $count . ' linked transaction' . ($count === 1 ? '' : 's') . ' fall outside the selected accounting period.</div>';
            }
        }

        return trim('
            <div class="settings-layout">
                <div class="settings-stack">
                    ' . $missingHtml . '
                    ' . $mainHtml . '
                </div>
                <div class="settings-stack">
                    ' . $guidanceHtml . '
                    ' . $coverageHtml . '
                </div>
            </div>
        ');
    }

    private function escape(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    private function periodDisplayRange(array $period): string
    {
        $displayRange = trim((string)($period['display_range'] ?? ''));
        if ($displayRange !== '') {
            return $displayRange;
        }

        $start = trim((string)($period['start'] ?? ''));
        $end = trim((string)($period['end'] ?? ''));

        if ($start !== '' && $end !== '') {
            return $start . ' to ' . $end;
        }

        return '';
    }

    private function renderInlineFeedback(array $errors): string
    {
        if ($errors === []) {
            return '';
        }

        return '<div class="helper">' . $this->escape(implode(' ', $errors)) . '</div>';
    }

    private function normaliseErrors(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);
            return $value === '' ? [] : [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $errors = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $item = trim($item);
                if ($item !== '') {
                    $errors[] = $item;
                }
            }
        }

        return $errors;
    }

    private function displayDate(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return HelperFramework::displayDate($value);
    }

    private function existingPeriodsTable(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->existingPeriodRows($context))
            ->filename('accounting-periods')
            ->empty('No accounting periods have been created for this company yet.')
            ->classes('', 'table-scroll-mini')
            ->textColumn('label', 'Alias')
            ->textColumn('period_start_display', 'Start')
            ->textColumn('period_end_display', 'End');
    }

    private function existingPeriodRows(array $context): array
    {
        $rows = [];

        foreach ((array)($context['services']['accounting_periods'] ?? []) as $accountingPeriod) {
            if (!is_array($accountingPeriod)) {
                continue;
            }

            $rows[] = [
                'label' => (string)($accountingPeriod['label'] ?? ''),
                'period_start_display' => $this->displayDate((string)($accountingPeriod['period_start'] ?? '')),
                'period_end_display' => $this->displayDate((string)($accountingPeriod['period_end'] ?? '')),
                'period_start' => (string)($accountingPeriod['period_start'] ?? ''),
                'period_end' => (string)($accountingPeriod['period_end'] ?? ''),
            ];
        }

        return $rows;
    }

    private function tableInvalidationFact(): string
    {
        return str_replace('_', '.', $this->key());
    }

    private function resolveSelectedAccountingPeriod(array $accountingPeriods, int $accountingPeriodId): array
    {
        foreach ($accountingPeriods as $accountingPeriod) {
            if ((int)($accountingPeriod['id'] ?? 0) === $accountingPeriodId) {
                return (array)$accountingPeriod;
            }
        }

        return (array)($accountingPeriods[0] ?? []);
    }
}
