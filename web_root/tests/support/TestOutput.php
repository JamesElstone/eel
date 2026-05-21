<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

if (!function_exists('test_output_bootstrap')) {
    function test_output_bootstrap(): void
    {
        static $bootstrapped = false;

        if ($bootstrapped) {
            return;
        }

        $bootstrapped = true;

        $GLOBALS['test_output_state'] = [
            'started_at' => gmdate('c'),
            'summary' => [
                'status' => 'healthy',
                'total_classes' => 0,
                'passed_classes' => 0,
                'failed_classes' => 0,
                'total_tests' => 0,
                'passed_tests' => 0,
                'failed_tests' => 0,
                'skipped_tests' => 0,
            ],
            'classes' => [],
            'messages' => [],
        ];
    }
}

if (!function_exists('test_output_line')) {
    function test_output_line(string $message): void
    {
        test_output_bootstrap();
        test_output_record_message($message, 'pass');
    }
}

if (!function_exists('test_output_failure_line')) {
    function test_output_failure_line(string $message): void
    {
        test_output_bootstrap();
        test_output_record_message($message, 'fail');
    }
}

if (!function_exists('test_output_skip_line')) {
    function test_output_skip_line(string $message): void
    {
        test_output_bootstrap();
        test_output_record_message($message, 'skip');
    }
}

if (!function_exists('test_output_record_message')) {
    function test_output_record_message(string $message, string $result): void
    {
        test_output_bootstrap();

        $state = &$GLOBALS['test_output_state'];
        $state['messages'][] = [
            'result' => $result,
            'message' => $message,
        ];

        if (preg_match('/^([^:]+):\s+(.+?)(?:\.)?$/', $message, $matches) !== 1) {
            if ($result === 'fail') {
                $state['summary']['status'] = 'failing';
            }

            return;
        }

        $className = trim($matches[1]);
        $description = trim($matches[2]);

        if (!isset($state['classes'][$className])) {
            $state['classes'][$className] = [
                'class' => $className,
                'result' => 'pass',
                'tests' => [],
            ];
        }

        $state['classes'][$className]['tests'][] = [
            'name' => $description,
            'result' => $result,
        ];

        if ($result === 'fail') {
            $state['classes'][$className]['result'] = 'fail';
            $state['summary']['status'] = 'failing';
        }
    }
}

if (!function_exists('test_output_render')) {
    function test_output_render(): void
    {
        test_output_bootstrap();

        $state = &$GLOBALS['test_output_state'];
        $classes = array_values($state['classes']);
        usort(
            $classes,
            static fn(array $left, array $right): int => strcmp((string)$left['class'], (string)$right['class'])
        );

        $totalClasses = count($classes);
        $failedClasses = 0;
        $totalTests = 0;
        $failedTests = 0;

        foreach ($classes as $class) {
            $totalTests += count($class['tests']);

            foreach ($class['tests'] as $test) {
                if (($test['result'] ?? 'pass') === 'fail') {
                    $failedTests++;
                }
                if (($test['result'] ?? 'pass') === 'skip') {
                    $state['summary']['skipped_tests']++;
                }
            }

            if (($class['result'] ?? 'pass') === 'fail') {
                $failedClasses++;
            }
        }

        $state['summary']['total_classes'] = $totalClasses;
        $state['summary']['failed_classes'] = $failedClasses;
        $state['summary']['passed_classes'] = $totalClasses - $failedClasses;
        $state['summary']['total_tests'] = $totalTests;
        $state['summary']['failed_tests'] = $failedTests;
        $state['summary']['passed_tests'] = $totalTests - $failedTests;
        $state['summary']['status'] = $failedTests === 0 && $state['summary']['status'] !== 'failing' ? 'healthy' : 'failing';
        $state['completed_at'] = gmdate('c');

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        $payload = [
            'all' => [
                'result' => $state['summary']['status'] === 'healthy' ? 'pass' : 'fail',
                'health_status' => $state['summary']['status'],
                'summary' => $state['summary'],
                'classes' => $classes,
                'messages' => $state['messages'],
                'started_at' => $state['started_at'],
                'completed_at' => $state['completed_at'],
            ],
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{"all":{"result":"fail","health_status":"failing","summary":{"status":"failing"},"classes":[]}}';
        }

        if (defined('STDOUT')) {
            fwrite(STDOUT, $json . PHP_EOL);
            return;
        }

        echo $json;
    }
}
