<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

/** @return array<string, mixed> */
function hmrcPackageTestFrozenModel(string $accountsBasisHash): array
{
    return [
        'identity' => [
            'company_name' => 'Elstoné Electricals Limited',
            'company_number' => '12345678',
        ],
        'filing_identity' => ['utr' => '0123456789'],
        'accounts_report' => ['basis_hash' => $accountsBasisHash],
        'accounting_period' => [
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
        ],
        'ct_period' => [
            'id' => 996003,
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
        ],
    ];
}

function hmrcPackageTestIxbrl(string $startDate, string $endDate, bool $includeUtr): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<html xmlns="http://www.w3.org/1999/xhtml" '
        . 'xmlns:xbrli="http://www.xbrl.org/2003/instance"><body>'
        . '<xbrli:identifier>12345678</xbrli:identifier>'
        . '<xbrli:startDate>' . $startDate . '</xbrli:startDate>'
        . '<xbrli:endDate>' . $endDate . '</xbrli:endDate>'
        . ($includeUtr ? '<p>Corporation Tax UTR 0123456789</p>' : '')
        . '</body></html>';
}

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcSubmissionPackageService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\HmrcSubmissionPackageService $service
    ): void {
        $harness->check(
            \eel_accounts\Service\HmrcSubmissionPackageService::class,
            'fails closed when a persisted submission body is unavailable',
            static function () use ($harness, $service): void {
                $result = $service->buildSubmissionEnvelope(0);

                $harness->assertSame(false, (bool)($result['ok'] ?? true));
                $harness->assertSame('Select a persisted CT600 submission.', (string)($result['errors'][0] ?? ''));
                $harness->assertSame('', $service->hashPackage(0));
            }
        );

        $harness->check(
            \eel_accounts\Service\HmrcSubmissionPackageService::class,
            'records unambiguous CT600 and iXBRL artifact roles with mapping provenance',
            static function () use ($harness, $service): void {
                $method = new ReflectionMethod($service, 'manifestArtifacts');
                $method->setAccessible(true);
                $artifacts = (array)$method->invoke(
                    $service,
                    [
                        'accounting_period_id' => 79,
                        'ct_period_id' => 6,
                        'model' => [
                            'period' => ['start_date' => '2022-09-05', 'end_date' => '2023-09-04'],
                            'attachments' => ['supplementary_pages' => []],
                        ],
                        'rim' => ['form_version' => 'V3', 'artifact_version' => 'V1.994'],
                        'mapping_profile' => [
                            'id' => 6, 'profile_name' => 'reviewed_ct600_v3_v1_994_return_v3',
                            'revision_no' => 1, 'content_hash' => str_repeat('a', 64),
                        ],
                    ],
                    ['filename' => 'ct600.xml'],
                    [
                        'filename' => 'accounts.xhtml', 'hash' => str_repeat('b', 64),
                        'taxonomy_profile' => 'FRS-102/2025', 'validation_status' => 'passed',
                    ],
                    [
                        'filename' => 'computation.xhtml', 'hash' => str_repeat('c', 64),
                        'taxonomy_profile' => '2025/V1.0.0', 'mapping_profile_id' => 8,
                        'mapping_hash' => str_repeat('d', 64), 'validation_status' => 'passed',
                    ],
                    str_repeat('e', 64),
                    'ct600-final.xml',
                    'passed'
                );

                $byRole = array_column($artifacts, null, 'role');
                $harness->assertSame(
                    ['ct600_return_xml', 'statutory_accounts_ixbrl', 'ct_computation_ixbrl'],
                    array_column($artifacts, 'role')
                );
                $harness->assertSame('V3/V1.994', (string)$byRole['ct600_return_xml']['schema_version']);
                $harness->assertSame(6, (int)$byRole['ct600_return_xml']['mapping_profile_id']);
                $harness->assertSame('reviewed_ct600_v3_v1_994_return_v3', (string)$byRole['ct600_return_xml']['mapping_profile_name']);
                $harness->assertSame([], (array)$byRole['ct600_return_xml']['supplementary_pages']);
                $harness->assertSame('FRS-102/2025', (string)$byRole['statutory_accounts_ixbrl']['schema_version']);
                $harness->assertSame('2025/V1.0.0', (string)$byRole['ct_computation_ixbrl']['schema_version']);
                $harness->assertSame(8, (int)$byRole['ct_computation_ixbrl']['mapping_profile_id']);
            }
        );

        $harness->check(
            \eel_accounts\Service\HmrcSubmissionPackageService::class,
            'uses injected builders and artifact locators without masking readiness errors',
            static function () use ($harness): void {
                InterfaceDB::beginTransaction();
                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (id, company_name, company_number)
                     VALUES (:id, :company_name, :company_number)',
                    ['id' => 996001, 'company_name' => 'Package Fixture Limited', 'company_number' => '12345678']
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                     VALUES (:id, :company_id, :label, :period_start, :period_end)',
                    [
                        'id' => 996002,
                        'company_id' => 996001,
                        'label' => 'Package fixture 2024',
                        'period_start' => '2024-01-01',
                        'period_end' => '2024-12-31',
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO corporation_tax_periods
                        (id, company_id, accounting_period_id, sequence_no, period_start, period_end, status)
                     VALUES
                        (:id, :company_id, :accounting_period_id, :sequence_no, :period_start, :period_end, :status)',
                    [
                        'id' => 996003,
                        'company_id' => 996001,
                        'accounting_period_id' => 996002,
                        'sequence_no' => 1,
                        'period_start' => '2024-01-01',
                        'period_end' => '2024-12-31',
                        'status' => 'ready',
                    ]
                );

                $locatorCalls = 0;
                $builderFailure = new \eel_accounts\Service\HmrcSubmissionPackageService(
                    static function () use (&$locatorCalls): array {
                        $locatorCalls++;
                        return ['ok' => false, 'errors' => ['Accounts locator should not have run.']];
                    },
                    static function () use (&$locatorCalls): array {
                        $locatorCalls++;
                        return ['ok' => false, 'errors' => ['Computation locator should not have run.']];
                    },
                    static fn(int $companyId, int $accountingPeriodId, int $ctPeriodId, array $declaration): array => [
                        'ok' => false,
                        'errors' => ['Injected builder rejected an incomplete frozen filing model.'],
                    ]
                );
                $failedBuild = $builderFailure->prepareForSubmission(996001, 996003, 'TIL', []);
                $harness->assertSame(false, (bool)($failedBuild['ok'] ?? true));
                $harness->assertSame('The CT600 filing body is not ready.', (string)($failedBuild['errors'][0] ?? ''));
                $harness->assertTrue(str_contains(
                    implode(' ', (array)($failedBuild['errors'] ?? [])),
                    'Injected builder rejected an incomplete frozen filing model.'
                ));
                $harness->assertSame(0, $locatorCalls);

                $accountsCalls = 0;
                $computationCalls = 0;
                $artifactFailure = new \eel_accounts\Service\HmrcSubmissionPackageService(
                    static function (int $companyId, int $accountingPeriodId) use (&$accountsCalls): array {
                        $accountsCalls++;
                        return ['ok' => false, 'errors' => ['Injected accounts iXBRL is stale.']];
                    },
                    static function (int $companyId, int $ctPeriodId) use (&$computationCalls): array {
                        $computationCalls++;
                        return ['ok' => false, 'errors' => ['Injected computations iXBRL is stale.']];
                    },
                    static fn(int $companyId, int $accountingPeriodId, int $ctPeriodId, array $declaration): array => [
                        'ok' => true,
                        'filing_body_xml' => '<unused/>',
                        'return_model' => ['filing_model' => ['model' => []]],
                    ]
                );
                $failedArtifacts = $artifactFailure->prepareForSubmission(996001, 996003, 'TIL', []);
                $errors = implode(' ', (array)($failedArtifacts['errors'] ?? []));
                $harness->assertSame(false, (bool)($failedArtifacts['ok'] ?? true));
                $harness->assertSame('The filing iXBRL artifacts are not ready.', (string)($failedArtifacts['errors'][0] ?? ''));
                $harness->assertTrue(str_contains($errors, 'Injected accounts iXBRL is stale.'));
                $harness->assertTrue(str_contains($errors, 'Injected computations iXBRL is stale.'));
                $harness->assertSame(1, $accountsCalls);
                $harness->assertSame(1, $computationCalls);
            }
        );

        $harness->check(
            \eel_accounts\Service\HmrcSubmissionPackageService::class,
            'requires exact approved accounts and CT-period basis hashes',
            static function () use ($harness, $service): void {
                $accountsPath = tempnam(test_tmp_directory(), 'ct600-accounts-');
                $computationPath = tempnam(test_tmp_directory(), 'ct600-computation-');
                if (!is_string($accountsPath) || !is_string($computationPath)) {
                    throw new RuntimeException('Unable to create package-test iXBRL fixtures.');
                }
                try {
                    file_put_contents(
                        $accountsPath,
                        hmrcPackageTestIxbrl('2024-01-01', '2024-12-31', false),
                        LOCK_EX
                    );
                    file_put_contents(
                        $computationPath,
                        hmrcPackageTestIxbrl('2024-01-01', '2024-12-31', true),
                        LOCK_EX
                    );

                    $accountsBasis = str_repeat('a', 64);
                    $ctBasis = str_repeat('b', 64);
                    $accounts = ['path' => $accountsPath, 'basis_hash' => $accountsBasis];
                    $computation = [
                        'path' => $computationPath,
                        'basis_hash' => $ctBasis,
                        'ct_period_id' => 996003,
                    ];
                    $method = new ReflectionMethod($service, 'crossDocumentChecks');
                    $method->setAccessible(true);

                    $matching = $method->invoke(
                        $service,
                        hmrcPackageTestFrozenModel($accountsBasis),
                        $accounts,
                        $computation,
                        $ctBasis
                    );
                    $harness->assertSame(true, (bool)($matching['ok'] ?? false));
                    $harness->assertSame([], (array)($matching['errors'] ?? []));

                    $wrongAccounts = $accounts;
                    $wrongAccounts['basis_hash'] = str_repeat('c', 64);
                    $accountsMismatch = $method->invoke(
                        $service,
                        hmrcPackageTestFrozenModel($accountsBasis),
                        $wrongAccounts,
                        $computation,
                        $ctBasis
                    );
                    $harness->assertSame(false, (bool)($accountsMismatch['ok'] ?? true));
                    $harness->assertSame(
                        ['The accounts iXBRL does not belong to the approved accounts-report basis.'],
                        (array)($accountsMismatch['errors'] ?? [])
                    );

                    $computationMismatch = $method->invoke(
                        $service,
                        hmrcPackageTestFrozenModel($accountsBasis),
                        $accounts,
                        $computation,
                        str_repeat('d', 64)
                    );
                    $harness->assertSame(false, (bool)($computationMismatch['ok'] ?? true));
                    $harness->assertSame(
                        ['The computations iXBRL does not belong to the approved CT-period filing basis.'],
                        (array)($computationMismatch['errors'] ?? [])
                    );
                } finally {
                    @unlink($accountsPath);
                    @unlink($computationPath);
                }
            }
        );

        $harness->check(
            \eel_accounts\Service\HmrcSubmissionPackageService::class,
            'canonicalises Unicode source manifests identically at packaging and persistence boundaries',
            static function () use ($harness, $service): void {
                $left = [
                    'company' => 'Elstoné & Sons – 株式会社',
                    'nested' => ['currency' => '£', 'note' => 'mañana'],
                    'artifacts' => [
                        ['filename' => 'computation-β.xhtml', 'sha256' => str_repeat('b', 64)],
                        ['filename' => 'accounts-é.xhtml', 'sha256' => str_repeat('a', 64)],
                    ],
                ];
                $right = [
                    'artifacts' => [
                        ['sha256' => str_repeat('b', 64), 'filename' => 'computation-β.xhtml'],
                        ['sha256' => str_repeat('a', 64), 'filename' => 'accounts-é.xhtml'],
                    ],
                    'nested' => ['note' => 'mañana', 'currency' => '£'],
                    'company' => 'Elstoné & Sons – 株式会社',
                ];

                $packageCanonical = new ReflectionMethod($service, 'canonicalJson');
                $packageCanonical->setAccessible(true);
                $packageJson = (string)$packageCanonical->invoke($service, $left);
                $reorderedJson = (string)$packageCanonical->invoke($service, $right);

                $submissionReflection = new ReflectionClass(
                    \eel_accounts\Service\HmrcCorporationTaxSubmissionService::class
                );
                $submissionService = $submissionReflection->newInstanceWithoutConstructor();
                $submissionCanonical = $submissionReflection->getMethod('canonicalJson');
                $submissionCanonical->setAccessible(true);
                $persistenceJson = (string)$submissionCanonical->invoke($submissionService, $right);

                $harness->assertSame($packageJson, $reorderedJson);
                $harness->assertSame($packageJson, $persistenceJson);
                $harness->assertSame(hash('sha256', $packageJson), hash('sha256', $persistenceJson));
                $harness->assertTrue(str_contains($packageJson, 'Elstoné'));
                $harness->assertTrue(str_contains($packageJson, '株式会社'));
                $harness->assertFalse(str_contains($packageJson, '\\u00e9'));
            }
        );

        $harness->check(
            \eel_accounts\Service\HmrcSubmissionPackageService::class,
            'assembles the real accounts and computation iXBRL attachments',
            static function () use ($harness, $service): void {
                $accountsPath = tempnam(test_tmp_directory(), 'ct600-accounts-');
                $computationPath = tempnam(test_tmp_directory(), 'ct600-computation-');
                if (!is_string($accountsPath) || !is_string($computationPath)) {
                    throw new RuntimeException('Unable to create package attachment fixtures.');
                }
                $accountsBytes = hmrcPackageTestIxbrl('2024-01-01', '2024-12-31', false);
                $computationBytes = hmrcPackageTestIxbrl('2024-01-01', '2024-12-31', true);
                file_put_contents($accountsPath, $accountsBytes);
                file_put_contents($computationPath, $computationBytes);

                try {
                    $method = new ReflectionMethod($service, 'attachIxbrl');
                    $method->setAccessible(true);
                    $xml = (string)$method->invoke(
                        $service,
                        '<IRenvelope xmlns="' . \eel_accounts\Service\Ct600BuilderService::CT_NAMESPACE
                            . '"><CompanyTaxReturn/></IRenvelope>',
                        ['path' => $accountsPath, 'filename' => 'accounts.xhtml'],
                        ['path' => $computationPath, 'filename' => 'computation.xhtml']
                    );
                    $document = new DOMDocument();
                    $harness->assertSame(true, $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS));
                    $xpath = new DOMXPath($document);
                    $xpath->registerNamespace('ct', \eel_accounts\Service\Ct600BuilderService::CT_NAMESPACE);
                    $root = '/ct:IRenvelope/ct:CompanyTaxReturn/ct:AttachedFiles/ct:XBRLsubmission';
                    $harness->assertSame(1, $xpath->query($root . '/ct:Accounts/ct:Instance/ct:EncodedInlineXBRLDocument')?->length);
                    $harness->assertSame(1, $xpath->query($root . '/ct:Computation/ct:Instance/ct:EncodedInlineXBRLDocument')?->length);
                    $harness->assertSame(
                        $accountsBytes,
                        base64_decode((string)$xpath->evaluate(
                            'string(' . $root . '/ct:Accounts/ct:Instance/ct:EncodedInlineXBRLDocument)'
                        ), true)
                    );
                    $harness->assertSame(
                        $computationBytes,
                        base64_decode((string)$xpath->evaluate(
                            'string(' . $root . '/ct:Computation/ct:Instance/ct:EncodedInlineXBRLDocument)'
                        ), true)
                    );
                    $harness->assertSame(
                        'accounts.xhtml',
                        (string)$xpath->evaluate(
                            'string(' . $root . '/ct:Accounts/ct:Instance/ct:EncodedInlineXBRLDocument/@Filename)'
                        )
                    );
                    $harness->assertSame(
                        'computation.xhtml',
                        (string)$xpath->evaluate(
                            'string(' . $root . '/ct:Computation/ct:Instance/ct:EncodedInlineXBRLDocument/@Filename)'
                        )
                    );
                } finally {
                    @unlink($accountsPath);
                    @unlink($computationPath);
                }
            }
        );
    }
);
