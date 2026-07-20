<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Support;

/**
 * Explicitly request-scoped memoization for expensive, immutable read models.
 *
 * The cache is inactive for direct service calls (including unit tests). The
 * accounting site-context provider starts it once for the current request,
 * after any submitted action has completed and before page/card reads begin.
 */
final class RequestCache
{
    private static ?\WeakReference $request = null;
    private static ?\WeakReference $scope = null;

    /** @var array{session_id: string, values: array<string, mixed>}|null */
    private static ?array $sessionBoundary = null;

    /** @var array<string, mixed> */
    private static array $values = [];

    public static function beginFor(object $request): object
    {
        $scope = self::$scope?->get();
        if (self::$request?->get() === $request && $scope !== null) {
            return $scope;
        }

        self::$request = \WeakReference::create($request);
        self::$values = [];
        self::$sessionBoundary = null;

        $scope = new class {
            public function __destruct()
            {
                RequestCache::endFor($this);
            }
        };
        self::$scope = \WeakReference::create($scope);

        return $scope;
    }

    public static function isActive(): bool
    {
        if (self::$request === null || self::$scope === null) {
            return false;
        }

        if (self::$request->get() === null || self::$scope->get() === null) {
            self::reset();
            return false;
        }

        if (self::$sessionBoundary !== null && !self::matchesSessionBoundary()) {
            self::reset();
            return false;
        }

        return true;
    }

    /** @param list<string> $keys */
    public static function bindToSessionKeys(array $keys): void
    {
        if (!self::isActive()) {
            return;
        }

        $values = [];
        foreach ($keys as $key) {
            $key = trim($key);
            if ($key !== '') {
                $values[$key] = $_SESSION[$key] ?? null;
            }
        }

        self::$sessionBoundary = [
            'session_id' => session_id(),
            'values' => $values,
        ];
    }

    public static function endFor(object $scope): void
    {
        if (self::$scope?->get() === $scope) {
            self::reset();
        }
    }

    public static function key(mixed ...$parts): string
    {
        return hash('sha256', serialize($parts));
    }

    public static function has(string $namespace, string $key): bool
    {
        return self::isActive() && array_key_exists(self::cacheKey($namespace, $key), self::$values);
    }

    public static function get(string $namespace, string $key): mixed
    {
        if (!self::isActive()) {
            return null;
        }

        return self::$values[self::cacheKey($namespace, $key)] ?? null;
    }

    public static function put(string $namespace, string $key, mixed $value): mixed
    {
        if (self::isActive()) {
            self::$values[self::cacheKey($namespace, $key)] = $value;
        }

        return $value;
    }

    public static function remember(string $namespace, string $key, \Closure $resolver): mixed
    {
        if (!self::isActive()) {
            return $resolver();
        }

        $cacheKey = self::cacheKey($namespace, $key);
        if (!array_key_exists($cacheKey, self::$values)) {
            self::$values[$cacheKey] = $resolver();
        }

        return self::$values[$cacheKey];
    }

    public static function forget(string $namespace, string $key): void
    {
        unset(self::$values[self::cacheKey($namespace, $key)]);
    }

    public static function forgetNamespace(string $namespace): void
    {
        $prefix = trim($namespace) . "\x1f";
        foreach (array_keys(self::$values) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$values[$key]);
            }
        }
    }

    /**
     * Drops memoized read models after a write while preserving the current
     * request boundary. Subsequent reads in the action must see fresh data.
     */
    public static function clear(): void
    {
        self::$values = [];
    }

    public static function reset(): void
    {
        self::$request = null;
        self::$scope = null;
        self::$sessionBoundary = null;
        self::$values = [];
    }

    private static function matchesSessionBoundary(): bool
    {
        if (self::$sessionBoundary === null || self::$sessionBoundary['session_id'] !== session_id()) {
            return self::$sessionBoundary === null;
        }

        foreach (self::$sessionBoundary['values'] as $key => $expected) {
            if (($_SESSION[$key] ?? null) !== $expected) {
                return false;
            }
        }

        return true;
    }

    private static function cacheKey(string $namespace, string $key): string
    {
        return trim($namespace) . "\x1f" . $key;
    }
}
