<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

use eel_accounts\Service\Ct600ReturnData;
use eel_accounts\Service\Ct600ReturnDataFactory;
use eel_accounts\Service\IxbrlTaxonomyProfileService;

$harness = new GeneratedServiceClassTestHarness();

$harness->check(Ct600ReturnDataFactory::class, 'maps the two AP79-shaped nil returns from distinct frozen computation artifacts', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    $fixture = ct600FactoryFixture();
    try {
        $factory = new Ct600ReturnDataFactory();
        $declaration = ['name' => 'Fixture Director', 'status' => 'proper_officer', 'confirmed' => true];
        $ct6 = $factory->build($fixture['readiness'][6], $declaration);
        $ct7 = $factory->build($fixture['readiness'][7], $declaration);

        /** @var Ct600ReturnData $ct6Return */
        $ct6Return = $ct6['return'];
        /** @var Ct600ReturnData $ct7Return */
        $ct7Return = $ct7['return'];

        $harness->assertSame(0, $ct6Return->companyType);
        $harness->assertTrue($ct6Return->multipleReturns);
        $harness->assertSame('0123456789', $ct6Return->utr);
        $harness->assertSame('Proper officer', $ct6Return->declarationStatus);
        $harness->assertSame(9358, $ct6Return->amount(Ct600ReturnData::TURNOVER));
        $harness->assertSame(629, $ct6Return->amount(Ct600ReturnData::AIA));
        $harness->assertSame(0, $ct6Return->amount(Ct600ReturnData::CAPITAL_ALLOWANCES));
        $harness->assertSame(564, $ct6Return->amount(Ct600ReturnData::LOSS_ARISING));

        $harness->assertSame(666, $ct7Return->amount(Ct600ReturnData::TURNOVER));
        $harness->assertSame(4, $ct7Return->amount(Ct600ReturnData::TRADING_PROFITS));
        $harness->assertSame(4, $ct7Return->amount(Ct600ReturnData::LOSSES_BROUGHT_FORWARD));
        $harness->assertSame(0, $ct7Return->amount(Ct600ReturnData::NET_TRADING_PROFITS));
        $harness->assertTrue($ct6['computation']->runId !== $ct7['computation']->runId);
        $harness->assertSame($ct6['accounts']->runId, $ct7['accounts']->runId);
        $harness->assertTrue((bool)$ct6['validation']['hashes_reverified']);
    } finally {
        ct600FactoryCleanup($fixture);
        InterfaceDB::rollBack();
    }
});

$harness->check(Ct600ReturnDataFactory::class, 'fails closed for a changed iXBRL file and an amendment request', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    $fixture = ct600FactoryFixture();
    try {
        $factory = new Ct600ReturnDataFactory();
        $declaration = ['name' => 'Fixture Director', 'status' => 'authorised_person', 'confirmed' => true];
        file_put_contents($fixture['accounts_path'], 'changed after Arelle validation');
        ct600FactoryExpectException(
            static fn() => $factory->build($fixture['readiness'][6], $declaration),
            'changed after generation or external validation'
        );

        file_put_contents($fixture['accounts_path'], $fixture['accounts_xml']);
        $amendment = $fixture['readiness'][6];
        $amendment['submission_type'] = 'amendment';
        ct600FactoryExpectException(
            static fn() => $factory->build($amendment, $declaration),
            'amended returns are not implemented'
        );
    } finally {
        ct600FactoryCleanup($fixture);
        InterfaceDB::rollBack();
    }
});

$harness->check(Ct600ReturnDataFactory::class, 'does not allow a positive-tax return through the AP79 phase-one mapping', static function () use ($harness): void {
    InterfaceDB::beginTransaction();
    $fixture = ct600FactoryFixture();
    try {
        $summary = $fixture['summaries'][7];
        $summary['taxable_before_losses'] = 10.00;
        $summary['taxable_profit'] = 10.00;
        $summary['losses_brought_forward'] = 0.00;
        $summary['losses_used'] = 0.00;
        $summary['losses_carried_forward'] = 0.00;
        $summary['estimated_corporation_tax'] = 1.90;
        $summary['computation_hash'] = hash('sha256', 'positive-tax-fixture');
        InterfaceDB::prepareExecute(
            'UPDATE corporation_tax_computation_runs
             SET computation_hash = :hash, summary_json = :summary
             WHERE id = :id',
            [
                'hash' => $summary['computation_hash'],
                'summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'id' => $fixture['computation_run_ids'][7],
            ]
        );
        $readiness = $fixture['readiness'][7];
        $readiness['computation']['computation_hash'] = $summary['computation_hash'];

        ct600FactoryExpectException(
            static fn() => (new Ct600ReturnDataFactory())->build(
                $readiness,
                ['name' => 'Fixture Director', 'status' => 'proper_officer', 'confirmed' => true]
            ),
            'nil/loss returns only'
        );
    } finally {
        ct600FactoryCleanup($fixture);
        InterfaceDB::rollBack();
    }
});

/** @return array<string, mixed> */
function ct600FactoryFixture(): array
{
    $token = bin2hex(random_bytes(5));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (
            company_name, company_number, company_status, companies_house_type,
            has_insolvency_history, has_been_liquidated
         ) VALUES (:name, :number, :status, :type, 0, 0)',
        ['name' => 'AP79 Factory ' . $token, 'number' => '12345678', 'status' => 'active', 'type' => 'ltd']
    );
    $companyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_name = :name',
        ['name' => 'AP79 Factory ' . $token]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :start, :end)',
        [
            'company_id' => $companyId,
            'label' => 'AP79-' . $token,
            'start' => '2022-09-05',
            'end' => '2023-09-30',
        ]
    );
    $accountingPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
        ['company_id' => $companyId, 'label' => 'AP79-' . $token]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO year_end_reviews (company_id, accounting_period_id, is_locked, locked_at, locked_by)
         VALUES (:company_id, :accounting_period_id, 1, CURRENT_TIMESTAMP, :actor)',
        ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'actor' => 'factory-test']
    );

    $ctPeriods = [
        6 => ['sequence' => 1, 'start' => '2022-09-05', 'end' => '2023-09-04'],
        7 => ['sequence' => 2, 'start' => '2023-09-05', 'end' => '2023-09-30'],
    ];
    $ctPeriodIds = [];
    foreach ($ctPeriods as $key => $period) {
        InterfaceDB::prepareExecute(
            'INSERT INTO corporation_tax_periods (
                company_id, accounting_period_id, sequence_no, period_start, period_end, status
             ) VALUES (:company_id, :accounting_period_id, :sequence, :start, :end, :status)',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'sequence' => $period['sequence'],
                'start' => $period['start'],
                'end' => $period['end'],
                'status' => 'computed',
            ]
        );
        $ctPeriodIds[$key] = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM corporation_tax_periods
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id AND sequence_no = :sequence',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'sequence' => $period['sequence'],
            ]
        );
    }

    $directory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the CT600 factory test directory.');
    }
    $accountsPath = $directory . DIRECTORY_SEPARATOR . 'ct600_factory_accounts_' . $token . '.xhtml';
    $accountsXml = ct600FactoryIxbrl(
        IxbrlTaxonomyProfileService::SCHEMA_REF,
        '12345678',
        '2022-09-05',
        '2023-09-30',
        null,
        '10025.44'
    );
    file_put_contents($accountsPath, $accountsXml);
    $accountsHash = (string)hash_file('sha256', $accountsPath);
    InterfaceDB::prepareExecute(
        'INSERT INTO ixbrl_generation_runs (
            company_id, accounting_period_id, status, export_type, taxonomy_profile,
            validation_status, external_validation_status, generated_path, generated_filename,
            output_sha256, external_validated_sha256
         ) VALUES (
            :company_id, :accounting_period_id, :status, :export_type, :taxonomy_profile,
            :validation_status, :external_validation_status, :path, :filename, :hash, :validated_hash
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'status' => 'generated',
            'export_type' => 'filing_export',
            'taxonomy_profile' => IxbrlTaxonomyProfileService::PROFILE,
            'validation_status' => 'passed',
            'external_validation_status' => 'passed',
            'path' => $accountsPath,
            'filename' => basename($accountsPath),
            'hash' => $accountsHash,
            'validated_hash' => $accountsHash,
        ]
    );
    $accountsRunId = (int)InterfaceDB::fetchColumn(
        'SELECT MAX(id) FROM ixbrl_generation_runs WHERE company_id = :company_id',
        ['company_id' => $companyId]
    );

    $summaries = [
        6 => ct600FactorySummary($ctPeriodIds[6], 1, '2022-09-05', '2023-09-04', -563.22, 0, 0, 563.22, 563.22, 628.84),
        7 => ct600FactorySummary($ctPeriodIds[7], 2, '2023-09-05', '2023-09-30', 4.68, 563.22, 4.68, 0, 558.54, 0),
    ];
    $computationPaths = [];
    $computationRunIds = [];
    $readiness = [];
    foreach ($ctPeriods as $key => $period) {
        $path = $directory . DIRECTORY_SEPARATOR . 'ct600_factory_computation_' . $key . '_' . $token . '.xhtml';
        file_put_contents($path, ct600FactoryIxbrl(
            'https://xbrl.frc.org.uk/ct/2023-01-01/uk-ct-comp-2023-01-01.xsd',
            '12345678',
            $period['start'],
            $period['end'],
            '0123456789'
        ));
        $hash = (string)hash_file('sha256', $path);
        InterfaceDB::prepareExecute(
            'INSERT INTO corporation_tax_computation_runs (
                company_id, accounting_period_id, ct_period_id, period_start, period_end,
                status, computation_hash, summary_json, generated_path, generated_filename,
                taxonomy_profile, validation_status, external_validation_status,
                output_sha256, external_validated_sha256
             ) VALUES (
                :company_id, :accounting_period_id, :ct_period_id, :period_start, :period_end,
                :status, :computation_hash, :summary_json, :path, :filename,
                :taxonomy_profile, :validation_status, :external_validation_status,
                :output_sha256, :external_validated_sha256
             )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_id' => $ctPeriodIds[$key],
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'status' => 'generated',
                'computation_hash' => $summaries[$key]['computation_hash'],
                'summary_json' => json_encode($summaries[$key], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'path' => $path,
                'filename' => basename($path),
                'taxonomy_profile' => 'ct-computations-2023',
                'validation_status' => 'passed',
                'external_validation_status' => 'passed',
                'output_sha256' => $hash,
                'external_validated_sha256' => $hash,
            ]
        );
        $runId = (int)InterfaceDB::fetchColumn(
            'SELECT MAX(id) FROM corporation_tax_computation_runs WHERE ct_period_id = :ct_period_id',
            ['ct_period_id' => $ctPeriodIds[$key]]
        );
        $computationPaths[$key] = $path;
        $computationRunIds[$key] = $runId;
        InterfaceDB::prepareExecute(
            'UPDATE corporation_tax_periods SET latest_computation_run_id = :run_id WHERE id = :id',
            ['run_id' => $runId, 'id' => $ctPeriodIds[$key]]
        );
        $readiness[$key] = [
            'can_prepare' => true,
            'blockers' => [],
            'environment' => 'TEST',
            'utr' => '0123456789',
            'company' => ['id' => $companyId],
            'accounting_period' => ['id' => $accountingPeriodId],
            'ct_period' => ['id' => $ctPeriodIds[$key]],
            'accounts' => ['ok' => true, 'run_id' => $accountsRunId, 'path' => $accountsPath, 'hash' => $accountsHash],
            'computations' => ['ok' => true, 'run_id' => $runId, 'path' => $path, 'hash' => $hash],
            'computation' => [
                'available' => true,
                'computation_run_id' => $runId,
                'computation_hash' => $summaries[$key]['computation_hash'],
            ],
            'supplementary' => ['ok' => true, 'required_pages' => []],
        ];
    }

    return [
        'readiness' => $readiness,
        'summaries' => $summaries,
        'accounts_path' => $accountsPath,
        'accounts_xml' => $accountsXml,
        'computation_paths' => $computationPaths,
        'computation_run_ids' => $computationRunIds,
        'paths' => array_merge([$accountsPath], array_values($computationPaths)),
    ];
}

/** @return array<string, mixed> */
function ct600FactorySummary(
    int $ctPeriodId,
    int $sequence,
    string $start,
    string $end,
    float $taxableBefore,
    float $lossesBroughtForward,
    float $lossesUsed,
    float $lossCreated,
    float $lossesCarriedForward,
    float $aia
): array {
    $taxableProfit = max(0, round($taxableBefore - $lossesUsed, 2));
    $hash = hash('sha256', 'ct600-factory-summary-' . $ctPeriodId . '-' . $start);

    return [
        'available' => true,
        'ct_period_id' => $ctPeriodId,
        'ct_period_sequence_no' => $sequence,
        'period_start' => $start,
        'period_end' => $end,
        'taxable_before_losses' => $taxableBefore,
        'taxable_profit' => $taxableProfit,
        'taxable_loss' => $lossCreated,
        'loss_created_in_period' => $lossCreated,
        'losses_brought_forward' => $lossesBroughtForward,
        'losses_used' => $lossesUsed,
        'losses_carried_forward' => $lossesCarriedForward,
        'estimated_corporation_tax' => 0,
        'capital_allowances' => $aia,
        'capital_allowance_breakdown' => [
            'available' => true,
            'rows' => [[
                'aia_claimed' => $aia,
                'fya_claimed' => 0,
                'wda_claimed' => 0,
                'balancing_allowance' => 0,
                'balancing_charge' => 0,
            ]],
        ],
        'computation_hash' => $hash,
    ];
}

function ct600FactoryIxbrl(
    string $schemaRef,
    string $registration,
    string $start,
    string $end,
    ?string $utr = null,
    ?string $turnover = null
): string {
    $utrFact = $utr === null
        ? ''
        : '<ix:nonNumeric name="tax:UKTaxNumber" contextRef="duration">' . $utr . '</ix:nonNumeric>';
    $turnoverFact = $turnover === null
        ? ''
        : '<ix:nonFraction name="core:TurnoverRevenue" contextRef="duration" unitRef="GBP" decimals="2">'
            . $turnover . '</ix:nonFraction>';

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:ix="http://www.xbrl.org/2013/inlineXBRL"'
        . ' xmlns:xbrli="http://www.xbrl.org/2003/instance" xmlns:link="http://www.xbrl.org/2003/linkbase"'
        . ' xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:bus="urn:test:business"'
        . ' xmlns:core="urn:test:core" xmlns:tax="urn:test:tax"><head><title>Fixture</title></head><body>'
        . '<ix:header><ix:references><link:schemaRef xlink:type="simple" xlink:href="' . $schemaRef . '"/>'
        . '</ix:references><ix:resources><xbrli:context id="duration"><xbrli:entity>'
        . '<xbrli:identifier scheme="http://www.hmrc.gov.uk/">' . ($utr ?? $registration) . '</xbrli:identifier>'
        . '</xbrli:entity><xbrli:period><xbrli:startDate>' . $start . '</xbrli:startDate>'
        . '<xbrli:endDate>' . $end . '</xbrli:endDate></xbrli:period></xbrli:context>'
        . '<xbrli:unit id="GBP"><xbrli:measure>iso4217:GBP</xbrli:measure></xbrli:unit>'
        . '</ix:resources></ix:header>'
        . '<ix:nonNumeric name="bus:UKCompaniesHouseRegisteredNumber" contextRef="duration">'
        . $registration . '</ix:nonNumeric>'
        . '<ix:nonNumeric name="bus:StartDateForPeriodCoveredByReport" contextRef="duration">'
        . $start . '</ix:nonNumeric>'
        . '<ix:nonNumeric name="bus:EndDateForPeriodCoveredByReport" contextRef="duration">'
        . $end . '</ix:nonNumeric>'
        . $utrFact . $turnoverFact . '</body></html>';
}

/** @param array<string, mixed> $fixture */
function ct600FactoryCleanup(array $fixture): void
{
    foreach ((array)($fixture['paths'] ?? []) as $path) {
        if (is_string($path)) {
            @unlink($path);
        }
    }
}

function ct600FactoryExpectException(callable $callback, string $messageFragment): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if (str_contains($exception->getMessage(), $messageFragment)) {
            return;
        }
        throw new RuntimeException(
            'Expected exception containing "' . $messageFragment . '", received: ' . $exception->getMessage()
        );
    }

    throw new RuntimeException('Expected exception containing "' . $messageFragment . '".');
}
