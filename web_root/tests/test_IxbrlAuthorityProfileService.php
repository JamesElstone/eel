<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlAuthorityProfileService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\IxbrlAuthorityProfileService $service
    ): void {
        $harness->check($service::class, 'defines distinct versioned profiles for both filing authorities', static function () use ($harness, $service): void {
            $profiles = $service->all();
            $harness->assertCount(3, $profiles);

            $hmrcAccounts = $service->profile($service::HMRC_CT_ACCOUNTS);
            $hmrcComputation = $service->profile($service::HMRC_CT_COMPUTATION);
            $companiesHouse = $service->profile($service::COMPANIES_HOUSE_ACCOUNTS);

            $harness->assertSame($service::TRANSFORMATION_REGISTRY_2011, $hmrcAccounts->transformationNamespace());
            $harness->assertSame($service::TRANSFORMATION_REGISTRY_2011, $hmrcComputation->transformationNamespace());
            $harness->assertSame($service::TRANSFORMATION_REGISTRY_2015, $companiesHouse->transformationNamespace());
            $harness->assertFalse(hash_equals($hmrcAccounts->fingerprint(), $hmrcComputation->fingerprint()));
            $harness->assertFalse(hash_equals($hmrcAccounts->fingerprint(), $companiesHouse->fingerprint()));
        });

        $harness->check($service::class, 'publishes exact authority document prefixes and safety rules', static function () use ($harness, $service): void {
            $hmrc = $service->profile($service::HMRC_CT_ACCOUNTS)->documentPolicy();
            $companiesHouse = $service->profile($service::COMPANIES_HOUSE_ACCOUNTS)->documentPolicy();

            $harness->assertSame('<?xml version="1.0" encoding="UTF-8"?>' . "\n", $hmrc['document_prefix']);
            $harness->assertSame('<?xml version="1.0"?>' . "\n", $companiesHouse['document_prefix']);
            $harness->assertFalse($hmrc['bom_allowed']);
            $harness->assertFalse($hmrc['doctype_allowed']);
            $harness->assertFalse($companiesHouse['bom_allowed']);
            $harness->assertFalse($companiesHouse['doctype_allowed']);
        });

        $harness->check($service::class, 'fails closed for an unknown authority profile', static function () use ($harness, $service): void {
            $harness->assertThrows(
                static fn() => $service->profile('future_unknown_authority'),
                InvalidArgumentException::class
            );
        });
    }
);
