<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Client\HmrcCtTransactionEngineEnvironment::class,
    static function (
        GeneratedServiceClassTestHarness $h,
        \eel_accounts\Client\HmrcCtTransactionEngineEnvironment $unused
    ): void {
        unset($unused);
        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineEnvironment::class,
            'keeps ETS, TIL and LIVE endpoint and class selection closed',
            static function () use ($h): void {
                $test = \eel_accounts\Client\HmrcCtTransactionEngineEnvironment::profile('TEST');
                $til = \eel_accounts\Client\HmrcCtTransactionEngineEnvironment::profile('TIL');
                $live = \eel_accounts\Client\HmrcCtTransactionEngineEnvironment::profile('LIVE');
                $h->assertTrue(str_contains((string)$test['submission_url'], 'test-transaction-engine'));
                $h->assertSame('HMRC-CT-CT600', $test['class']);
                $h->assertSame('1', $test['gateway_test']);
                $h->assertTrue(str_contains((string)$til['submission_url'], 'transaction-engine.tax'));
                $h->assertSame('HMRC-CT-CT600-TIL', $til['class']);
                $h->assertSame('LIVE', $til['credential_environment']);
                $h->assertFalse((bool)$til['statutory']);
                $h->assertTrue((bool)$live['statutory']);
            }
        );
        $h->check(
            \eel_accounts\Client\HmrcCtTransactionEngineEnvironment::class,
            'accepts only the selected environments documented poll endpoint',
            static function () use ($h): void {
                $endpoint = \eel_accounts\Client\HmrcCtTransactionEngineEnvironment::responseEndpoint(
                    'https://transaction-engine.tax.service.gov.uk/poll',
                    'LIVE'
                );
                $h->assertSame('https://transaction-engine.tax.service.gov.uk/poll', $endpoint);
                try {
                    \eel_accounts\Client\HmrcCtTransactionEngineEnvironment::responseEndpoint(
                        'https://transaction-engine.tax.service.gov.uk/poll?target=other',
                        'LIVE'
                    );
                    throw new RuntimeException('Unsafe poll endpoint was accepted.');
                } catch (InvalidArgumentException) {
                    // Expected.
                }
            }
        );
    }
);
