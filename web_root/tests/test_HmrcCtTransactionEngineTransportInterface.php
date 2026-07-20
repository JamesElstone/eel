<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->runInterface(
    \eel_accounts\Client\HmrcCtTransactionEngineTransportInterface::class,
    static function (GeneratedServiceClassTestHarness $h): void {
        $reflection = new ReflectionClass(
            \eel_accounts\Client\HmrcCtTransactionEngineTransportInterface::class
        );
        foreach (['configurationStatus', 'submit', 'poll', 'delete'] as $method) {
            $h->assertTrue($reflection->hasMethod($method));
        }
    }
);
