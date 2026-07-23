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

        $harness->check(
            \eel_accounts\Service\CompaniesHouseProtocolConversationService::class,
            'creates stable environment-specific preflight binding facts outside api.keys',
            static function () use ($harness): void {
                $path = test_tmp_directory() . DIRECTORY_SEPARATOR . 'companies-house-preflight-' . bin2hex(random_bytes(4)) . '.keys';
                $configPath = AppConfigurationStore::configPath();
                $originalConfig = file_get_contents($configPath);
                if (!is_string($originalConfig)) {
                    throw new RuntimeException('Unable to snapshot test configuration.');
                }
                AppConfigurationStore::set('security_keys.path', $path);
                try {
                    $service = new \eel_accounts\Service\CompaniesHouseProtocolConversationService();
                    $method = new ReflectionMethod($service, 'hmacKey');
                    $method->setAccessible(true);
                    $testKey = (string)$method->invoke($service, 'TEST');
                    $harness->assertSame($testKey, (string)$method->invoke($service, 'TEST'));
                    $liveKey = (string)$method->invoke($service, 'LIVE');
                    $harness->assertSame(false, $testKey === $liveKey);
                    $harness->assertSame(64, strlen($testKey));
                    $harness->assertSame(64, strlen($liveKey));
                } finally {
                    test_write_file_contents_locked($configPath, $originalConfig);
                    AppConfigurationStore::config(true);
                    @unlink($path);
                }
            }
        );
    }
);
