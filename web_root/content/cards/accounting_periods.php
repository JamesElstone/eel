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
                'key' => 'tax_years',
                'service' => TaxYearRepository::class,
                'method' => 'fetchTaxYears',
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

    public function render(array $context): string
    {

        if ((string)($context['company']['id'] ?? 0) <= 0) {
            return '<div class="helper">No information available until a company is selected.</div>';
        }

        $accountingGuidance = (array)($context['services']['accounting_guidance'] ?? []);
        $usesFiledPeriods = (string)($context['services']['accounting_guidance']['suggestion_basis'] ?? '') === 'companies_house_filed_periods';

        $companyId = (int)($context['company']['id'] ?? 0);
        $taxYearId = (int)($context['company']['tax_year_id'] ?? 0);
        $pageId = trim((string)($context['page']['page_id'] ?? ''));

        $taxYears = (array)($context['services']['tax_years'] ?? []);
        $selectedTaxYear = $this->resolveSelectedTaxYear($taxYears, $taxYearId);
        $selectedTaxYearId = (int)($selectedTaxYear['id'] ?? ($taxYearId > 0 ? $taxYearId : 0));

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
                        <input type="hidden" name="card_action" value="TaxYears">
                        <input type="hidden" name="intent" value="create_suggested_periods">
                        <input type="hidden" name="company_id" value="' . $companyId . '">
                        <button class="button primary" type="submit">Create Suggested Accounting Periods</button>
                    </form>
                </div>
            ';
            $missingHtml .= '</div>';
        }

        $existingPeriodsHtml = '';
        if ($taxYears !== []) {
            $existingPeriodRowsHtml = '';

            foreach ($taxYears as $taxYear) {
                if (!is_array($taxYear)) {
                    continue;
                }

                $existingPeriodRowsHtml .= '<tr>
                    <td>' . $this->escape((string)($taxYear['label'] ?? '')) . '</td>
                    <td>' . $this->escape($this->displayDate((string)($taxYear['period_start'] ?? ''))) . '</td>
                    <td>' . $this->escape($this->displayDate((string)($taxYear['period_end'] ?? ''))) . '</td>
                </tr>';
            }

            $existingPeriodsHtml = '
                <div class="table-scroll-mini">
                    <table>
                        <thead>
                            <tr>
                                <th>Alias</th>
                                <th>Start</th>
                                <th>End</th>
                            </tr>
                        </thead>
                        <tbody>' . $existingPeriodRowsHtml . '</tbody>
                    </table>
                </div>
            ';
        }

        $mainHtml = '
        <div class="panel-soft">
            <h4 class="card-title">Existing periods</h4>
            ' . $existingPeriodsHtml . '
        </div>
        <div class="panel-soft">
            <div class="form-flex-flow">
                <div class="form-row full">
                    <label for="tax_year_id">Accounting period</label>
                    <form method="post" data-ajax="true" data-accounting-period-selector="true">
                        <input type="hidden" name="action" value="set-page-context">
                        <input type="hidden" name="_ajax" value="1">
                        <input type="hidden" name="page" value="' . $this->escape($pageId) . '">
                        <select class="select" id="tax_year_id" name="tax_year_id">
                        <option value="0" data-period-label="" data-period-start="" data-period-end=""' . ($taxYearId === 0 ? ' selected' : '') . '>New Period</option>';
                        foreach ($taxYears as $taxYear) {
                            $mainHtml .= '<option value="' . 
                                ($taxYear['id'] ?? 0) . 
                                '" data-period-label="' . $this->escape((string)($taxYear['label'] ?? ''))
                                . '" data-period-start="' . $this->escape((string)($taxYear['period_start'] ?? ''))
                                . '" data-period-end="' . $this->escape((string)($taxYear['period_end'] ?? ''))
                                . '"'. (((int)($taxYear['id'] ?? 0) === $taxYearId) ? ' selected' : '')
                                . '>('
                                . $this->escape($this->displayDate((string)($taxYear['period_start'] ?? '')))
                                . ' to '
                                . $this->escape($this->displayDate((string)($taxYear['period_end'] ?? '')))
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
                <input type="hidden" id="accounting_period_intent" name="intent" value="' . ($selectedTaxYearId > 0 ? 'update_tax_period' : 'add_tax_period') . '">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" id="accounting_period_selected_tax_year_id" name="tax_year_id" value="' . $selectedTaxYearId . '" data-state-default="' . $selectedTaxYearId . '">
                <section data-state-fields="accounting_period_selected_tax_year_id,financial_period_label,period_start,period_end" data-state-target="save_accounting_period_button">
                <div class="form-flex-flow">
                    <div class="form-row half">
                        <label for="financial_period_label">Period Alias Name</label>
                        <input class="input" id="financial_period_label" name="financial_period_label" value="' . $this->escape((string)($selectedTaxYear['label'] ?? '')) . '">
                    </div>
                    <div class="form-row half">
                        <label for="period_start">Period start</label>
                        <input class="input" type="date" id="period_start" name="period_start" value="' . $this->escape((string)($selectedTaxYear['period_start'] ?? '')) . '">
                    </div>
                    <div class="form-row half">
                        <label for="period_end">Period end</label>
                        <input class="input" type="date" id="period_end" name="period_end" value="' . $this->escape((string)($selectedTaxYear['period_end'] ?? '')) . '">
                    </div>
                    <div class="form-row full">
                        <button class="button primary" id="save_accounting_period_button" type="submit" disabled>Save Tax Period Details</button>
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
                    ' . $guidanceHtml . '
                </div>
                <div>
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

    private function resolveSelectedTaxYear(array $taxYears, int $taxYearId): array
    {
        foreach ($taxYears as $taxYear) {
            if ((int)($taxYear['id'] ?? 0) === $taxYearId) {
                return (array)$taxYear;
            }
        }

        return (array)($taxYears[0] ?? []);
    }
}
