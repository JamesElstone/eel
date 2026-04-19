<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _uploads_monthly_statusCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'uploads_monthly_status';
    }

    public function services(): array
    {
        return [];
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
        $selectedUploadId = (int)($page['selected_upload_id'] ?? $page['upload_id'] ?? 0);
        $settings = (array)($page['settings'] ?? []);
        $dateFormat = (string)($settings['date_format'] ?? '');
        $taxYears = (array)($page['tax_years'] ?? []);
        $monthStatus = (array)($page['month_status'] ?? []);

        $taxYearOptions = '';
        foreach ($taxYears as $taxYear) {
            if (!is_array($taxYear)) {
                continue;
            }
            $label = (string)($taxYear['label'] ?? HelperFramework::accountingPeriodLabel(
                (string)($taxYear['period_start'] ?? ''),
                (string)($taxYear['period_end'] ?? ''),
                $selectedCompanyId,
                $dateFormat
            ));
            $taxYearOptions .= '<option value="' . (int)($taxYear['id'] ?? 0) . '"' . ((int)($taxYear['id'] ?? 0) === $selectedTaxYearId ? ' selected' : '') . '>' . HelperFramework::escape($label) . '</option>';
        }

        $monthsHtml = '';
        foreach ($monthStatus as $month) {
            if (!is_array($month)) {
                continue;
            }
            $monthYear = trim((string)($month['year'] ?? ''));
            $monthYearHtml = $monthYear !== ''
                ? '<div class="month-year">' . HelperFramework::escape($monthYear) . '</div>'
                : '';
            $monthsHtml .= '<a class="' . HelperFramework::escape($this->monthStatusClass((string)($month['status'] ?? 'idle'))) . '" href="' . HelperFramework::escape($this->buildPageUrl('transactions', [
                'company_id' => $selectedCompanyId,
                'tax_year_id' => $selectedTaxYearId,
                'month_key' => (string)($month['month_key'] ?? ''),
            ])) . '">
                <div class="month-head">
                    <div>
                        <div class="month-name">' . HelperFramework::escape((string)($month['month'] ?? '')) . '</div>
                        ' . $monthYearHtml . '
                    </div>
                    <span class="month-dot"></span>
                </div>
                <div class="month-metric">' . (int)($month['transactions'] ?? 0) . '</div>
                <div class="helper">' . (int)($month['uncategorised'] ?? 0) . ' uncategorised</div>
            </a>';
        }

        return '<section class="eel-card-fragment" data-card="uploads-monthly-status">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Monthly Status</h2>
                </div>
                <div class="card-body">
                    <form method="get" action="" style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px; flex-wrap: wrap;" data-ajax-card-form="true" data-ajax-card-update="uploads-details,uploads-field-mapping,uploads-validate,uploads-monthly-status">
                        <input type="hidden" name="page" value="uploads">
                        <input type="hidden" name="company_id" value="' . $selectedCompanyId . '">'
                        . ($selectedUploadId > 0 ? '<input type="hidden" name="upload_id" value="' . $selectedUploadId . '">' : '') . '
                        <label for="uploads_monthly_status_tax_year_id" class="helper" style="margin: 0;">Accounting period</label>
                        <select class="select" id="uploads_monthly_status_tax_year_id" name="tax_year_id" data-ajax-card-autosubmit="true">' . $taxYearOptions . '</select>
                    </form>
                    <div class="month-grid">' . $monthsHtml . '</div>
                </div>
            </div>
        </section>';
    }

    private function monthStatusClass(string $status): string
    {
        return match ($status) {
            'bad', 'attention', 'uncategorised' => 'month-card month-card-bad',
            'warn', 'warning', 'ready' => 'month-card month-card-warn',
            'good', 'ok', 'complete', 'posted' => 'month-card month-card-ok',
            default => 'month-card',
        };
    }

    private function buildPageUrl(string $page, array $params = []): string
    {
        return '?' . http_build_query(['page' => $page] + $params);
    }
}
