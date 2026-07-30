<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseAccountsSchemaValidator::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CompaniesHouseAccountsSchemaValidator $validator): void {
        $harness->check($validator::class, 'rejects an empty schema inventory', static function () use ($harness, $validator): void {
            try { $validator->validateAccountsRequest('<xml/>', []); $harness->assertTrue(false); }
            catch (InvalidArgumentException) { $harness->assertTrue(true); }
        });
    }
);
