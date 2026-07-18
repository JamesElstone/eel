<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$service = new \eel_accounts\Service\HmrcCt600VersionService();
$harness->check(\eel_accounts\Service\HmrcCt600VersionService::class, 'prepares an applicability fixture table', static function () use ($harness): void {
    InterfaceDB::prepareExecute('CREATE TABLE IF NOT EXISTS hmrc_ct_rim_packages (
        id INTEGER PRIMARY KEY,
        form_version VARCHAR(16) NOT NULL,
        artifact_version VARCHAR(64) NOT NULL,
        applicable_from DATE NULL,
        applicable_to DATE NULL,
        live_from DATETIME NULL,
        live_to DATETIME NULL,
        hmrc_status VARCHAR(64) NOT NULL,
        package_state VARCHAR(32) NOT NULL,
        applicability_status VARCHAR(32) NOT NULL,
        source_url VARCHAR(500) NOT NULL,
        download_url VARCHAR(1000) NULL,
        sha256 CHAR(64) NULL
    )');
    InterfaceDB::prepareExecute('DELETE FROM hmrc_ct_rim_packages');
    $harness->assertTrue(true);
});

$insertFixture = static function (): void {
    InterfaceDB::prepareExecute('INSERT INTO hmrc_ct_rim_packages
        (id, form_version, artifact_version, applicable_from, applicable_to, live_from, hmrc_status, package_state, applicability_status, source_url)
        VALUES
        (1, \'V2\', \'V3.99\', NULL, \'2015-03-31\', \'2015-07-22 00:00:00\', \'live\', \'verified\', \'open_start\', \'test\'),
        (2, \'V3\', \'V1.994\', \'2015-04-01\', NULL, \'2026-04-07 08:23:02\', \'live\', \'verified\', \'confirmed\', \'test\')');
};

$harness->check(\eel_accounts\Service\HmrcCt600VersionService::class, 'selects V2 for an older CT period', static function () use ($harness, $service, $insertFixture): void {
    InterfaceDB::beginTransaction();
    try {
        $insertFixture();
        $result = $service->resolveForCtPeriod('2014-04-01', '2015-03-31', new DateTimeImmutable('2026-07-18'));
        $harness->assertSame(true, $result['ok']);
        $harness->assertSame('V2', $result['form_version']);
        $harness->assertSame('V3.99', $result['artifact_version']);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check(\eel_accounts\Service\HmrcCt600VersionService::class, 'selects V2 when a CT period spans the V2/V3 boundary', static function () use ($harness, $service, $insertFixture): void {
    InterfaceDB::beginTransaction();
    try {
        $insertFixture();
        $result = $service->resolveForCtPeriod('2015-03-01', '2015-12-31', new DateTimeImmutable('2026-07-18'));
        $harness->assertSame(true, $result['ok']);
        $harness->assertSame('V2', $result['form_version']);
        $harness->assertSame('V3.99', $result['artifact_version']);
        $harness->assertSame(true, in_array('This CT period spans the V2/V3 boundary; the form version is selected from the CT period start date.', $result['warnings'], true));
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->check(\eel_accounts\Service\HmrcCt600VersionService::class, 'selects V3 for a CT period starting on the V3 boundary', static function () use ($harness, $service, $insertFixture): void {
    InterfaceDB::beginTransaction();
    try {
        $insertFixture();
        $result = $service->resolveForCtPeriod('2015-04-01', '2016-03-31', new DateTimeImmutable('2026-07-18'));
        $harness->assertSame(true, $result['ok']);
        $harness->assertSame('V3', $result['form_version']);
        $harness->assertSame('V1.994', $result['artifact_version']);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});

$harness->run(\eel_accounts\Service\HmrcCt600VersionService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\HmrcCt600VersionService $service): void {
    $harness->check(\eel_accounts\Service\HmrcCt600VersionService::class, 'rejects invalid period dates', static function () use ($harness, $service): void {
        $result = $service->resolveForCtPeriod('2025-02-30', '2026-01-31');
        $harness->assertSame(false, $result['ok']);
        $harness->assertSame('CT period dates must use YYYY-MM-DD.', $result['errors'][0]);
    });

    $harness->check(\eel_accounts\Service\HmrcCt600VersionService::class, 'rejects periods over twelve months', static function () use ($harness, $service): void {
        $result = $service->resolveForCtPeriod('2024-01-01', '2025-01-01');
        $harness->assertSame(false, $result['ok']);
        $harness->assertSame('The CT period exceeds 12 months.', $result['errors'][0]);
    });
});
