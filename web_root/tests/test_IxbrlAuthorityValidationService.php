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
        $codes = static fn(array $result): array => array_column($result['errors'], 'code');

        $harness->check($service::class, 'accepts each authority registry and exact document policy', static function () use ($harness, $service, $catalog, $document): void {
            $hmrcPrefix = $catalog->profile($catalog::HMRC_CT_ACCOUNTS)->documentPolicy()['document_prefix'];
            $companiesHousePrefix = $catalog->profile($catalog::COMPANIES_HOUSE_ACCOUNTS)->documentPolicy()['document_prefix'];

            $hmrc = $service->validate(
                $document($hmrcPrefix, $catalog::TRANSFORMATION_REGISTRY_2011),
                $catalog::HMRC_CT_ACCOUNTS
            );
            $computation = $service->validate(
                $document($hmrcPrefix, $catalog::TRANSFORMATION_REGISTRY_2011, 'ixt:datedaymonthyearen'),
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
