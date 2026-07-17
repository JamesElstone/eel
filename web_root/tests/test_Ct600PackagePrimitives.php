<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

use eel_accounts\Service\Ct600IxbrlArtifact;
use eel_accounts\Service\Ct600PackageManifest;
use eel_accounts\Service\Ct600ReturnData;
use eel_accounts\Service\Ct600XmlBuilder;
use eel_accounts\Service\GovTalkEnvelopeBuilder;
use eel_accounts\Service\IrmarkService;

$harness = new GeneratedServiceClassTestHarness();

$harness->check(Ct600ReturnData::class, 'preserves a leading-zero UTR and reports phase-one blockers', static function () use ($harness): void {
    $return = ct600PrimitiveReturn([
        'utr' => '0123456789',
        'requiredSupplementaryPages' => ['CT600A'],
    ]);

    $harness->assertSame('0123456789', $return->utr);
    $harness->assertSame(
        ['Supplementary page CT600A is required but is not supported in phase one.'],
        $return->scopeBlockers()
    );
});

$harness->check(Ct600XmlBuilder::class, 'embeds computation then accounts as base64 with frozen hashes', static function () use ($harness): void {
    $fixture = ct600PrimitiveArtifacts();
    try {
        $return = ct600PrimitiveReturn();
        $result = (new Ct600XmlBuilder())->build($return, $fixture['accounts'], $fixture['computation']);
        $document = new DOMDocument();
        $harness->assertTrue($document->loadXML($result['xml'], LIBXML_NONET));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ct', Ct600XmlBuilder::CT_NAMESPACE);

        $harness->assertSame(
            1,
            $xpath->query('//ct:ReturnInfoSummary/ct:Accounts/ct:DifferentPeriod')?->length ?? 0
        );
        $harness->assertSame(
            0,
            $xpath->query('//ct:ReturnInfoSummary/ct:Accounts/ct:ThisPeriodAccounts')?->length ?? 0
        );

        $containers = $xpath->query('//ct:XBRLsubmission/*');
        $harness->assertSame(2, $containers?->length ?? 0);
        $harness->assertSame('Computation', $containers?->item(0)?->localName);
        $harness->assertSame('Accounts', $containers?->item(1)?->localName);

        $encoded = $xpath->query('//ct:EncodedInlineXBRLDocument');
        $harness->assertSame(2, $encoded?->length ?? 0);
        $harness->assertSame($fixture['computation_content'], base64_decode((string)$encoded?->item(0)?->textContent, true));
        $harness->assertSame($fixture['accounts_content'], base64_decode((string)$encoded?->item(1)?->textContent, true));
        $harness->assertSame(hash('sha256', $result['xml']), $result['body_sha256']);
        $harness->assertSame('2026-v1.994', $result['schema_version']);
    } finally {
        ct600PrimitiveCleanup($fixture);
    }
});

$harness->check(Ct600XmlBuilder::class, 'rejects cross-document identity mismatches and hook failures', static function () use ($harness): void {
    $fixture = ct600PrimitiveArtifacts(['accounts_registration' => 'ZZ999999']);
    try {
        ct600ExpectException(
            static fn() => (new Ct600XmlBuilder())->build(ct600PrimitiveReturn(), $fixture['accounts'], $fixture['computation']),
            'Accounts iXBRL company registration number does not match the CT600.'
        );
    } finally {
        ct600PrimitiveCleanup($fixture);
    }

    $fixture = ct600PrimitiveArtifacts();
    try {
        ct600ExpectException(
            static fn() => (new Ct600XmlBuilder())->build(
                ct600PrimitiveReturn(),
                $fixture['accounts'],
                $fixture['computation'],
                null,
                static fn(): array => ['errors' => ['Taxonomy acceptance window has expired.']]
            ),
            'Taxonomy acceptance window has expired.'
        );
    } finally {
        ct600PrimitiveCleanup($fixture);
    }
});

$harness->check(Ct600XmlBuilder::class, 'enforces the configured 25 MB-style serialized-body boundary', static function () use ($harness): void {
    $harness->assertSame(25_000_000, Ct600XmlBuilder::DEFAULT_MAX_BODY_BYTES);
    $fixture = ct600PrimitiveArtifacts();
    try {
        $return = ct600PrimitiveReturn();
        $baseline = (new Ct600XmlBuilder())->build($return, $fixture['accounts'], $fixture['computation']);
        $exact = (new Ct600XmlBuilder($baseline['body_bytes']))->build($return, $fixture['accounts'], $fixture['computation']);
        $harness->assertSame($baseline['body_bytes'], $exact['body_bytes']);
        ct600ExpectException(
            static fn() => (new Ct600XmlBuilder($baseline['body_bytes'] - 1))->build(
                $return,
                $fixture['accounts'],
                $fixture['computation']
            ),
            'exceeds the'
        );
    } finally {
        ct600PrimitiveCleanup($fixture);
    }
});

$harness->check(IrmarkService::class, 'produces a stable generic IRmark over the final GovTalk Body', static function () use ($harness): void {
    $fixture = ct600PrimitiveArtifacts();
    try {
        $body = (new Ct600XmlBuilder())->build(ct600PrimitiveReturn(), $fixture['accounts'], $fixture['computation']);
        $request = (new GovTalkEnvelopeBuilder())->buildSubmission(
            $body['xml'],
            'TEST',
            'ABC123',
            'test-sender',
            'test-password',
            '0123456789',
            '1234',
            'eel accounts',
            '1.0.0'
        );
        $second = (new IrmarkService())->applyToGovTalkXml($request['xml']);

        $harness->assertSame($request['irmark'], $second['irmark']);
        $harness->assertSame($request['irmark_display'], $second['irmark_display']);
        $harness->assertSame(20, strlen((string)base64_decode($request['irmark'], true)));
        $harness->assertSame(32, strlen($request['irmark_display']));
        $harness->assertSame('HMRC-CT-CT600', $request['class']);
        $harness->assertTrue(str_contains($request['xml'], '<GatewayTest>1</GatewayTest>'));
        $harness->assertTrue(str_contains($request['ir_envelope_xml'], $request['irmark']));
    } finally {
        ct600PrimitiveCleanup($fixture);
    }
});

$harness->check(Ct600PackageManifest::class, 'creates deterministic credential-free provenance', static function () use ($harness): void {
    $fixture = ct600PrimitiveArtifacts();
    try {
        $return = ct600PrimitiveReturn();
        $body = (new Ct600XmlBuilder())->build($return, $fixture['accounts'], $fixture['computation']);
        $request = (new GovTalkEnvelopeBuilder())->buildSubmission(
            $body['xml'],
            'TIL',
            'ABC124',
            'test-sender',
            'do-not-persist',
            '0123456789',
            '1234',
            'eel accounts',
            '1.0.0'
        );
        $manifest = Ct600PackageManifest::fromFinalizedPackage(
            $return,
            $fixture['accounts'],
            $fixture['computation'],
            $request,
            new DateTimeImmutable('2026-07-17T00:00:00+00:00')
        );
        $json = $manifest->toJson();

        $harness->assertSame('HMRC-CT-CT600-TIL', $request['class']);
        $harness->assertTrue(!str_contains($json, 'do-not-persist'));
        $harness->assertTrue(!str_contains($json, $fixture['accounts']->path));
        $harness->assertTrue(!str_contains($json, '0123456789'));
        $harness->assertSame(hash('sha256', $json), $manifest->sha256());
        $harness->assertSame($fixture['computation']->runId, (int)$manifest->computation['run_id']);
    } finally {
        ct600PrimitiveCleanup($fixture);
    }
});

$installedRimXsd = PROJECT_ROOT . 'third_party' . DIRECTORY_SEPARATOR . 'hmrc_ct600'
    . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'HMRC-CT-2014-v1-994'
    . DIRECTORY_SEPARATOR . 'CT-2014-v1-994.xsd';
if (is_file($installedRimXsd)) {
    $harness->check(Ct600XmlBuilder::class, 'validates against the installed official V1.994 RIM XSD', static function () use ($harness, $installedRimXsd): void {
        $fixture = ct600PrimitiveArtifacts();
        try {
            $result = (new Ct600XmlBuilder())->build(
                ct600PrimitiveReturn(),
                $fixture['accounts'],
                $fixture['computation'],
                $installedRimXsd
            );
            $harness->assertSame('passed', $result['schema_validation']['status']);
        } finally {
            ct600PrimitiveCleanup($fixture);
        }
    });
}

$installedEnvelopeXsd = dirname($installedRimXsd) . DIRECTORY_SEPARATOR . 'envelope-v2-0-HMRC.xsd';
if (is_file($installedEnvelopeXsd)) {
    $harness->check(GovTalkEnvelopeBuilder::class, 'validates the finalized request against the installed GovTalk 2.0 XSD', static function () use ($harness, $installedEnvelopeXsd): void {
        $fixture = ct600PrimitiveArtifacts();
        try {
            $body = (new Ct600XmlBuilder())->build(ct600PrimitiveReturn(), $fixture['accounts'], $fixture['computation']);
            $request = (new GovTalkEnvelopeBuilder())->buildSubmission(
                $body['xml'],
                'TIL',
                'ABC125',
                'test-sender',
                'test-password',
                '0123456789',
                '1234',
                'eel accounts',
                '1.0.0',
                $installedEnvelopeXsd
            );
            $harness->assertSame('passed', $request['envelope_schema_validation']['status']);
            $harness->assertTrue(str_contains($request['xml'], '<GatewayTest>0</GatewayTest>'));
        } finally {
            ct600PrimitiveCleanup($fixture);
        }
    });
}

/** @param array<string, mixed> $overrides */
function ct600PrimitiveReturn(array $overrides = []): Ct600ReturnData
{
    $calculation = [
        Ct600ReturnData::TURNOVER => 1000,
        Ct600ReturnData::TRADING_PROFITS => 65,
        Ct600ReturnData::LOSSES_BROUGHT_FORWARD => 0,
        Ct600ReturnData::NET_TRADING_PROFITS => 65,
        Ct600ReturnData::PROFITS_BEFORE_OTHER_DEDUCTIONS => 65,
        Ct600ReturnData::CAPITAL_ALLOWANCES => 629,
        Ct600ReturnData::TRADING_LOSSES => 0,
        Ct600ReturnData::TRADING_LOSSES_CARRIED_FORWARD => 0,
        Ct600ReturnData::PROFITS_BEFORE_DONATIONS_AND_GROUP_RELIEF => 0,
        Ct600ReturnData::CHARGEABLE_PROFITS => 0,
        Ct600ReturnData::AIA => 629,
        Ct600ReturnData::LOSS_ARISING => 564,
        Ct600ReturnData::CORPORATION_TAX => 0,
        Ct600ReturnData::NET_CORPORATION_TAX => 0,
        Ct600ReturnData::TOTAL_RELIEFS_AND_DEDUCTIONS => 0,
        Ct600ReturnData::TAX_PAYABLE => 0,
    ];
    if (isset($overrides['calculation']) && is_array($overrides['calculation'])) {
        $calculation = array_replace($calculation, $overrides['calculation']);
    }

    $arguments = [
        'companyId' => 4900,
        'accountingPeriodId' => 7900,
        'ctPeriodId' => 6001,
        'ctPeriodSequence' => 1,
        'accountsRunId' => 901,
        'computationRunId' => 902,
        'companyName' => 'Example Electrical Testing Ltd',
        'registrationNumber' => '12345678',
        'utr' => '0123456789',
        'companyType' => 0,
        'accountingPeriodStart' => '2022-09-05',
        'accountingPeriodEnd' => '2023-09-30',
        'periodStart' => '2022-09-05',
        'periodEnd' => '2023-09-04',
        'declarationName' => 'James Elstone',
        'declarationStatus' => 'Director',
        'declarationConfirmed' => true,
        'calculation' => $calculation,
        'multipleReturns' => true,
    ];
    unset($overrides['calculation']);

    /** @var array<string, mixed> $arguments */
    $arguments = array_replace($arguments, $overrides);
    return new Ct600ReturnData(...$arguments);
}

/** @param array<string, mixed> $overrides */
/** @return array<string, mixed> */
function ct600PrimitiveArtifacts(array $overrides = []): array
{
    $directory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create CT600 primitive test directory.');
    }
    $token = bin2hex(random_bytes(5));
    $accountsPath = $directory . DIRECTORY_SEPARATOR . 'accounts_' . $token . '.xhtml';
    $computationPath = $directory . DIRECTORY_SEPARATOR . 'computation_' . $token . '.xhtml';
    $accountsContent = '<html xmlns="http://www.w3.org/1999/xhtml"><body>accounts ' . $token . '</body></html>';
    $computationContent = '<html xmlns="http://www.w3.org/1999/xhtml"><body>computation ' . $token . '</body></html>';
    file_put_contents($accountsPath, $accountsContent);
    file_put_contents($computationPath, $computationContent);
    $accountsHash = (string)hash_file('sha256', $accountsPath);
    $computationHash = (string)hash_file('sha256', $computationPath);

    return [
        'accounts' => new Ct600IxbrlArtifact(
            documentType: Ct600IxbrlArtifact::ACCOUNTS,
            runId: 901,
            path: $accountsPath,
            filename: basename($accountsPath),
            outputSha256: $accountsHash,
            validatedSha256: $accountsHash,
            externalValidationPassed: true,
            periodStart: '2022-09-05',
            periodEnd: '2023-09-30',
            taxonomyProfile: 'FRS-105-2021',
            baseTaxonomyVersionDate: '2021-01-01',
            registrationNumber: (string)($overrides['accounts_registration'] ?? '12345678')
        ),
        'computation' => new Ct600IxbrlArtifact(
            documentType: Ct600IxbrlArtifact::COMPUTATION,
            runId: 902,
            path: $computationPath,
            filename: basename($computationPath),
            outputSha256: $computationHash,
            validatedSha256: $computationHash,
            externalValidationPassed: true,
            periodStart: '2022-09-05',
            periodEnd: '2023-09-04',
            taxonomyProfile: 'CT-COMPUTATIONS-2021',
            baseTaxonomyVersionDate: '2021-01-01',
            registrationNumber: '12345678',
            utr: '0123456789'
        ),
        'accounts_content' => $accountsContent,
        'computation_content' => $computationContent,
        'paths' => [$accountsPath, $computationPath],
    ];
}

/** @param array<string, mixed> $fixture */
function ct600PrimitiveCleanup(array $fixture): void
{
    foreach ((array)($fixture['paths'] ?? []) as $path) {
        if (is_string($path)) {
            @unlink($path);
        }
    }
}

function ct600ExpectException(callable $callback, string $messageFragment): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if (!str_contains($exception->getMessage(), $messageFragment)) {
            throw new RuntimeException(
                'Expected exception containing "' . $messageFragment . '", received: ' . $exception->getMessage()
            );
        }
        return;
    }

    throw new RuntimeException('Expected an exception containing "' . $messageFragment . '".');
}
