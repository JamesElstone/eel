<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

use eel_accounts\Service\Ct600IxbrlArtifact;
use eel_accounts\Service\Ct600TaxonomyAcceptanceService;

$harness = new GeneratedServiceClassTestHarness();
$service = new Ct600TaxonomyAcceptanceService();

$harness->check(Ct600TaxonomyAcceptanceService::class, 'accepts the current FRC profile for AP79 and exposes catalog provenance', static function () use ($harness, $service): void {
    $result = $service->assessDocument('accounts', '2023-09-30', [
        'taxonomy_profile' => 'frc-2026-frs-105',
        'schema_ref' => 'https://xbrl.frc.org.uk/FRS-102/2026-01-01/FRS-102-2026-01-01.xsd',
        'base_taxonomy_version_date' => '2026-01-01',
    ]);

    $harness->assertTrue($result['accepted']);
    $harness->assertSame(2026, $result['release']);
    $harness->assertSame(null, $result['accepted_through']);
    $harness->assertSame('2026-07-17', $result['catalog_checked_at']);
    $harness->assertSame('2026-04-17', $result['source_updated_at']);
    $harness->assertSame(
        ['Taxonomy acceptance uses the HMRC catalog checked on 2026-07-17 (source updated 2026-04-17); recheck the official table before enabling LIVE submission.'],
        $result['warnings']
    );
});

$harness->check(Ct600TaxonomyAcceptanceService::class, 'accepts comparable computations profile and schemaRef metadata', static function () use ($harness, $service): void {
    $result = $service->assessDocument('computation', '2023-09-04', [
        'taxonomy_profile' => 'ct-computations-2023',
        'schema_ref' => 'https://www.gov.uk/hmrc/computations/2023-01-01/computations-2023.xsd',
        'base_taxonomy_version_date' => '2023-01-01',
    ]);

    $harness->assertTrue($result['accepted']);
    $harness->assertSame(2023, $result['release']);
    $harness->assertSame('2025-03-31', $result['accepted_through']);
});

$harness->check(Ct600TaxonomyAcceptanceService::class, 'applies inclusive HMRC acceptance boundaries and rejects an expired release', static function () use ($harness, $service): void {
    $boundary = $service->assessDocument('accounts', '2024-03-31', [
        'taxonomy_profile' => 'frc-2021-frs-105',
    ]);
    $expired = $service->assessDocument('accounts', '2024-04-01', [
        'taxonomy_profile' => 'frc-2021-frs-105',
    ]);

    $harness->assertTrue($boundary['accepted']);
    $harness->assertTrue(!$expired['accepted']);
    $harness->assertSame(
        ['Accounts iXBRL taxonomy FRC 2021 is accepted only for period ends from 2015-01-01 through 2024-03-31; the document period ends 2024-04-01.'],
        $expired['errors']
    );
});

$harness->check(Ct600TaxonomyAcceptanceService::class, 'fails closed for missing, unknown and conflicting taxonomy metadata', static function () use ($harness, $service): void {
    $missing = $service->assessDocument('accounts', '2023-09-30', []);
    $unknown = $service->assessDocument('computation', '2023-09-04', [
        'taxonomy_profile' => 'ct-computations-2022',
        'base_taxonomy_version_date' => '2022-01-01',
    ]);
    $conflict = $service->assessDocument('accounts', '2023-09-30', [
        'taxonomy_profile' => 'frc-2026-frs-105',
        'schema_ref' => 'https://xbrl.frc.org.uk/FRS-102/2025-01-01/FRS-102-2025-01-01.xsd',
    ]);

    $harness->assertSame(
        ['Accounts iXBRL taxonomy metadata is missing; provide a taxonomy profile, schemaRef, or base taxonomy version date.'],
        $missing['errors']
    );
    $harness->assertSame(
        ['Computation iXBRL taxonomy HMRC computations 2022 is not in the pinned HMRC acceptance catalog checked on 2026-07-17.'],
        $unknown['errors']
    );
    $harness->assertSame(
        ['Accounts iXBRL taxonomy metadata conflicts: taxonomy profile identifies FRC 2026, schemaRef identifies FRC 2025.'],
        $conflict['errors']
    );
});

$harness->check(Ct600TaxonomyAcceptanceService::class, 'validates artifact pairs through the Ct600XmlBuilder hook API', static function () use ($harness, $service): void {
    $hash = str_repeat('a', 64);
    $accounts = new Ct600IxbrlArtifact(
        Ct600IxbrlArtifact::ACCOUNTS,
        1,
        __FILE__,
        basename(__FILE__),
        $hash,
        $hash,
        true,
        '2022-09-05',
        '2023-09-30',
        'frc-2026-frs-105',
        '2026-01-01',
        'AA123456'
    );
    $computation = new Ct600IxbrlArtifact(
        Ct600IxbrlArtifact::COMPUTATION,
        2,
        __FILE__,
        basename(__FILE__),
        $hash,
        $hash,
        true,
        '2022-09-05',
        '2023-09-04',
        'ct-computations-2023',
        '2023-01-01',
        'AA123456',
        '0123456789'
    );

    $return = ct600TaxonomyReturnStub();
    $result = $service->validate($return, $accounts, $computation);
    $harness->assertTrue($result['accepted']);
    $harness->assertSame([], $result['errors']);
    $harness->assertSame(2026, $result['accounts']['release']);
    $harness->assertSame(2023, $result['computation']['release']);
});

function ct600TaxonomyReturnStub(): \eel_accounts\Service\Ct600ReturnData
{
    $calculation = [];
    foreach ([
        \eel_accounts\Service\Ct600ReturnData::TURNOVER,
        \eel_accounts\Service\Ct600ReturnData::TRADING_PROFITS,
        \eel_accounts\Service\Ct600ReturnData::LOSSES_BROUGHT_FORWARD,
        \eel_accounts\Service\Ct600ReturnData::NET_TRADING_PROFITS,
        \eel_accounts\Service\Ct600ReturnData::PROFITS_BEFORE_OTHER_DEDUCTIONS,
        \eel_accounts\Service\Ct600ReturnData::CAPITAL_ALLOWANCES,
        \eel_accounts\Service\Ct600ReturnData::TRADING_LOSSES,
        \eel_accounts\Service\Ct600ReturnData::TRADING_LOSSES_CARRIED_FORWARD,
        \eel_accounts\Service\Ct600ReturnData::PROFITS_BEFORE_DONATIONS_AND_GROUP_RELIEF,
        \eel_accounts\Service\Ct600ReturnData::CHARGEABLE_PROFITS,
        \eel_accounts\Service\Ct600ReturnData::CORPORATION_TAX,
        \eel_accounts\Service\Ct600ReturnData::NET_CORPORATION_TAX,
        \eel_accounts\Service\Ct600ReturnData::TOTAL_RELIEFS_AND_DEDUCTIONS,
        \eel_accounts\Service\Ct600ReturnData::TAX_PAYABLE,
    ] as $key) {
        $calculation[$key] = 0;
    }

    return new \eel_accounts\Service\Ct600ReturnData(
        companyId: 1,
        accountingPeriodId: 1,
        ctPeriodId: 1,
        ctPeriodSequence: 1,
        accountsRunId: 1,
        computationRunId: 2,
        companyName: 'Synthetic Company Ltd',
        registrationNumber: 'AA123456',
        utr: '0123456789',
        companyType: 0,
        accountingPeriodStart: '2022-09-05',
        accountingPeriodEnd: '2023-09-30',
        periodStart: '2022-09-05',
        periodEnd: '2023-09-04',
        declarationName: 'Test Director',
        declarationStatus: 'director',
        declarationConfirmed: true,
        calculation: $calculation,
        multipleReturns: true
    );
}
