<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
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
                'allowed_skipped_tests' => 0,
                'unexpected_skipped_tests' => 0,
            ],
            'skip_policy' => [
                'strict' => false,
                'allowed_categories' => [],
            ],
            'classes' => [],
            'messages' => [],
        ];
    }
}

if (!function_exists('test_output_normalize_skip_category')) {
    function test_output_normalize_skip_category(string $category): string
    {
        $normalizedCategory = strtolower($category);

        if ($normalizedCategory === '' || preg_match('/^[a-z0-9_-]+$/D', $normalizedCategory) !== 1) {
            throw new InvalidArgumentException(
                'Invalid skip category "' . $category
                . '". Skip categories may contain only letters, digits, hyphens, and underscores.'
            );
        }

        return $normalizedCategory;
    }
}

if (!function_exists('test_output_configure_skip_policy')) {
    function test_output_configure_skip_policy(bool $strict, array $allowedCategories = []): void
    {
        test_output_bootstrap();

        $normalizedCategories = [];

        foreach ($allowedCategories as $category) {
            if (!is_string($category)) {
                throw new InvalidArgumentException('Skip policy categories must be strings.');
            }

            $normalizedCategory = test_output_normalize_skip_category($category);
            $normalizedCategories[$normalizedCategory] = true;
        }

        $GLOBALS['test_output_state']['skip_policy'] = [
            'strict' => $strict,
            'allowed_categories' => array_keys($normalizedCategories),
        ];
    }
}

if (!function_exists('test_output_parse_skip_policy_arguments')) {
    /**
     * @param array<int, mixed> $arguments
     * @return array{strict: bool, allowed_categories: list<string>}
     */
    function test_output_parse_skip_policy_arguments(array $arguments): array
    {
        $strict = false;
        $allowedCategories = [];

        foreach (array_slice($arguments, 1) as $argument) {
            if (!is_string($argument)) {
                continue;
            }

            if ($argument === '--strict-skips') {
                $strict = true;
                continue;
            }

            if ($argument === '--allow-skip-category') {
                throw new InvalidArgumentException(
                    'The --allow-skip-category option requires a category in the form --allow-skip-category=<category>.'
                );
            }

            if (str_starts_with($argument, '--allow-skip-category=')) {
                $allowedCategories[] = substr($argument, strlen('--allow-skip-category='));
            }
        }

        return [
            'strict' => $strict,
            'allowed_categories' => $allowedCategories,
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

if (!function_exists('test_output_result')) {
    function test_output_result(
        string $className,
        string $description,
        string $result,
        string $diagnostic = '',
        string $skipCategory = 'unclassified'
    ): void {
        test_output_bootstrap();

        if ($result === 'skip') {
            $skipCategory = test_output_normalize_skip_category($skipCategory);
        }

        $suffix = match ($result) {
            'fail' => ' failed.',
            'skip' => ' skipped.',
            default => '.',
        };
        $message = $className . ': ' . $description . $suffix;

        if ($diagnostic !== '') {
            $message .= ' ' . $diagnostic;
        }

        test_output_record_result($className, $description, $result, $message, $diagnostic, $skipCategory);
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
    function test_output_skip_line(string $message, string $category = 'unclassified'): void
    {
        test_output_bootstrap();
        test_output_record_message($message, 'skip', $category);
    }
}

if (!function_exists('test_output_record_message')) {
    function test_output_record_message(
        string $message,
        string $result,
        string $skipCategory = 'unclassified'
    ): void
    {
        test_output_bootstrap();

        if ($result === 'skip') {
            $skipCategory = test_output_normalize_skip_category($skipCategory);
        }

        $pattern = match ($result) {
            'fail' => '/^([^:]+):\s+(.+?) failed\.(?:\s+(.*))?$/s',
            'skip' => '/^([^:]+):\s+(.+?) skipped\.(?:\s+(.*))?$/s',
            default => '/^([^:]+):\s+(.+?)(?:\.)?$/s',
        };

        if (preg_match($pattern, $message, $matches) !== 1) {
            $state = &$GLOBALS['test_output_state'];
            $state['messages'][] = [
                'result' => $result,
                'message' => $message,
            ];

            if ($result === 'fail') {
                $state['summary']['status'] = 'failing';
            }

            return;
        }

        test_output_record_result(
            trim($matches[1]),
            trim($matches[2]),
            $result,
            $message,
            isset($matches[3]) ? trim($matches[3]) : '',
            $skipCategory
        );
    }
}

if (!function_exists('test_output_record_result')) {
    function test_output_record_result(
        string $className,
        string $description,
        string $result,
        string $message,
        string $diagnostic = '',
        string $skipCategory = 'unclassified'
    ): void {
        test_output_bootstrap();

        $state = &$GLOBALS['test_output_state'];
        $state['messages'][] = [
            'result' => $result,
            'message' => $message,
        ];

        if (!isset($state['classes'][$className])) {
            $state['classes'][$className] = [
                'class' => $className,
                'result' => 'pass',
                'tests' => [],
            ];
        }

        $test = [
            'name' => $description,
            'result' => $result,
        ];

        if ($result === 'skip') {
            $test['diagnostic'] = $diagnostic;
            $test['skip_category'] = test_output_normalize_skip_category($skipCategory);
        }

        $state['classes'][$className]['tests'][] = $test;

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
        $skippedTests = 0;
        $allowedSkippedTests = 0;
        $unexpectedSkippedTests = 0;
        $skipPolicy = $state['skip_policy'] ?? [];
        $strictSkips = (bool)($skipPolicy['strict'] ?? false);
        $allowedCategories = array_fill_keys($skipPolicy['allowed_categories'] ?? [], true);

        foreach ($classes as $class) {
            $totalTests += count($class['tests']);

            foreach ($class['tests'] as $test) {
                if (($test['result'] ?? 'pass') === 'fail') {
                    $failedTests++;
                }
                if (($test['result'] ?? 'pass') === 'skip') {
                    $skippedTests++;
                    $skipCategory = (string)($test['skip_category'] ?? 'unclassified');

                    if (isset($allowedCategories[$skipCategory])) {
                        $allowedSkippedTests++;
                    } else {
                        $unexpectedSkippedTests++;
                    }
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
        $state['summary']['skipped_tests'] = $skippedTests;
        $state['summary']['allowed_skipped_tests'] = $allowedSkippedTests;
        $state['summary']['unexpected_skipped_tests'] = $unexpectedSkippedTests;
        $state['summary']['passed_tests'] = $totalTests - $failedTests - $skippedTests;

        $hasFailureMessage = false;
        foreach ($state['messages'] as $message) {
            if (($message['result'] ?? 'pass') === 'fail') {
                $hasFailureMessage = true;
                break;
            }
        }

        $state['summary']['status'] = (
            $failedTests === 0
            && !$hasFailureMessage
            && (!$strictSkips || $unexpectedSkippedTests === 0)
        ) ? 'healthy' : 'failing';
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
