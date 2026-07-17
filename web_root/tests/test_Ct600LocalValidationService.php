<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

use eel_accounts\Service\Ct600IxbrlArtifact;
use eel_accounts\Service\Ct600LocalValidationService;
use eel_accounts\Service\Ct600ReturnData;
use eel_accounts\Service\Ct600XmlBuilder;
use eel_accounts\Service\GovTalkEnvelopeBuilder;
use eel_accounts\Service\IrmarkService;

(new GeneratedServiceClassTestHarness())->run(
    Ct600LocalValidationService::class,
    static function (GeneratedServiceClassTestHarness $harness, Ct600LocalValidationService $service): void {
        $harness->check(
            Ct600LocalValidationService::class,
            'runs both pinned official XSDs and fails closed when the XSL engine is unavailable',
            static function () use ($harness, $service): void {
                $fixture = ct600LocalValidationFixture();
                try {
                    $result = $service->validate($fixture['xml']);

                    $harness->assertSame('passed', (string)$result['checks']['artifacts']['status']);
                    $harness->assertSame('passed', (string)$result['checks']['xml']['status']);
                    $harness->assertSame('passed', (string)$result['checks']['govtalk_xsd']['status']);
                    $harness->assertSame('passed', (string)$result['checks']['ct_xsd']['status']);
                    $harness->assertSame('passed', (string)$result['checks']['irmark']['status']);
                    $harness->assertSame(4, count((array)$result['artifact_hashes']));

                    if (!class_exists(XSLTProcessor::class)) {
                        $harness->assertSame(false, (bool)$result['ok']);
                        $harness->assertSame('unavailable', (string)$result['checks']['business_rules']['status']);
                        $harness->assertSame('XSL_EXTENSION_MISSING', (string)$result['errors'][0]['code']);
                    } else {
                        $harness->assertSame('passed', (string)$result['checks']['business_rules']['status']);
                        $harness->assertSame(true, (bool)$result['ok']);
                    }

                    $encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
                    $harness->assertTrue(is_string($encoded));
                    $harness->assertFalse(str_contains((string)$encoded, 'local-validation-sender'));
                    $harness->assertFalse(str_contains((string)$encoded, 'local-validation-password'));
                } finally {
                    ct600LocalValidationCleanup($fixture);
                }
            }
        );

        $harness->check(
            Ct600LocalValidationService::class,
            'rejects a changed body through the generic IRmark gate before submission',
            static function () use ($harness, $service): void {
                $fixture = ct600LocalValidationFixture();
                try {
                    $tampered = str_replace(
                        '<CompanyName>Local Validation Ltd</CompanyName>',
                        '<CompanyName>Tampered Validation Ltd</CompanyName>',
                        $fixture['xml']
                    );
                    $result = $service->validate($tampered);
                    $irMarkErrors = array_values(array_filter(
                        (array)$result['errors'],
                        static fn(array $error): bool => (string)($error['code'] ?? '') === 'IRMARK_MISMATCH'
                    ));

                    $harness->assertSame(false, (bool)$result['ok']);
                    $harness->assertSame('failed', (string)$result['checks']['irmark']['status']);
                    $harness->assertCount(1, $irMarkErrors);
                } finally {
                    ct600LocalValidationCleanup($fixture);
                }
            }
        );

        $harness->check(
            Ct600LocalValidationService::class,
            'returns structured XSD diagnostics without returning authentication secrets',
            static function () use ($harness, $service): void {
                $fixture = ct600LocalValidationFixture();
                try {
                    $invalid = str_replace(
                        '<GatewayTest>1</GatewayTest>',
                        '<GatewayTest>invalid</GatewayTest>',
                        $fixture['xml']
                    );
                    $result = $service->validate($invalid);
                    $xsdErrors = array_values(array_filter(
                        (array)$result['errors'],
                        static fn(array $error): bool => (string)($error['stage'] ?? '') === 'govtalk_xsd'
                    ));

                    $harness->assertSame(false, (bool)$result['ok']);
                    $harness->assertSame('failed', (string)$result['checks']['govtalk_xsd']['status']);
                    $harness->assertTrue($xsdErrors !== []);
                    $harness->assertTrue(str_starts_with((string)$xsdErrors[0]['code'], 'XSD-'));
                    $harness->assertSame('error', (string)$xsdErrors[0]['severity']);
                    $encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
                    $harness->assertFalse(str_contains((string)$encoded, 'local-validation-password'));
                } finally {
                    ct600LocalValidationCleanup($fixture);
                }
            }
        );

        $harness->check(
            Ct600LocalValidationService::class,
            'does not execute validation when a pinned HMRC artifact is missing',
            static function () use ($harness): void {
                $missing = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp'
                    . DIRECTORY_SEPARATOR . 'missing-ct600-artifact.xsd';
                $service = new Ct600LocalValidationService(ctSchemaPath: $missing);
                $result = $service->validate('<not-secret/>');

                $harness->assertSame(false, (bool)$result['ok']);
                $harness->assertSame('failed', (string)$result['checks']['artifacts']['status']);
                $harness->assertSame('skipped', (string)$result['checks']['xml']['status']);
                $harness->assertSame('HMRC_ARTIFACT_MISSING', (string)$result['errors'][0]['code']);
            }
        );

        if (class_exists(XSLTProcessor::class)) {
            $harness->check(
                Ct600LocalValidationService::class,
                'maps an official Schematron rejection to its HMRC number and assertion code',
                static function () use ($harness, $service): void {
                    $fixture = ct600LocalValidationFixture();
                    try {
                        $mismatched = preg_replace(
                            '/(<GovTalkDetails>.*?<Key Type="UTR">)0123456789/s',
                            '${1}9999999999',
                            $fixture['xml'],
                            1
                        );
                        if (!is_string($mismatched)) {
                            throw new RuntimeException('Could not create the Schematron rejection fixture.');
                        }
                        $mismatched = (new IrmarkService())->applyToGovTalkXml($mismatched)['xml'];
                        $result = $service->validate($mismatched);
                        $businessErrors = array_values(array_filter(
                            (array)$result['errors'],
                            static fn(array $error): bool => (string)($error['stage'] ?? '') === 'business_rules'
                        ));

                        $harness->assertSame(false, (bool)$result['ok']);
                        $harness->assertSame('failed', (string)$result['checks']['business_rules']['status']);
                        $harness->assertSame('5005', (string)$businessErrors[0]['number']);
                        $harness->assertSame('CTIRheader.1', (string)$businessErrors[0]['rule_code']);
                        $harness->assertSame(
                            ['a_CTIRheader.1'],
                            (array)$businessErrors[0]['assertion_ids']
                        );
                        $harness->assertSame(
                            'Keys in the GovTalkDetails do not match those in the IRheader.',
                            (string)$businessErrors[0]['text']
                        );
                    } finally {
                        ct600LocalValidationCleanup($fixture);
                    }
                }
            );
        }
    }
);

/** @return array{xml: string, paths: list<string>} */
function ct600LocalValidationFixture(): array
{
    $directory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the CT600 local-validation test directory.');
    }
    $token = bin2hex(random_bytes(5));
    $accountsPath = $directory . DIRECTORY_SEPARATOR . 'local_accounts_' . $token . '.xhtml';
    $computationPath = $directory . DIRECTORY_SEPARATOR . 'local_computation_' . $token . '.xhtml';
    file_put_contents(
        $accountsPath,
        '<html xmlns="http://www.w3.org/1999/xhtml"><body>synthetic accounts</body></html>'
    );
    file_put_contents(
        $computationPath,
        '<html xmlns="http://www.w3.org/1999/xhtml"><body>synthetic computation</body></html>'
    );
    $accountsHash = (string)hash_file('sha256', $accountsPath);
    $computationHash = (string)hash_file('sha256', $computationPath);

    $accounts = new Ct600IxbrlArtifact(
        documentType: Ct600IxbrlArtifact::ACCOUNTS,
        runId: 7101,
        path: $accountsPath,
        filename: basename($accountsPath),
        outputSha256: $accountsHash,
        validatedSha256: $accountsHash,
        externalValidationPassed: true,
        periodStart: '2023-01-01',
        periodEnd: '2023-12-31',
        taxonomyProfile: 'FRS-105-2021',
        baseTaxonomyVersionDate: '2021-01-01',
        registrationNumber: '12345678'
    );
    $computation = new Ct600IxbrlArtifact(
        documentType: Ct600IxbrlArtifact::COMPUTATION,
        runId: 7102,
        path: $computationPath,
        filename: basename($computationPath),
        outputSha256: $computationHash,
        validatedSha256: $computationHash,
        externalValidationPassed: true,
        periodStart: '2023-01-01',
        periodEnd: '2023-12-31',
        taxonomyProfile: 'CT-COMPUTATIONS-2021',
        baseTaxonomyVersionDate: '2021-01-01',
        registrationNumber: '12345678',
        utr: '0123456789'
    );
    $calculation = [
        Ct600ReturnData::TURNOVER => 1000,
        Ct600ReturnData::TRADING_PROFITS => 1,
        Ct600ReturnData::LOSSES_BROUGHT_FORWARD => 0,
        Ct600ReturnData::NET_TRADING_PROFITS => 1,
        Ct600ReturnData::PROFITS_BEFORE_OTHER_DEDUCTIONS => 1,
        Ct600ReturnData::CAPITAL_ALLOWANCES => 1,
        Ct600ReturnData::TRADING_LOSSES => 0,
        Ct600ReturnData::TRADING_LOSSES_CARRIED_FORWARD => 0,
        Ct600ReturnData::PROFITS_BEFORE_DONATIONS_AND_GROUP_RELIEF => 0,
        Ct600ReturnData::CHARGEABLE_PROFITS => 0,
        Ct600ReturnData::AIA => 1,
        Ct600ReturnData::LOSS_ARISING => 0,
        Ct600ReturnData::CORPORATION_TAX => 0,
        Ct600ReturnData::NET_CORPORATION_TAX => 0,
        Ct600ReturnData::TOTAL_RELIEFS_AND_DEDUCTIONS => 0,
        Ct600ReturnData::TAX_PAYABLE => 0,
    ];
    $return = new Ct600ReturnData(
        companyId: 7100,
        accountingPeriodId: 7100,
        ctPeriodId: 7100,
        ctPeriodSequence: 1,
        accountsRunId: 7101,
        computationRunId: 7102,
        companyName: 'Local Validation Ltd',
        registrationNumber: '12345678',
        utr: '0123456789',
        companyType: 6,
        accountingPeriodStart: '2023-01-01',
        accountingPeriodEnd: '2023-12-31',
        periodStart: '2023-01-01',
        periodEnd: '2023-12-31',
        declarationName: 'Validation Director',
        declarationStatus: 'Director',
        declarationConfirmed: true,
        calculation: $calculation,
        multipleReturns: false,
    );
    $body = (new Ct600XmlBuilder())->build($return, $accounts, $computation);
    $request = (new GovTalkEnvelopeBuilder())->buildSubmission(
        $body['xml'],
        'TEST',
        strtoupper(bin2hex(random_bytes(8))),
        'local-validation-sender',
        'local-validation-password',
        '0123456789',
        '1234',
        'EEL Accounts Test',
        '1.0'
    );

    return ['xml' => $request['xml'], 'paths' => [$accountsPath, $computationPath]];
}

/** @param array{xml: string, paths: list<string>} $fixture */
function ct600LocalValidationCleanup(array $fixture): void
{
    foreach ($fixture['paths'] as $path) {
        @unlink($path);
    }
}
