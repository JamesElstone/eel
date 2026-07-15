<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\VatRateRuleService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\VatRateRuleService $schemaService): void {
        $harness->check(\eel_accounts\Service\VatRateRuleService::class, 'keeps read methods safe before the rate table is populated', static function () use ($harness, $schemaService): void {
            \InterfaceDB::execute('DROP TABLE IF EXISTS vat_rate_rules');

            $harness->assertCount(0, $schemaService->fetchRules());
            $unavailable = $schemaService->fetchForDateAndScope('2026-07-14', 'standard', 'uk');
            $harness->assertFalse((bool)$unavailable['available']);
            $harness->assertTrue(str_contains((string)$unavailable['message'], 'No active sourced VAT rate'));
        });

        $harness->check(\eel_accounts\Service\VatRateRuleService::class, 'imports and looks up complete headline VAT rate history', static function () use ($harness, $schemaService): void {
            $schemaService->ensureSchema();
            \InterfaceDB::execute('DELETE FROM vat_rate_rules');

            $documents = vatRateRuleTestDocuments();
            $service = vatRateRuleTestService($documents);
            $result = $service->refreshFromHmrc();

            $harness->assertTrue((bool)($result['success'] ?? false));
            $harness->assertFalse((bool)($result['unchanged'] ?? true));
            $harness->assertSame(10, (int)($result['refreshed_count'] ?? -1));
            $harness->assertSame('8cea9cfb-2067-45bb-9938-6d2d14ff842a', (string)($result['source_content_id'] ?? ''));
            $harness->assertSame(64, strlen((string)($result['dataset_hash'] ?? '')));

            $rules = $service->fetchRules();
            $harness->assertCount(10, $rules);
            $harness->assertSame(10, count(array_filter($rules, static fn(array $rule): bool => (int)$rule['is_active'] === 1)));

            $harness->assertSame(15.0, $service->fetchForDateAndScope('2009-12-31', 'standard', 'uk')['rate_percentage']);
            $harness->assertSame(17.5, $service->fetchForDateAndScope('2010-01-01', 'standard', 'uk')['rate_percentage']);
            $harness->assertSame(20.0, $service->fetchForDateAndScope('2011-01-04', 'standard', 'UK')['rate_percentage']);
            $harness->assertSame(8.0, $service->fetchForDateAndScope('1997-08-31', 'reduced', 'uk')['rate_percentage']);
            $harness->assertSame(5.0, $service->fetchForDateAndScope('1997-09-01', 'reduced', 'uk')['rate_percentage']);
            $harness->assertSame(0.0, $service->fetchForDateAndScope('1973-04-01', 'zero', 'uk')['rate_percentage']);
            $harness->assertFalse((bool)$service->fetchForDateAndScope('1973-03-31', 'zero', 'uk')['available']);
            $harness->assertFalse((bool)$service->fetchForDateAndScope('2024-02-30', 'standard', 'uk')['available']);

            $currentStandard = $service->fetchForDateAndScope('2026-07-14', 'standard', 'uk');
            $harness->assertSame(null, $currentStandard['effective_to']);
            $harness->assertSame('2026-06-25 12:33:52', $currentStandard['source_updated_at']);
            $harness->assertTrue(str_contains((string)$currentStandard['notes'], \eel_accounts\Service\VatRateRuleService::CURRENT_RATES_URL));
        });

        $harness->check(\eel_accounts\Service\VatRateRuleService::class, 'makes an unchanged dataset a write-free no-op', static function () use ($harness): void {
            $service = vatRateRuleTestService(vatRateRuleTestDocuments());
            $before = (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules');
            $activeHash = (string)\InterfaceDB::fetchColumn('SELECT dataset_hash FROM vat_rate_rules WHERE is_active = 1 LIMIT 1');
            $result = $service->refreshFromHmrc();

            $harness->assertTrue((bool)($result['success'] ?? false));
            $harness->assertTrue((bool)($result['unchanged'] ?? false));
            $harness->assertSame(0, (int)($result['refreshed_count'] ?? -1));
            $harness->assertSame($before, (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules'));
            $harness->assertSame($activeHash, (string)\InterfaceDB::fetchColumn('SELECT dataset_hash FROM vat_rate_rules WHERE is_active = 1 LIMIT 1'));
        });

        $harness->check(\eel_accounts\Service\VatRateRuleService::class, 'rejects wrong or incomplete Content API documents without changing the active snapshot', static function () use ($harness): void {
            $activeHash = (string)\InterfaceDB::fetchColumn('SELECT dataset_hash FROM vat_rate_rules WHERE is_active = 1 LIMIT 1');
            $rowCount = (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules');

            $wrongPage = vatRateRuleTestMutateDocument(
                vatRateRuleTestDocuments(),
                \eel_accounts\Service\VatRateRuleService::NOTICE_CONTENT_API_URL,
                static function (array $document): array {
                    $document['base_path'] = '/guidance/not-vat-notice-700';
                    return $document;
                }
            );
            $wrongPageResult = vatRateRuleTestService($wrongPage)->refreshFromHmrc();
            $harness->assertFalse((bool)($wrongPageResult['success'] ?? true));
            $harness->assertTrue(str_contains((string)($wrongPageResult['errors'][0] ?? ''), 'expected Content API page'));

            $invalidId = vatRateRuleTestMutateDocument(
                vatRateRuleTestDocuments(),
                \eel_accounts\Service\VatRateRuleService::CURRENT_RATES_CONTENT_API_URL,
                static function (array $document): array {
                    $document['content_id'] = 'not-a-uuid';
                    return $document;
                }
            );
            $invalidIdResult = vatRateRuleTestService($invalidId)->refreshFromHmrc();
            $harness->assertFalse((bool)($invalidIdResult['success'] ?? true));
            $harness->assertTrue(str_contains((string)($invalidIdResult['errors'][0] ?? ''), 'valid content ID'));

            $missingTimestamp = vatRateRuleTestMutateDocument(
                vatRateRuleTestDocuments(),
                \eel_accounts\Service\VatRateRuleService::CURRENT_RATES_CONTENT_API_URL,
                static function (array $document): array {
                    unset($document['public_updated_at'], $document['updated_at']);
                    return $document;
                }
            );
            $missingTimestampResult = vatRateRuleTestService($missingTimestamp)->refreshFromHmrc();
            $harness->assertFalse((bool)($missingTimestampResult['success'] ?? true));
            $harness->assertTrue(str_contains((string)($missingTimestampResult['errors'][0] ?? ''), 'source update timestamp'));

            $harness->assertSame($rowCount, (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules'));
            $harness->assertSame($activeHash, (string)\InterfaceDB::fetchColumn('SELECT dataset_hash FROM vat_rate_rules WHERE is_active = 1 LIMIT 1'));
        });

        $harness->check(\eel_accounts\Service\VatRateRuleService::class, 'versions source wording changes even when dates and percentages are unchanged', static function () use ($harness): void {
            $beforeHash = (string)\InterfaceDB::fetchColumn('SELECT dataset_hash FROM vat_rate_rules WHERE is_active = 1 LIMIT 1');
            $beforeCount = (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules');
            $service = vatRateRuleTestService(vatRateRuleTestDocuments([], 20.0, ' (confirmed current rate)'));
            $result = $service->refreshFromHmrc();

            $harness->assertTrue((bool)($result['success'] ?? false));
            $harness->assertFalse((bool)($result['unchanged'] ?? true));
            $harness->assertSame(10, (int)($result['refreshed_count'] ?? -1));
            $harness->assertTrue((string)$result['dataset_hash'] !== $beforeHash);
            $harness->assertSame($beforeCount, (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules WHERE is_active = 0'));
            $harness->assertSame($beforeCount + 10, (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules'));
            $harness->assertTrue(str_contains(
                (string)$service->fetchForDateAndScope('2026-07-14', 'standard', 'uk')['notes'],
                'confirmed current rate'
            ));
        });

        $harness->check(\eel_accounts\Service\VatRateRuleService::class, 'retains versioned snapshots and the last valid dataset after source failure', static function () use ($harness): void {
            $priorRowCount = (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules');
            $changedService = vatRateRuleTestService(vatRateRuleTestDocuments([
                ['1 January 2030', '21%'],
            ], 21.0));
            $changed = $changedService->refreshFromHmrc();

            $harness->assertTrue((bool)($changed['success'] ?? false));
            $harness->assertSame(11, (int)($changed['refreshed_count'] ?? -1));
            $harness->assertSame(11, (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules WHERE is_active = 1'));
            $harness->assertSame($priorRowCount, (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules WHERE is_active = 0'));
            $harness->assertSame($priorRowCount + 11, (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules'));
            $harness->assertSame(21.0, $changedService->fetchForDateAndScope('2030-01-01', 'standard', 'uk')['rate_percentage']);

            $activeHash = (string)\InterfaceDB::fetchColumn('SELECT dataset_hash FROM vat_rate_rules WHERE is_active = 1 LIMIT 1');
            $rowCount = (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules');
            $invalidDocuments = vatRateRuleTestDocuments([
                ['1 January 2030', '21%'],
            ], 20.0);
            $failed = vatRateRuleTestService($invalidDocuments)->refreshFromHmrc();

            $harness->assertFalse((bool)($failed['success'] ?? true));
            $harness->assertTrue(str_contains((string)($failed['errors'][0] ?? ''), 'does not agree'));
            $harness->assertSame($rowCount, (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules'));
            $harness->assertSame($activeHash, (string)\InterfaceDB::fetchColumn('SELECT dataset_hash FROM vat_rate_rules WHERE is_active = 1 LIMIT 1'));

            $networkFailure = new \eel_accounts\Service\VatRateRuleService(
                static function (string $url): never {
                    throw new \RuntimeException('Fixture network failure.');
                }
            );
            $networkResult = $networkFailure->refreshFromHmrc();
            $harness->assertFalse((bool)($networkResult['success'] ?? true));
            $harness->assertSame($rowCount, (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules'));
            $harness->assertSame($activeHash, (string)\InterfaceDB::fetchColumn('SELECT dataset_hash FROM vat_rate_rules WHERE is_active = 1 LIMIT 1'));
        });

        $harness->check(\eel_accounts\Service\VatRateRuleService::class, 'rolls back the whole snapshot if a database write fails', static function () use ($harness): void {
            if (\InterfaceDB::driverName() !== 'sqlite') {
                $harness->skip('SQLite failure trigger is not applicable.');
            }

            $activeHash = (string)\InterfaceDB::fetchColumn('SELECT dataset_hash FROM vat_rate_rules WHERE is_active = 1 LIMIT 1');
            $rowCount = (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules');
            \InterfaceDB::execute(
                "CREATE TRIGGER vat_rate_rules_test_abort
                 BEFORE INSERT ON vat_rate_rules
                 WHEN NEW.rate_percentage = 22
                 BEGIN
                     SELECT RAISE(ABORT, 'fixture VAT rate insert failure');
                 END"
            );

            try {
                $result = vatRateRuleTestService(vatRateRuleTestDocuments([
                    ['1 January 2030', '21%'],
                    ['1 January 2031', '22%'],
                ], 22.0))->refreshFromHmrc();
            } finally {
                \InterfaceDB::execute('DROP TRIGGER IF EXISTS vat_rate_rules_test_abort');
            }

            $harness->assertFalse((bool)($result['success'] ?? true));
            $harness->assertSame($rowCount, (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules'));
            $harness->assertSame($activeHash, (string)\InterfaceDB::fetchColumn('SELECT dataset_hash FROM vat_rate_rules WHERE is_active = 1 LIMIT 1'));
            $harness->assertSame(11, (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM vat_rate_rules WHERE is_active = 1'));
        });
    }
);

function vatRateRuleTestService(array $documents): \eel_accounts\Service\VatRateRuleService
{
    return new \eel_accounts\Service\VatRateRuleService(
        static function (string $url) use ($documents): array {
            if (!array_key_exists($url, $documents)) {
                return ['status_code' => 404, 'body' => ''];
            }

            return ['status_code' => 200, 'body' => $documents[$url]];
        }
    );
}

function vatRateRuleTestDocuments(
    array $additionalStandardRows = [],
    float $currentStandardRate = 20.0,
    string $currentStandardSourceSuffix = ''
): array
{
    $standardRows = [
        ['1 April 1973', '10%'],
        ['29 July 1974', '8%'],
        ['18 June 1979', '15% (covers all previously standard and higher-rated supplies)'],
        ['1 April 1991', '17.5%'],
        ['1 December 2008', '15%'],
        ['1 January 2010', '17.5%'],
        ['4 January 2011', '20%' . $currentStandardSourceSuffix],
        ...$additionalStandardRows,
    ];
    $standardHtml = '';
    foreach ($standardRows as $row) {
        $standardHtml .= '<tr><td>' . $row[0] . '</td><td>' . $row[1] . '</td></tr>';
    }

    $noticeBody = '<h4 id="historic-vat-rates-in-the-uk">3.3.1 Historic VAT rates in the UK</h4>
        <p>VAT was introduced into the UK on 1 April 1973. The zero rate has existed throughout that time.</p>
        <h4 id="standard-rate">Standard rate</h4>
        <table><thead><tr><th>Date</th><th>Amount</th></tr></thead><tbody>' . $standardHtml . '</tbody></table>
        <h4 id="reduced-rate">Reduced rate</h4>
        <table><thead><tr><th>Date</th><th>Amount</th></tr></thead><tbody>
            <tr><td>1 April 1994</td><td>8% (covers supplies of fuel and power)</td></tr>
            <tr><td>1 September 1997</td><td>5% (extended to other supplies)</td></tr>
        </tbody></table>';

    $currentBody = '<h2>VAT rates for goods and services</h2><table><tbody>
        <tr><th>Standard rate</th><td>' . rtrim(rtrim(number_format($currentStandardRate, 3, '.', ''), '0'), '.') . '%</td><td>Most goods and services</td></tr>
        <tr><th>Reduced rate</th><td>5%</td><td>Some goods and services</td></tr>
        <tr><th>Zero rate</th><td>0%</td><td>Zero-rated goods and services</td></tr>
    </tbody></table>';

    $notice = json_encode([
        'base_path' => '/guidance/vat-guide-notice-700',
        'content_id' => '8cea9cfb-2067-45bb-9938-6d2d14ff842a',
        'public_updated_at' => '2026-06-25T13:33:52+01:00',
        'details' => ['body' => $noticeBody],
    ], JSON_THROW_ON_ERROR);
    $current = json_encode([
        'base_path' => '/vat-rates',
        'content_id' => 'f838c22a-b2aa-49be-bd95-153f593293a3',
        'public_updated_at' => '2026-04-23T11:28:57+01:00',
        'details' => ['body' => $currentBody],
    ], JSON_THROW_ON_ERROR);

    return [
        \eel_accounts\Service\VatRateRuleService::NOTICE_CONTENT_API_URL => $notice,
        \eel_accounts\Service\VatRateRuleService::CURRENT_RATES_CONTENT_API_URL => $current,
    ];
}

function vatRateRuleTestMutateDocument(array $documents, string $url, callable $mutator): array
{
    $document = json_decode((string)($documents[$url] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    $documents[$url] = json_encode($mutator($document), JSON_THROW_ON_ERROR);

    return $documents;
}
