<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'af' . DIRECTORY_SEPARATOR . 'antifraud.php';

$tests = [
    'defines antifraud header prefix constant' => static function (): void {
        if (!defined('AF_HEADER_PREFIX') || AF_HEADER_PREFIX !== AntiFraudService::HEADER_PREFIX) {
            throw new RuntimeException('AF_HEADER_PREFIX was not defined from AntiFraudService.');
        }
    },
    'defines antifraud cookie prefix constant' => static function (): void {
        if (!defined('AF_COOKIE_PREFIX') || AF_COOKIE_PREFIX !== AntiFraudService::COOKIE_PREFIX) {
            throw new RuntimeException('AF_COOKIE_PREFIX was not defined from AntiFraudService.');
        }
    },
    'loads antifraud helper functions' => static function (): void {
        if (!function_exists('af_request_value') || !function_exists('af_cookie_suffix_from_field')) {
            throw new RuntimeException('Expected antifraud helper functions were not loaded.');
        }
    },
    'delegates cookie suffix helper to the antifraud service' => static function (): void {
        if (af_cookie_suffix_from_field('Client-Timezone') !== 'client_timezone') {
            throw new RuntimeException('af_cookie_suffix_from_field() did not delegate to AntiFraudService.');
        }
    },
];

foreach ($tests as $description => $callback) {
    $callback();
    test_output_line('antifraud: ' . $description . '.');
}
