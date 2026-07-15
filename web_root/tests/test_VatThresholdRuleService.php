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

$harness->run(
    \eel_accounts\Service\VatThresholdRuleService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\VatThresholdRuleService $service): void {
        $harness->check(\eel_accounts\Service\VatThresholdRuleService::class, 'has no seeded or built-in fallback', static function () use ($harness, $service): void {
            $service->fetchRules();
            InterfaceDB::execute('DELETE FROM vat_threshold_rules');

            $rule = $service->fetchForDate('2024-04-01', \eel_accounts\Service\VatThresholdRuleService::TYPE_TAXABLE_SUPPLIES);

            $harness->assertFalse((bool)$rule['available']);
            $harness->assertSame(null, $rule['registration_threshold']);
            $harness->assertTrue(str_contains((string)$rule['message'], 'unavailable'));
        });

        $harness->check(\eel_accounts\Service\VatThresholdRuleService::class, 'parses every source type and audits source anomalies', static function () use ($harness): void {
            $service = new \eel_accounts\Service\VatThresholdRuleService();
            $first = $service->parseContentApiJson(vatThresholdContentPayload(), '2026-07-14T10:11:12Z');
            $second = $service->parseContentApiJson(vatThresholdContentPayload(), '2026-07-15T12:00:00Z');
            $rows = (array)$first['rows'];

            $types = array_values(array_unique(array_column($rows, 'threshold_type')));
            sort($types);
            $harness->assertSame(['acquisitions', 'deregistration', 'distance_selling', 'taxable_supplies'], $types);
            $harness->assertSame((string)$first['dataset_hash'], (string)$second['dataset_hash']);
            $harness->assertSame(
                array_column($rows, 'row_hash'),
                array_column((array)$second['rows'], 'row_hash')
            );
            $harness->assertSame('2026-04-28 16:53:24', (string)$first['source_updated_at']);
            $harness->assertSame('2026-07-14 10:11:12', (string)$first['source_checked_at']);

            $corrected = vatThresholdFindRow($rows, static fn(array $row): bool =>
                $row['threshold_type'] === 'taxable_supplies'
                && $row['original_period_text'] === '1 January 1997 to 31 March 1998'
            );
            $harness->assertSame('1997-12-01', (string)$corrected['effective_from']);
            $harness->assertTrue(str_contains((string)$corrected['audit_notes'], \eel_accounts\Service\VatThresholdRuleService::REGISTRATION_MANUAL_URL));
            $harness->assertTrue(str_contains((string)$corrected['audit_notes'], \eel_accounts\Service\VatThresholdRuleService::DEREGISTRATION_MANUAL_URL));
            $harness->assertTrue(str_contains((string)$corrected['audit_notes'], \eel_accounts\Service\VatThresholdRuleService::CORROBORATING_SUPPLEMENT_URL));

            $current = vatThresholdFindRow($rows, static fn(array $row): bool =>
                $row['threshold_type'] === 'taxable_supplies' && $row['effective_to'] === null
            );
            $harness->assertSame('2025-04-01', (string)$current['effective_from']);
            $harness->assertSame(90000.00, (float)$current['registration_threshold']);

            $distance = vatThresholdFindRow($rows, static fn(array $row): bool => $row['threshold_type'] === 'distance_selling');
            $harness->assertSame('northern_ireland', (string)$distance['jurisdiction']);
            $harness->assertSame(8818.00, (float)$distance['registration_threshold']);

            $gbAcquisitions = vatThresholdFindRow($rows, static fn(array $row): bool =>
                $row['threshold_type'] === 'acquisitions' && $row['jurisdiction'] === 'great_britain'
            );
            $niAcquisitions = vatThresholdFindRow($rows, static fn(array $row): bool =>
                $row['threshold_type'] === 'acquisitions' && $row['jurisdiction'] === 'northern_ireland'
            );
            $harness->assertSame(null, $gbAcquisitions['registration_threshold']);
            $harness->assertSame(null, $niAcquisitions['registration_threshold']);
            $harness->assertTrue(str_contains((string)$gbAcquisitions['original_period_text'], 'Great Britain'));
            $harness->assertTrue(str_contains((string)$niAcquisitions['original_period_text'], 'Northern Ireland'));

            $warnings = (array)$first['warnings'];
            $harness->assertTrue(count($warnings) >= 2);
            $harness->assertTrue(count(array_filter($warnings, static fn(string $warning): bool => str_contains($warning, 'gap'))) >= 1);
            $harness->assertTrue(count(array_filter($warnings, static fn(string $warning): bool => str_contains($warning, 'overlap'))) >= 1);
        });

        $harness->check(\eel_accounts\Service\VatThresholdRuleService::class, 'publishes versioned snapshots and makes unchanged refreshes no-ops', static function () use ($harness): void {
            InterfaceDB::execute('DELETE FROM vat_threshold_rules');
            $calls = 0;
            $payload = vatThresholdContentPayload();
            $service = new \eel_accounts\Service\VatThresholdRuleService(
                static function (string $url) use (&$calls, $payload): array {
                    $calls++;
                    return ['status_code' => 200, 'body' => $payload, 'url' => $url];
                }
            );

            $first = $service->refreshFromHmrc();
            $firstRules = $service->fetchRules();
            $second = $service->refreshFromHmrc();

            $harness->assertTrue((bool)$first['success']);
            $harness->assertFalse((bool)$first['unchanged']);
            $harness->assertSame(count($firstRules), (int)$first['refreshed_count']);
            $harness->assertTrue(count($firstRules) > 0);
            $harness->assertTrue((bool)$second['success']);
            $harness->assertTrue((bool)$second['unchanged']);
            $harness->assertSame(0, (int)$second['refreshed_count']);
            $harness->assertSame(count($firstRules), count($service->fetchRules()));
            $harness->assertSame(2, $calls);

            $historic = $service->fetchForDate('2024-03-31', \eel_accounts\Service\VatThresholdRuleService::TYPE_TAXABLE_SUPPLIES);
            $changed = $service->fetchForDate('2024-04-01', \eel_accounts\Service\VatThresholdRuleService::TYPE_TAXABLE_SUPPLIES);
            $deregistration = $service->fetchForDate('2024-04-01', \eel_accounts\Service\VatThresholdRuleService::TYPE_DEREGISTRATION);
            $harness->assertSame(85000.00, (float)$historic['registration_threshold']);
            $harness->assertSame(90000.00, (float)$changed['registration_threshold']);
            $harness->assertSame(88000.00, (float)$deregistration['deregistration_threshold']);

            $changedService = new \eel_accounts\Service\VatThresholdRuleService(
                static fn(string $url): string => vatThresholdContentPayload(91000)
            );
            $changedResult = $changedService->refreshFromHmrc();
            $harness->assertTrue((bool)$changedResult['success']);
            $harness->assertFalse((bool)$changedResult['unchanged']);
            $harness->assertSame(91000.00, (float)$changedService->fetchForDate('2026-01-01')['registration_threshold']);
            $harness->assertSame(count($firstRules), (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_threshold_rules WHERE is_active = 0'));
            $harness->assertSame(count($firstRules), (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_threshold_rules WHERE is_active = 1'));
        });

        $harness->check(\eel_accounts\Service\VatThresholdRuleService::class, 'retains the last valid snapshot after fetch and parser failures', static function () use ($harness): void {
            $beforeHash = (string)InterfaceDB::fetchColumn('SELECT dataset_hash FROM vat_threshold_rules WHERE is_active = 1 LIMIT 1');
            $service = new \eel_accounts\Service\VatThresholdRuleService(static fn(string $url): string => '{broken json');

            $result = $service->refreshFromHmrc();

            $harness->assertFalse((bool)$result['success']);
            $harness->assertSame($beforeHash, (string)InterfaceDB::fetchColumn('SELECT dataset_hash FROM vat_threshold_rules WHERE is_active = 1 LIMIT 1'));
            $harness->assertSame(91000.00, (float)(new \eel_accounts\Service\VatThresholdRuleService())->fetchForDate('2026-01-01')['registration_threshold']);
        });

        $harness->check(\eel_accounts\Service\VatThresholdRuleService::class, 'rolls back deactivation and partial inserts when snapshot publication fails', static function () use ($harness): void {
            $beforeHash = (string)InterfaceDB::fetchColumn('SELECT dataset_hash FROM vat_threshold_rules WHERE is_active = 1 LIMIT 1');
            InterfaceDB::execute(
                "CREATE TRIGGER vat_threshold_force_failure
                 BEFORE INSERT ON vat_threshold_rules
                 WHEN NEW.threshold_type = 'deregistration'
                 BEGIN
                     SELECT RAISE(ABORT, 'forced VAT threshold publication failure');
                 END"
            );

            try {
                $service = new \eel_accounts\Service\VatThresholdRuleService(
                    static fn(string $url): string => vatThresholdContentPayload(92000)
                );
                $result = $service->refreshFromHmrc();
            } finally {
                InterfaceDB::execute('DROP TRIGGER IF EXISTS vat_threshold_force_failure');
            }

            $harness->assertFalse((bool)$result['success']);
            $harness->assertSame($beforeHash, (string)InterfaceDB::fetchColumn('SELECT dataset_hash FROM vat_threshold_rules WHERE is_active = 1 LIMIT 1'));
            $harness->assertSame(0, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM vat_threshold_rules WHERE registration_threshold = 92000"));
            $harness->assertSame(91000.00, (float)$service->fetchForDate('2026-01-01')['registration_threshold']);
        });

        InterfaceDB::execute('DELETE FROM vat_threshold_rules');
    }
);

/** @return array<string, mixed> */
function vatThresholdFindRow(array $rows, callable $predicate): array
{
    foreach ($rows as $row) {
        if (is_array($row) && $predicate($row)) {
            return $row;
        }
    }
    throw new RuntimeException('Expected VAT threshold row was not found.');
}

function vatThresholdContentPayload(int $currentTaxableThreshold = 90000): string
{
    $html = <<<'HTML'
<div class="govspeak">
<h2 id="registration-limits--taxable-supplies">2. Registration limits — taxable supplies</h2>
<h3 id="current-threshold">2.1 Current threshold</h3>
<p>The current registration threshold for taxable supplies is £__CURRENT__.</p>
<h3 id="previous-thresholds">2.2 Previous thresholds</h3>
<table><thead><tr><th>Period</th><th>Annual limit (in £)</th></tr></thead><tbody>
<tr><td>27 November 1996 to 30 November 1997</td><td>48,000</td></tr>
<tr><td>1 January 1997 to 31 March 1998</td><td>49,000</td></tr>
<tr><td>1 April 2023 to 31 March 2024</td><td>85,000</td></tr>
<tr><td>1 April 2024 to 31 March 2025</td><td>90,000</td></tr>
</tbody></table>
<h2 id="registration-limits--distance-selling">3. Registration limits — distance selling</h2>
<h3 id="distance-selling">3.1 Distance selling</h3>
<p>Businesses in Northern Ireland can use distance selling. The current distance selling threshold is £8,818. The threshold is based on sales made during a calendar year from 1 January to 31 December.</p>
<h2 id="registration-limits--acquisitions">4. Registration limits — acquisitions</h2>
<h3 id="from-1-january-2021">4.1 From 1 January 2021</h3>
<p>As a result of Brexit businesses registered for VAT in Great Britain do not need to register for VAT based on the level of their acquisitions. The threshold may still apply to previous years.</p>
<p>For businesses registered for VAT in Northern Ireland it is set at the same level as the normal UK VAT registration threshold. It is the combined total of acquisitions from the UK and all EU member states that counted towards the threshold.</p>
<h3 id="previous-thresholds-1">4.2 Previous thresholds</h3>
<table><thead><tr><th>Period</th><th>Annual limit (in £)</th></tr></thead><tbody>
<tr><td>1 April 2007 to 30 March 2008</td><td>64,000</td></tr>
<tr><td>1 April 2008 to 30 April 2009</td><td>67,000</td></tr>
<tr><td>1 April 2009 to 31 March 2010</td><td>68,000</td></tr>
<tr><td>1 April 2017 to 31 March 2018</td><td>85,000</td></tr>
<tr><td>1 April 2019 to 31 March 2020</td><td>85,000</td></tr>
<tr><td>1 April 2024 to 31 March 2025</td><td>90,000</td></tr>
</tbody></table>
<h2 id="cancelled-vat-registration-limits">5. Cancelled VAT registration limits</h2>
<h3 id="current-limit">5.1 Current limit</h3>
<p>The current VAT registration cancellation limit is £88,000.</p>
<h3 id="previous-limits">5.2 Previous limits</h3>
<table><thead><tr><th>Period</th><th>Annual limit (in £)</th></tr></thead><tbody>
<tr><td>1 April 2023 to 31 March 2024</td><td>83,000</td></tr>
<tr><td>1 April 2024 to 31 March 2025</td><td>88,000</td></tr>
</tbody></table>
</div>
HTML;
    $html = str_replace('__CURRENT__', number_format($currentTaxableThreshold), $html);

    return json_encode([
        'base_path' => '/government/publications/vat-notice-70011-cancelling-your-registration/vat-notice-70011-supplement',
        'content_id' => 'f3ee4855-d9ba-46a4-825d-563a26157b10',
        'updated_at' => '2026-04-28T17:53:24+01:00',
        'document_type' => 'html_publication',
        'details' => ['body' => $html],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
