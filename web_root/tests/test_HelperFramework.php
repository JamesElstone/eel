<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(HelperFramework::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(HelperFramework::class, 'builds consistent labels from underscored keys', function () use ($harness): void {
        $harness->assertSame('Parsed Latest Year', HelperFramework::labelFromKey('parsed_latest_year', '_'));
        $harness->assertSame('No Role Assigned', HelperFramework::titleCase('NO ROLE ASSIGNED'));
    });

    $harness->check(HelperFramework::class, 'parses shared date and time formats through one helper', function () use ($harness): void {
        $parsed = HelperFramework::parseDateTimeValue('19/04/2026 14:30');
        $harness->assertTrue($parsed instanceof DateTimeImmutable, 'Expected a parsed DateTimeImmutable instance.');
        $harness->assertSame('2026-04-19 14:30:00', $parsed->format('Y-m-d H:i:s'));
    });

    $harness->check(HelperFramework::class, 'normalises dates and UTC datetimes consistently', function () use ($harness): void {
        $harness->assertSame('2026-04-19', HelperFramework::normaliseDate('19/04/2026'));
        $harness->assertSame('2026-04-19 13:30:00', HelperFramework::normaliseUtcDateTime('2026-04-19T14:30:00+01:00'));
    });

    $harness->check(HelperFramework::class, 'uses company-aware display date defaults', function () use ($harness): void {
        $harness->assertSame('19/04/26', HelperFramework::displayDate('2026-04-19'));
        $harness->assertSame('2026-04-19', HelperFramework::displayDate('2026-04-19', null, 'Y-m-d'));
        $harness->assertSame('19/04/2026 to 30/04/2026', HelperFramework::accountingPeriodLabel('2026-04-19', '2026-04-30', 1, 'd/m/Y'));
    });

    $harness->check(HelperFramework::class, 'formats http header labels from server keys consistently', function () use ($harness): void {
        $harness->assertSame('X-Forwarded-For', HelperFramework::httpHeaderLabelFromServerKey('HTTP_X_FORWARDED_FOR'));
        $harness->assertSame('Content-Type', HelperFramework::httpHeaderLabelFromServerKey('CONTENT_TYPE'));
    });
});
