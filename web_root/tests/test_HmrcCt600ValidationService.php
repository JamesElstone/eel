<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcCt600ValidationService::class,
    static function (
        GeneratedServiceClassTestHarness $h,
        \eel_accounts\Service\HmrcCt600ValidationService $service
    ): void {
        $h->check(
            \eel_accounts\Service\HmrcCt600ValidationService::class,
            'fails closed before validation when no verified local RIM identity is supplied',
            static function () use ($h, $service): void {
                $resolved = $service->resolveArtifacts([]);
                $validated = $service->validateIrEnvelope(
                    '<IRenvelope xmlns="http://www.govtalk.gov.uk/taxation/CT/5"/>',
                    []
                );

                $h->assertSame(false, (bool)$resolved['ok']);
                $h->assertSame(false, (bool)$validated['ok']);
                $h->assertTrue(str_contains(implode(' ', (array)$resolved['errors']), 'not catalogued locally'));
            }
        );

        $h->check(
            \eel_accounts\Service\HmrcCt600ValidationService::class,
            'returns structured XML diagnostics for malformed input',
            static function () use ($h, $service): void {
                $method = new ReflectionMethod($service, 'document');
                $method->setAccessible(true);
                $result = $method->invoke($service, '<IRenvelope>');

                $h->assertSame(false, (bool)$result['ok']);
                $h->assertTrue(count((array)$result['diagnostics']) > 0);
                $h->assertSame('xml', $result['diagnostics'][0]['stage']);
                $h->assertTrue(trim((string)$result['diagnostics'][0]['message']) !== '');
            }
        );

        $h->check(
            \eel_accounts\Service\HmrcCt600ValidationService::class,
            'uses the pinned official CT XSD offline and preserves its failure diagnostics',
            static function () use ($h, $service): void {
                $schema = PROJECT_ROOT . 'third_party' . DIRECTORY_SEPARATOR . 'hmrc'
                    . DIRECTORY_SEPARATOR . 'ct600-rim' . DIRECTORY_SEPARATOR . 'ct600-v3-artefacts-v1.994'
                    . DIRECTORY_SEPARATOR . 'CT-2014-v1-994.xsd';
                $h->assertTrue(is_file($schema));

                $document = new DOMDocument();
                $h->assertSame(
                    true,
                    $document->loadXML(
                        '<IRenvelope xmlns="http://www.govtalk.gov.uk/taxation/CT/5"/>',
                        LIBXML_NONET | LIBXML_NOBLANKS
                    )
                );
                $method = new ReflectionMethod($service, 'schemaDiagnostics');
                $method->setAccessible(true);
                $diagnostics = $method->invoke($service, $document, $schema, 'ct_xsd');

                $h->assertTrue(count((array)$diagnostics) > 0);
                $h->assertSame('ct_xsd', $diagnostics[0]['stage']);
                $h->assertTrue(trim((string)$diagnostics[0]['code']) !== '');
                $h->assertTrue(trim((string)$diagnostics[0]['message']) !== '');
            }
        );

        $h->check(
            \eel_accounts\Service\HmrcCt600ValidationService::class,
            'validates the GovTalk structure with only pinned local schema dependencies',
            static function () use ($h, $service): void {
                $schema = PROJECT_ROOT . 'third_party' . DIRECTORY_SEPARATOR . 'hmrc'
                    . DIRECTORY_SEPARATOR . 'ct600-rim' . DIRECTORY_SEPARATOR . 'ct600-v3-artefacts-v1.994'
                    . DIRECTORY_SEPARATOR . 'envelope-v2-0-HMRC.xsd';
                $h->assertTrue(is_file($schema));

                $document = new DOMDocument();
                $h->assertSame(
                    true,
                    $document->loadXML(
                        '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope"><Body/></GovTalkMessage>',
                        LIBXML_NONET | LIBXML_NOBLANKS
                    )
                );
                $method = new ReflectionMethod($service, 'envelopeSchemaDiagnostics');
                $method->setAccessible(true);
                $diagnostics = $method->invoke($service, $document, $schema);

                $h->assertTrue(count((array)$diagnostics) > 0);
                $h->assertSame('envelope_xsd', $diagnostics[0]['stage']);
                $h->assertTrue(trim((string)$diagnostics[0]['message']) !== '');
            }
        );

        $h->check(
            \eel_accounts\Service\HmrcCt600ValidationService::class,
            'creates deterministic validation evidence from the same document and artifact hashes',
            static function () use ($h, $service): void {
                $artifacts = [
                    'package_id' => 21,
                    'package_sha256' => str_repeat('a', 64),
                    'artifacts' => [
                        'primary_schema' => ['sha256' => str_repeat('b', 64)],
                        'envelope_schema' => ['sha256' => str_repeat('c', 64)],
                    ],
                ];
                $diagnostics = [[
                    'stage' => 'ct_xsd',
                    'code' => 'fixture',
                    'type' => 'error',
                    'message' => 'Fixture diagnostic.',
                    'location' => '/',
                ]];
                $method = new ReflectionMethod($service, 'validationResult');
                $method->setAccessible(true);
                $first = $method->invoke($service, 'ct_xsd', '<IRenvelope/>', $artifacts, $diagnostics);
                $second = $method->invoke($service, 'ct_xsd', '<IRenvelope/>', $artifacts, $diagnostics);

                $h->assertSame(false, (bool)$first['ok']);
                $h->assertSame('failed', $first['status']);
                $h->assertSame(hash('sha256', '<IRenvelope/>'), $first['document_sha256']);
                $h->assertSame($first['validation_sha256'], $second['validation_sha256']);
                $h->assertSame('Fixture diagnostic.', $first['errors'][0]);
            }
        );
    }
);
