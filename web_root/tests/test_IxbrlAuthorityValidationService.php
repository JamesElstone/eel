<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlAuthorityValidationService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\IxbrlAuthorityValidationService $service
    ): void {
        $catalog = new \eel_accounts\Service\IxbrlAuthorityProfileService();
        $document = static function (
            string $prefix,
            string $registry,
            string $format = 'ixt:numdotdecimal',
            string $extraNamespace = ''
        ): string {
            return $prefix
                . '<html xmlns="http://www.w3.org/1999/xhtml"'
                . ' xmlns:ix="http://www.xbrl.org/2013/inlineXBRL"'
                . ' xmlns:ixt="' . $registry . '"' . $extraNamespace . '>'
                . '<head><title>Test</title></head><body>'
                . '<ix:nonFraction name="core:TurnoverRevenue" contextRef="c1" unitRef="GBP" decimals="0"'
                . ' format="' . $format . '">1,234</ix:nonFraction>'
                . '</body></html>';
        };
        $computationDocument = static function (
            string $documentPrefix,
            string $registry,
            string $taxonomyPrefix = 'ctc',
            string $taxonomyNamespace = 'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01',
            string $partnerValue = 'false',
            array $omittedFacts = [],
            array $blankFacts = [],
            array $nilFacts = []
        ): string {
            $facts = [
                'CompanyName' => 'Example Trading Limited',
                'TaxReference' => '1234567890',
                'PeriodOfAccountStartDate' => '2022-09-05',
                'PeriodOfAccountEndDate' => '2023-09-30',
                'StartOfPeriodCoveredByReturn' => '2023-09-05',
                'EndOfPeriodCoveredByReturn' => '2023-09-30',
                'CompanyIsAPartnerInAFirm' => $partnerValue,
            ];
            $factMarkup = '';
            foreach ($facts as $localName => $value) {
                if (in_array($localName, $omittedFacts, true)) {
                    continue;
                }
                $nil = in_array($localName, $nilFacts, true);
                $renderedValue = $nil || in_array($localName, $blankFacts, true) ? '' : $value;
                $factMarkup .= '<ix:nonNumeric name="' . $taxonomyPrefix . ':' . $localName
                    . '" contextRef="c1"' . ($nil ? ' xsi:nil="true"' : '') . '>'
                    . htmlspecialchars($renderedValue, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                    . '</ix:nonNumeric>';
            }

            return $documentPrefix
                . '<html xmlns="http://www.w3.org/1999/xhtml"'
                . ' xmlns:ix="http://www.xbrl.org/2013/inlineXBRL"'
                . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
                . ' xmlns:ixt="' . $registry . '"'
                . ' xmlns:' . $taxonomyPrefix . '="' . $taxonomyNamespace . '">'
                . '<head><title>HMRC computation</title></head><body>'
                . $factMarkup
                . '<ix:nonFraction name="' . $taxonomyPrefix . ':TurnoverRevenue" contextRef="c1"'
                . ' unitRef="GBP" decimals="0" format="ixt:numdotdecimal">1,234</ix:nonFraction>'
                . '</body></html>';
        };
        $codes = static fn(array $result): array => array_column($result['errors'], 'code');

        $harness->check($service::class, 'accepts each authority registry and exact document policy', static function () use ($harness, $service, $catalog, $document, $computationDocument): void {
            $hmrcPrefix = $catalog->profile($catalog::HMRC_CT_ACCOUNTS)->documentPolicy()['document_prefix'];
            $companiesHousePrefix = $catalog->profile($catalog::COMPANIES_HOUSE_ACCOUNTS)->documentPolicy()['document_prefix'];

            $hmrc = $service->validate(
                $document($hmrcPrefix, $catalog::TRANSFORMATION_REGISTRY_2011),
                $catalog::HMRC_CT_ACCOUNTS
            );
            $computation = $service->validate(
                $computationDocument($hmrcPrefix, $catalog::TRANSFORMATION_REGISTRY_2011),
                $catalog::HMRC_CT_COMPUTATION
            );
            $companiesHouse = $service->validate(
                $document($companiesHousePrefix, $catalog::TRANSFORMATION_REGISTRY_2015, 'ixt:zerodash'),
                $catalog::COMPANIES_HOUSE_ACCOUNTS
            );

            $harness->assertTrue($hmrc['ok']);
            $harness->assertTrue($computation['ok']);
            $harness->assertTrue($companiesHouse['ok']);
            $harness->assertSame(64, strlen($hmrc['profile_fingerprint']));
            $harness->assertSame($catalog::HMRC_CT_ACCOUNTS, $hmrc['profile']['key']);
        });

        $harness->check($service::class, 'accepts mandatory computation facts by expanded QName for the supported taxonomy years', static function () use ($harness, $service, $catalog, $computationDocument): void {
            $prefix = $catalog->profile($catalog::HMRC_CT_COMPUTATION)->documentPolicy()['document_prefix'];
            $taxonomyNamespaces = [
                'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01',
                'http://www.hmrc.gov.uk/schemas/ct/comp/2025-01-01',
            ];
            foreach ($taxonomyNamespaces as $index => $taxonomyNamespace) {
                $result = $service->validate(
                    $computationDocument(
                        $prefix,
                        $catalog::TRANSFORMATION_REGISTRY_2011,
                        $index === 0 ? 'arbitraryPrefix' : 'anotherPrefix',
                        $taxonomyNamespace
                    ),
                    $catalog::HMRC_CT_COMPUTATION
                );
                $harness->assertTrue($result['ok']);
            }
        });

        $harness->check($service::class, 'reports each missing mandatory HMRC computation fact', static function () use ($harness, $service, $catalog, $computationDocument, $codes): void {
            $prefix = $catalog->profile($catalog::HMRC_CT_COMPUTATION)->documentPolicy()['document_prefix'];
            $requiredFacts = [
                'CompanyName',
                'TaxReference',
                'PeriodOfAccountStartDate',
                'PeriodOfAccountEndDate',
                'StartOfPeriodCoveredByReturn',
                'EndOfPeriodCoveredByReturn',
                'CompanyIsAPartnerInAFirm',
            ];
            foreach ($requiredFacts as $requiredFact) {
                $result = $service->validate(
                    $computationDocument(
                        $prefix,
                        $catalog::TRANSFORMATION_REGISTRY_2011,
                        'ctFacts',
                        'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01',
                        'false',
                        [$requiredFact]
                    ),
                    $catalog::HMRC_CT_COMPUTATION
                );
                $harness->assertFalse($result['ok']);
                $harness->assertTrue(in_array('ixbrl.fact.required_missing', $codes($result), true));
                $missing = array_values(array_filter(
                    $result['errors'],
                    static fn(array $error): bool => $error['code'] === 'ixbrl.fact.required_missing'
                ));
                $harness->assertSame($requiredFact, $missing[0]['details']['fact_local_name'] ?? null);
            }
        });

        $harness->check($service::class, 'rejects required computation facts in an unrelated namespace', static function () use ($harness, $service, $catalog, $computationDocument, $codes): void {
            $prefix = $catalog->profile($catalog::HMRC_CT_COMPUTATION)->documentPolicy()['document_prefix'];
            $result = $service->validate(
                $computationDocument(
                    $prefix,
                    $catalog::TRANSFORMATION_REGISTRY_2011,
                    'lookalike',
                    'https://example.test/not-hmrc-computation'
                ),
                $catalog::HMRC_CT_COMPUTATION
            );

            $harness->assertFalse($result['ok']);
            $harness->assertTrue(in_array('ixbrl.fact.namespace_not_allowed', $codes($result), true));
            $harness->assertSame(
                'CompanyName',
                $result['errors'][0]['details']['fact_local_name'] ?? null
            );
        });

        $harness->check($service::class, 'requires all mandatory facts to use one supported computation namespace', static function () use ($harness, $service, $catalog, $computationDocument, $codes): void {
            $prefix = $catalog->profile($catalog::HMRC_CT_COMPUTATION)->documentPolicy()['document_prefix'];
            $document2024 = $computationDocument(
                $prefix,
                $catalog::TRANSFORMATION_REGISTRY_2011,
                'ct2024',
                'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01',
                'false',
                ['TaxReference']
            );
            $mixed = str_replace(
                '</body>',
                '<ix:nonNumeric xmlns:ct2025="http://www.hmrc.gov.uk/schemas/ct/comp/2025-01-01"'
                    . ' name="ct2025:TaxReference" contextRef="c1">1234567890</ix:nonNumeric></body>',
                $document2024
            );
            $result = $service->validate($mixed, $catalog::HMRC_CT_COMPUTATION);

            $harness->assertFalse($result['ok']);
            $harness->assertTrue(in_array('ixbrl.fact.namespace_mismatch', $codes($result), true));

            $ambiguous = str_replace(
                '</body>',
                '<ix:nonNumeric xmlns:ct2025="http://www.hmrc.gov.uk/schemas/ct/comp/2025-01-01"'
                    . ' name="ct2025:CompanyName" contextRef="c1">Example Trading Limited</ix:nonNumeric></body>',
                $computationDocument(
                    $prefix,
                    $catalog::TRANSFORMATION_REGISTRY_2011,
                    'ct2024',
                    'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01'
                )
            );
            $ambiguousResult = $service->validate($ambiguous, $catalog::HMRC_CT_COMPUTATION);
            $harness->assertFalse($ambiguousResult['ok']);
            $harness->assertTrue(in_array('ixbrl.fact.namespace_ambiguous', $codes($ambiguousResult), true));
        });

        $harness->check($service::class, 'rejects blank or nil mandatory computation facts', static function () use ($harness, $service, $catalog, $computationDocument, $codes): void {
            $prefix = $catalog->profile($catalog::HMRC_CT_COMPUTATION)->documentPolicy()['document_prefix'];
            $requiredFacts = [
                'CompanyName',
                'TaxReference',
                'PeriodOfAccountStartDate',
                'PeriodOfAccountEndDate',
                'StartOfPeriodCoveredByReturn',
                'EndOfPeriodCoveredByReturn',
                'CompanyIsAPartnerInAFirm',
            ];
            foreach ($requiredFacts as $requiredFact) {
                $blank = $service->validate(
                    $computationDocument(
                        $prefix,
                        $catalog::TRANSFORMATION_REGISTRY_2011,
                        'ctc',
                        'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01',
                        'false',
                        [],
                        [$requiredFact]
                    ),
                    $catalog::HMRC_CT_COMPUTATION
                );
                $harness->assertFalse($blank['ok']);
                $harness->assertTrue(in_array('ixbrl.fact.required_value_missing', $codes($blank), true));
            }

            $nil = $service->validate(
                $computationDocument(
                    $prefix,
                    $catalog::TRANSFORMATION_REGISTRY_2011,
                    'ctc',
                    'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01',
                    'false',
                    [],
                    [],
                    ['CompanyName']
                ),
                $catalog::HMRC_CT_COMPUTATION
            );
            $harness->assertFalse($nil['ok']);
            $harness->assertTrue(in_array('ixbrl.fact.required_value_missing', $codes($nil), true));
        });

        $harness->check($service::class, 'requires an explicit false partnership fact for the supported computation profile', static function () use ($harness, $service, $catalog, $computationDocument, $codes): void {
            $prefix = $catalog->profile($catalog::HMRC_CT_COMPUTATION)->documentPolicy()['document_prefix'];
            foreach (['true', '0'] as $partnerValue) {
                $result = $service->validate(
                    $computationDocument(
                        $prefix,
                        $catalog::TRANSFORMATION_REGISTRY_2011,
                        'ctc',
                        'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01',
                        $partnerValue
                    ),
                    $catalog::HMRC_CT_COMPUTATION
                );
                $harness->assertFalse($result['ok']);
                $harness->assertTrue(in_array('ixbrl.fact.lexical_value_not_allowed', $codes($result), true));
            }
        });

        $harness->check($service::class, 'rejects the 2015 registry for HMRC with fact locations', static function () use ($harness, $service, $catalog, $document): void {
            $prefix = $catalog->profile($catalog::HMRC_CT_ACCOUNTS)->documentPolicy()['document_prefix'];
            $result = $service->validate(
                $document($prefix, $catalog::TRANSFORMATION_REGISTRY_2015),
                $catalog::HMRC_CT_ACCOUNTS
            );

            $harness->assertFalse($result['ok']);
            $harness->assertSame('ixbrl.format.namespace_not_allowed', $result['errors'][0]['code']);
            $harness->assertTrue(str_contains($result['errors'][0]['location'], 'nonFraction'));
            $harness->assertSame(
                $catalog::TRANSFORMATION_REGISTRY_2011,
                $result['errors'][0]['details']['expected_namespace']
            );
        });

        $harness->check($service::class, 'rejects unsupported, unresolved and malformed format QNames', static function () use ($harness, $service, $catalog, $document, $codes): void {
            $prefix = $catalog->profile($catalog::HMRC_CT_ACCOUNTS)->documentPolicy()['document_prefix'];
            $unsupported = $service->validate(
                $document($prefix, $catalog::TRANSFORMATION_REGISTRY_2011, 'ixt:notapproved'),
                $catalog::HMRC_CT_ACCOUNTS
            );
            $unresolved = $service->validate(
                $document($prefix, $catalog::TRANSFORMATION_REGISTRY_2011, 'missing:numdotdecimal'),
                $catalog::HMRC_CT_ACCOUNTS
            );
            $malformed = $service->validate(
                $document($prefix, $catalog::TRANSFORMATION_REGISTRY_2011, 'numdotdecimal'),
                $catalog::HMRC_CT_ACCOUNTS
            );

            $harness->assertTrue(in_array('ixbrl.format.transform_not_allowed', $codes($unsupported), true));
            $harness->assertTrue(in_array('ixbrl.format.unresolved_prefix', $codes($unresolved), true));
            $harness->assertTrue(in_array('ixbrl.format.invalid_qname', $codes($malformed), true));
        });

        $harness->check($service::class, 'enforces exact authority declarations and safe XML bytes', static function () use ($harness, $service, $catalog, $document, $codes): void {
            $hmrcPrefix = $catalog->profile($catalog::HMRC_CT_ACCOUNTS)->documentPolicy()['document_prefix'];
            $withoutDeclaration = $service->validate(
                $document('', $catalog::TRANSFORMATION_REGISTRY_2011),
                $catalog::HMRC_CT_ACCOUNTS
            );
            $withBom = $service->validate(
                "\xEF\xBB\xBF" . $document($hmrcPrefix, $catalog::TRANSFORMATION_REGISTRY_2011),
                $catalog::HMRC_CT_ACCOUNTS
            );
            $withDoctype = $service->validate(
                $hmrcPrefix . '<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml"/>',
                $catalog::HMRC_CT_ACCOUNTS
            );

            $harness->assertTrue(in_array('ixbrl.document.xml_declaration_mismatch', $codes($withoutDeclaration), true));
            $harness->assertTrue(in_array('ixbrl.document.bom_forbidden', $codes($withBom), true));
            $harness->assertTrue(in_array('ixbrl.document.doctype_forbidden', $codes($withDoctype), true));
        });

        $harness->check($service::class, 'reports malformed XML and root mismatches without losing the profile fingerprint', static function () use ($harness, $service, $catalog, $codes): void {
            $prefix = $catalog->profile($catalog::COMPANIES_HOUSE_ACCOUNTS)->documentPolicy()['document_prefix'];
            $malformed = $service->validate($prefix . '<html>', $catalog::COMPANIES_HOUSE_ACCOUNTS);
            $wrongRoot = $service->validate(
                $prefix . '<accounts xmlns="http://www.w3.org/1999/xhtml"/>',
                $catalog::COMPANIES_HOUSE_ACCOUNTS
            );

            $harness->assertTrue(in_array('ixbrl.document.not_well_formed', $codes($malformed), true));
            $harness->assertTrue(in_array('ixbrl.document.root_mismatch', $codes($wrongRoot), true));
            $harness->assertSame(64, strlen($wrongRoot['profile_fingerprint']));
            $harness->assertThrows(
                static fn() => $service->assertValid($prefix . '<html>', $catalog::COMPANIES_HOUSE_ACCOUNTS),
                InvalidArgumentException::class
            );
        });
    }
);
