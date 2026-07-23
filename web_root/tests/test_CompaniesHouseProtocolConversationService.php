<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseProtocolConversationService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\CompaniesHouseProtocolConversationService $service
    ): void {
        $harness->check(
            \eel_accounts\Service\CompaniesHouseProtocolConversationService::class,
            'requires all durable protocol state tables',
            static function () use ($harness, $service): void {
                $harness->assertSame(true, $service->schemaReady());
                foreach ([
                    'companies_house_company_auth_preflights',
                    'companies_house_protocol_exchanges',
                    'companies_house_accounts_status_cycles',
                ] as $table) {
                    $harness->assertSame(true, InterfaceDB::tableExists($table));
                }
            }
        );
    }
);
