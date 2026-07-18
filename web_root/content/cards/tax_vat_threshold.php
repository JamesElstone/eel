<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _tax_vat_thresholdCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'tax_vat_threshold';
    }

    public function title(): string
    {
        return 'VAT Registration Threshold Monitor';
    }

    public function helper(array $context): string
    {
        return 'A read-only early warning based on posted accounting income. It is not a VAT return or a substitute for checking taxable turnover and the forward 30-day registration test.';
    }

    public function services(): array
    {
        return [[
            'key' => 'vat_turnover_monitoring',
            'service' => \eel_accounts\Service\VatTurnoverMonitoringService::class,
            'method' => 'fetchMonitoring',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ]];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $monitoring = (array)($context['services']['vat_turnover_monitoring'] ?? []);
        if (empty($monitoring['available'])) {
            return '<div class="helper">' . HelperFramework::escape((string)($monitoring['message'] ?? 'Select a company and accounting period to monitor the VAT threshold.')) . '</div>';
        }
        if (!empty($monitoring['not_started'])) {
            return '<div class="helper">This accounting period has not started, so no period-to-date VAT threshold comparison is available.</div>' . $this->links();
        }

        $settings = (array)($context['company']['settings'] ?? ($context['page']['settings'] ?? []));
        $threshold = (array)($monitoring['threshold'] ?? []);
        $thresholdValue = !empty($threshold['available']) ? $this->money($settings, $threshold['registration_threshold'] ?? 0) : 'Unavailable';
        $percent = $monitoring['threshold_percentage_used'] ?? null;
        $headroom = $monitoring['threshold_headroom'] ?? null;
        $headroomLabel = $headroom === null
            ? 'Unavailable'
            : ((float)$headroom >= 0
                ? $this->money($settings, $headroom)
                : $this->money($settings, abs((float)$headroom)) . ' above threshold');

        return '<div class="summary-grid">'
            . $this->summary('Gross income - AP to ' . (string)($monitoring['effective_date'] ?? ''), $this->money($settings, $monitoring['ap_to_date_gross_income'] ?? 0))
            . $this->summary('Gross income - trailing 12 months', $this->money($settings, $monitoring['trailing_12_month_gross_income'] ?? 0))
            . $this->summary('Registration threshold', $thresholdValue)
            . $this->summary('Threshold used', $percent === null ? 'Unavailable' : number_format((float)$percent, 1) . '%')
            . $this->summary('Headroom', $headroomLabel)
            . '</div>'
            . (empty($threshold['available']) ? $this->thresholdImportNotice() : '')
            . $this->warnings((array)($monitoring['warnings'] ?? []))
            . $this->links();
    }

    private function summary(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label)
            . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function warnings(array $warnings): string
    {
        if ($warnings === []) {
            return '';
        }

        return '<div class="helper"><strong>Important limitations</strong><ul><li>'
            . implode('</li><li>', array_map(static fn(mixed $warning): string => HelperFramework::escape((string)$warning), $warnings))
            . '</li></ul></div>';
    }

    private function links(): string
    {
        return '<div class="helper">Official guidance: '
            . '<a href="' . \eel_accounts\Service\VatThresholdRuleService::REGISTRATION_GUIDANCE_URL . '" target="_blank" rel="noopener noreferrer">VAT registration</a>'
            . ' and <a href="' . \eel_accounts\Service\VatThresholdRuleService::THRESHOLDS_URL . '" target="_blank" rel="noopener noreferrer">VAT thresholds</a>.'
            . '</div>';
    }

    private function thresholdImportNotice(): string
    {
        return '<div class="helper"><strong>Threshold unavailable.</strong> '
            . '<a class="button button-inline" href="?page=tax_artifacts">Import HMRC VAT thresholds on the Rates / Thresholds / Artifacts page</a>.'
            . '</div>';
    }

    private function money(array $settings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($settings, $value);
    }
}
