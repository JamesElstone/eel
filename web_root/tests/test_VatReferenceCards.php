<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$page = ['page_id' => 'tax_rates', 'page_cards' => ['tax_rates_vat', 'tax_thresholds_vat']];

$rateRow = [
    'rate_type' => 'standard', 'scope' => 'uk', 'effective_from' => '2011-01-04', 'effective_to' => null,
    'rate_percentage' => 20.0, 'source_url' => 'https://www.gov.uk/vat-rates',
    'source_updated_at' => '2026-06-25 00:00:00', 'source_checked_at' => '2026-07-14 00:00:00', 'is_active' => 1,
];
$rateContext = ['page' => $page, 'tax_rates_vat' => ['rules' => array_fill(0, 14, $rateRow)]];

$harness->run(_tax_rates_vatCard::class, static function (GeneratedServiceClassTestHarness $harness, _tax_rates_vatCard $card) use ($rateContext): void {
    $harness->check(_tax_rates_vatCard::class, 'uses an exportable 13-row VAT rate table and refresh action', static function () use ($harness, $card, $rateContext): void {
        $harness->assertSame('vat_rate_rules', (string)$card->services()[0]['key']);
        $html = $card->render($rateContext);
        $harness->assertTrue(str_contains($html, 'VAT rate rules 1-13 of 14'));
        $harness->assertTrue(str_contains($html, 'table-condensed-toggle'));
        $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertTrue(str_contains($html, 'Refresh HMRC VAT Rates'));
        $harness->assertTrue(str_contains($html, '>20%</td>'));
        $harness->assertSame(1, count($card->tables($rateContext)));
    });

    $harness->check(_tax_rates_vatCard::class, 'shows an explicit live import state when empty', static function () use ($harness, $card, $rateContext): void {
        $empty = $rateContext;
        $empty['tax_rates_vat']['rules'] = [];
        $html = $card->render($empty);
        $harness->assertTrue(str_contains($html, 'Import Live HMRC VAT Rates'));
        $harness->assertTrue(str_contains($html, 'starts empty'));
    });

    $harness->check(_tax_rates_vatCard::class, 'downstream schemas remain empty and permission migration replaces the old card key', static function () use ($harness): void {
        $root = dirname(__DIR__, 2);
        $migration003 = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_07_14_003_vat_monitoring_support_scope.sql');
        $migration005 = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_07_14_005_vat_reference_rates_and_tax_cards.sql');
        $master = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql');

        $harness->assertTrue(str_contains($migration003, 'CREATE TABLE IF NOT EXISTS vat_threshold_rules'));
        $harness->assertSame(false, str_contains($migration003, 'INSERT INTO vat_threshold_rules'));
        $harness->assertTrue(str_contains($migration005, 'CREATE TABLE IF NOT EXISTS vat_rate_rules'));
        $harness->assertSame(false, str_contains($migration005, 'INSERT INTO vat_rate_rules'));
        foreach (['tax_rates_ct', 'tax_rates_vat', 'tax_thresholds_vat'] as $cardKey) {
            $harness->assertTrue(str_contains($migration005, "SELECT role_id, '" . $cardKey . "'"));
        }
        $harness->assertTrue(str_contains($migration005, "WHERE card_key = 'tax_rates'"));
        $harness->assertTrue(str_contains($migration005, "DELETE FROM role_card_permissions\nWHERE card_key = 'tax_rates'"));
        $harness->assertTrue(str_contains($master, 'CREATE TABLE `vat_rate_rules`'));
        $harness->assertTrue(str_contains($master, 'CREATE TABLE `vat_threshold_rules`'));
        $harness->assertSame(false, str_contains($master, 'INSERT INTO `vat_rate_rules`'));
        $harness->assertSame(false, str_contains($master, 'INSERT INTO `vat_threshold_rules`'));
    });
});

$thresholdRow = [
    'threshold_type' => 'taxable_supplies', 'jurisdiction' => 'united_kingdom',
    'effective_from' => '2024-04-01', 'effective_to' => null, 'registration_threshold' => 90000.0,
    'deregistration_threshold' => null, 'source_url' => 'https://www.gov.uk/government/publications/vat-notice-70011-cancelling-your-registration/vat-notice-70011-supplement',
    'source_updated_at' => '2026-03-31 00:00:00', 'source_checked_at' => '2026-07-14 00:00:00',
    'audit_notes' => 'Published source anomaly retained for acquisitions.', 'is_active' => 1,
];
$thresholdContext = ['page' => $page, 'tax_thresholds_vat' => ['rules' => array_fill(0, 14, $thresholdRow)]];

$harness->run(_tax_thresholds_vatCard::class, static function (GeneratedServiceClassTestHarness $harness, _tax_thresholds_vatCard $card) use ($thresholdContext): void {
    $harness->check(_tax_thresholds_vatCard::class, 'uses an exportable 13-row threshold table with audit notes', static function () use ($harness, $card, $thresholdContext): void {
        $harness->assertSame('vat_threshold_rules', (string)$card->services()[0]['key']);
        $html = $card->render($thresholdContext);
        $harness->assertTrue(str_contains($html, 'VAT threshold rules 1-13 of 14'));
        $harness->assertTrue(str_contains($html, 'Registration Threshold / Annual Limit'));
        $harness->assertTrue(str_contains($html, 'Taxable Supplies (UK)'));
        $harness->assertTrue(str_contains($html, '£ 90,000.00'));
        $harness->assertTrue(str_contains($html, 'Source audit notes'));
        $harness->assertTrue(str_contains($html, 'Refresh HMRC VAT Thresholds'));
    });

    $harness->check(_tax_thresholds_vatCard::class, 'shows threshold unavailable until the first import', static function () use ($harness, $card, $thresholdContext): void {
        $empty = $thresholdContext;
        $empty['tax_thresholds_vat']['rules'] = [];
        $html = $card->render($empty);
        $harness->assertTrue(str_contains($html, 'Import Live HMRC VAT Thresholds'));
        $harness->assertTrue(str_contains($html, 'remains unavailable'));
    });
});
