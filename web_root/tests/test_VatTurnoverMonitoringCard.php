<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$monitoring = [
    'available' => true,
    'not_started' => false,
    'effective_date' => '2025-02-15',
    'ap_to_date_gross_income' => 19000.00,
    'trailing_12_month_gross_income' => 29000.00,
    'threshold' => ['available' => true, 'registration_threshold' => 90000.00],
    'threshold_percentage_used' => 32.2,
    'threshold_headroom' => 61000.00,
    'bar_points' => [
        ['label' => 'Apr 24', 'value' => 20000.00],
        ['label' => 'May 24', 'value' => -2000.00],
    ],
    'rolling_points' => [
        ['label' => 'Apr 24', 'value' => 30000.00],
        ['label' => 'May 24', 'value' => 28000.00],
    ],
    'threshold_points' => [
        ['label' => 'Apr 24', 'value' => 90000.00],
        ['label' => 'May 24', 'value' => 90000.00],
    ],
    'months' => [[
        'label' => 'May 24',
        'start_date' => '2024-05-01',
        'end_date' => '2024-05-31',
        'gross_income' => -2000.00,
        'rolling_12_month_gross_income' => 28000.00,
        'registration_threshold' => 90000.00,
        'threshold_headroom' => 62000.00,
        'coverage_complete' => true,
        'coverage_label' => 'Complete',
    ]],
    'warnings' => ['Accounting income is a proxy for VAT-taxable turnover.'],
];
$context = [
    'company' => ['settings' => ['default_currency' => 'GBP']],
    'services' => ['vat_turnover_monitoring' => $monitoring],
];
$singlePointMonitoring = $monitoring;
$singlePointMonitoring['effective_date'] = '2025-04-12';
$singlePointMonitoring['bar_points'] = [['label' => 'Apr 25', 'value' => 2500.00]];
$singlePointMonitoring['rolling_points'] = [['label' => 'Apr 25', 'value' => 2500.00]];
$singlePointMonitoring['threshold_points'] = [['label' => 'Apr 25', 'value' => 90000.00]];
$singlePointContext = [
    'company' => ['settings' => ['default_currency' => 'GBP']],
    'services' => ['vat_turnover_monitoring' => $singlePointMonitoring],
];
$unavailableThresholdContext = $context;
$unavailableThresholdContext['services']['vat_turnover_monitoring']['threshold'] = [
    'available' => false,
    'registration_threshold' => null,
];
$unavailableThresholdContext['services']['vat_turnover_monitoring']['threshold_percentage_used'] = null;
$unavailableThresholdContext['services']['vat_turnover_monitoring']['threshold_headroom'] = null;
$unavailableThresholdContext['services']['vat_turnover_monitoring']['threshold_points'] = [];

$harness->run(_vat_turnover_monitoringCard::class, static function (GeneratedServiceClassTestHarness $harness, _vat_turnover_monitoringCard $card) use ($context, $singlePointContext, $unavailableThresholdContext): void {
    $harness->check(_vat_turnover_monitoringCard::class, 'renders signed monthly data, rolling threshold lines, headroom and coverage', static function () use ($harness, $card, $context): void {
        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'Monthly gross accounting income (signed bar values)'));
        $harness->assertSame(true, str_contains($html, 'chart-bar-negative'));
        $harness->assertSame(true, str_contains($html, 'Cumulative Income against Threshold'));
        $harness->assertSame(true, str_contains($html, '-£ 2,000.00'));
        $harness->assertSame(true, str_contains($html, '£ 62,000.00'));
        $harness->assertSame(true, str_contains($html, '>Complete</span>'));
        $harness->assertSame(true, str_contains($html, 'https://www.gov.uk/register-for-vat'));
        $harness->assertSame(true, str_contains($html, 'data-table-key="vat_turnover_monitoring"'));
        $harness->assertSame(2, substr_count($html, '<section class="panel-soft">'));
        $harness->assertSame(true, str_contains($html, '<section class="panel-soft"><div class="helper"><strong>Important limitations and coverage checks</strong>'));
        $harness->assertSame(true, str_contains($html, '<div class="table-scroll vat-turnover-monitoring-table"'));
        $harness->assertSame(true, str_contains($html, 'table-condensed-toggle'));
        $harness->assertSame(true, str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertSame(true, strpos($html, 'Important limitations and coverage checks') < strpos($html, 'summary-grid'));
        $harness->assertSame(true, strpos($html, 'Official guidance:') < strpos($html, 'summary-grid'));
    });

    $harness->check(_vat_turnover_monitoringCard::class, 'keeps the VAT threshold series for a partial first month with one chart point', static function () use ($harness, $card, $singlePointContext): void {
        $html = $card->render($singlePointContext);

        $harness->assertSame(true, str_contains($html, 'Cumulative Income against Threshold'));
        $harness->assertSame(true, str_contains($html, 'As at 2025-04-12'));
        $harness->assertSame(true, str_contains($html, 'repeated at the effective date solely to render this comparison'));
        $harness->assertSame(false, str_contains($html, 'No chart data'));
    });

    $harness->check(_vat_turnover_monitoringCard::class, 'paginates monthly rows at 13 and exposes the table for export', static function () use ($harness, $card, $context): void {
        $pagedContext = $context;
        $pagedContext['services']['vat_turnover_monitoring']['months'] = array_fill(0, 14, $context['services']['vat_turnover_monitoring']['months'][0]);
        $html = $card->render($pagedContext);

        $harness->assertSame(true, str_contains($html, 'Months 1-13 of 14'));
        $harness->assertSame(1, count($card->tables($pagedContext)));
    });

    $harness->check(_vat_turnover_monitoringCard::class, 'directs the user to import sourced thresholds when none are available', static function () use ($harness, $card, $unavailableThresholdContext): void {
        $html = $card->render($unavailableThresholdContext);

        $harness->assertTrue(str_contains($html, 'Threshold unavailable.'));
        $harness->assertTrue(str_contains($html, 'href="?page=tax_artifacts"'));
        $harness->assertTrue(str_contains($html, 'Import HMRC VAT thresholds'));
    });
});

$harness->run(_tax_vat_thresholdCard::class, static function (GeneratedServiceClassTestHarness $harness, _tax_vat_thresholdCard $card) use ($context, $unavailableThresholdContext): void {
    $harness->check(_tax_vat_thresholdCard::class, 'renders compact AP and trailing VAT threshold summary', static function () use ($harness, $card, $context): void {
        $html = $card->render($context);

        $harness->assertSame(true, str_contains($html, 'Gross income - AP to 2025-02-15'));
        $harness->assertSame(true, str_contains($html, '32.2%'));
        $harness->assertSame(true, str_contains($html, '£ 61,000.00'));
        $harness->assertSame(true, str_contains($html, 'https://www.gov.uk/how-vat-works/vat-thresholds'));
    });

    $harness->check(_tax_vat_thresholdCard::class, 'directs the user to the source import when the threshold table is empty', static function () use ($harness, $card, $unavailableThresholdContext): void {
        $html = $card->render($unavailableThresholdContext);

        $harness->assertTrue(str_contains($html, 'Threshold unavailable.'));
        $harness->assertTrue(str_contains($html, 'Import HMRC VAT thresholds'));
    });
});

$harness->run(_vat_support_scopeCard::class, static function (GeneratedServiceClassTestHarness $harness, _vat_support_scopeCard $card): void {
    $harness->check(_vat_support_scopeCard::class, 'renders the LIVE-confirmed unsupported-scope marker', static function () use ($harness, $card): void {
        $html = $card->render(['services' => ['vat_support_scope' => [
            'tax_year_end_read_only' => true,
            'message' => \eel_accounts\Service\VatSupportScopeService::UNSUPPORTED_MESSAGE,
        ]]]);

        $harness->assertSame(true, str_contains($html, 'data-vat-support-read-only="1"'));
        $harness->assertSame(true, str_contains($html, 'Unsupported VAT scope'));
    });
});
