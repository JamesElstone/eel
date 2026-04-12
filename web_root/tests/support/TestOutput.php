<?php
declare(strict_types=1);

if (!function_exists('test_output_bootstrap')) {
    function test_output_bootstrap(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
    }
}

if (!function_exists('test_output_line')) {
    function test_output_line(string $message): void
    {
        test_output_bootstrap();

        if (defined('STDOUT')) {
            fwrite(STDOUT, $message . PHP_EOL);
            return;
        }

        echo $message . "\n";
    }
}

if (!function_exists('test_output_failure_line')) {
    function test_output_failure_line(string $message): void
    {
        test_output_line('!!! ' . $message);
    }
}
