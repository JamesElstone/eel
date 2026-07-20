<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Support\RequestCache::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(
            \eel_accounts\Support\RequestCache::class,
            'memoizes only inside one explicit request object',
            static function () use ($harness): void {
                \eel_accounts\Support\RequestCache::reset();
                $calls = 0;
                $resolver = static function () use (&$calls): int {
                    $calls++;
                    return $calls;
                };

                $harness->assertSame(1, \eel_accounts\Support\RequestCache::remember('test', 'value', $resolver));
                $harness->assertSame(2, \eel_accounts\Support\RequestCache::remember('test', 'value', $resolver));

                $request = new stdClass();
                $scope = \eel_accounts\Support\RequestCache::beginFor($request);
                $harness->assertSame(3, \eel_accounts\Support\RequestCache::remember('test', 'value', $resolver));
                $harness->assertSame(3, \eel_accounts\Support\RequestCache::remember('test', 'value', $resolver));
                $harness->assertSame(3, $calls);

                $harness->assertSame($scope, \eel_accounts\Support\RequestCache::beginFor($request));
                $harness->assertSame(3, \eel_accounts\Support\RequestCache::get('test', 'value'));

                \eel_accounts\Support\RequestCache::beginFor(new stdClass());
                $harness->assertSame(false, \eel_accounts\Support\RequestCache::has('test', 'value'));
                $harness->assertSame(4, \eel_accounts\Support\RequestCache::remember('test', 'value', $resolver));

                $nextRequest = new stdClass();
                $nextScope = \eel_accounts\Support\RequestCache::beginFor($nextRequest);
                $harness->assertSame(5, \eel_accounts\Support\RequestCache::remember('test', 'value', $resolver));
                $harness->assertSame(5, \eel_accounts\Support\RequestCache::remember('test', 'value', $resolver));
                unset($nextScope);
                $harness->assertSame(false, \eel_accounts\Support\RequestCache::isActive());

                $nextScope = \eel_accounts\Support\RequestCache::beginFor($nextRequest);
                $harness->assertSame(6, \eel_accounts\Support\RequestCache::remember('test', 'value', $resolver));
                unset($nextRequest);
                $harness->assertSame(false, \eel_accounts\Support\RequestCache::isActive());
                $harness->assertSame(7, \eel_accounts\Support\RequestCache::remember('test', 'value', $resolver));

                $writeRequest = new stdClass();
                $writeScope = \eel_accounts\Support\RequestCache::beginFor($writeRequest);
                $harness->assertSame(8, \eel_accounts\Support\RequestCache::remember('test', 'value', $resolver));
                \eel_accounts\Support\RequestCache::clear();
                $harness->assertSame(true, \eel_accounts\Support\RequestCache::isActive());
                $harness->assertSame(9, \eel_accounts\Support\RequestCache::remember('test', 'value', $resolver));
                unset($writeScope, $writeRequest);
                gc_collect_cycles();

                $sessionRequest = new stdClass();
                $sessionScope = \eel_accounts\Support\RequestCache::beginFor($sessionRequest);
                $_SESSION['request_cache_test_company'] = 49;
                \eel_accounts\Support\RequestCache::bindToSessionKeys(['request_cache_test_company']);
                $harness->assertSame(10, \eel_accounts\Support\RequestCache::remember('test', 'value', $resolver));
                $_SESSION['request_cache_test_company'] = 50;
                $harness->assertSame(false, \eel_accounts\Support\RequestCache::isActive());
                $harness->assertSame(11, \eel_accounts\Support\RequestCache::remember('test', 'value', $resolver));
                unset($_SESSION['request_cache_test_company'], $sessionScope, $sessionRequest);

                \eel_accounts\Support\RequestCache::reset();
            }
        );

        $harness->check(
            \eel_accounts\Support\RequestCache::class,
            'supports null values, invalidation, and stable compound keys',
                static function () use ($harness): void {
                \eel_accounts\Support\RequestCache::reset();
                $request = new stdClass();
                $scope = \eel_accounts\Support\RequestCache::beginFor($request);

                \eel_accounts\Support\RequestCache::put('nullable', 'value', null);
                $harness->assertSame(true, \eel_accounts\Support\RequestCache::has('nullable', 'value'));
                $harness->assertSame(null, \eel_accounts\Support\RequestCache::get('nullable', 'value'));
                \eel_accounts\Support\RequestCache::forget('nullable', 'value');
                $harness->assertSame(false, \eel_accounts\Support\RequestCache::has('nullable', 'value'));

                $first = \eel_accounts\Support\RequestCache::key(49, 82, ['period_end' => '2026-09-30']);
                $second = \eel_accounts\Support\RequestCache::key(49, 82, ['period_end' => '2026-09-30']);
                $different = \eel_accounts\Support\RequestCache::key(49, 81, ['period_end' => '2025-09-30']);
                $harness->assertSame($first, $second);
                $harness->assertSame(false, $first === $different);

                \eel_accounts\Support\RequestCache::reset();
            }
        );
    }
);
