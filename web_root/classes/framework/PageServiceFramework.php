<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class PageServiceFramework
{
    public function __construct(
        private readonly AppService $appServices
    ) {
    }

    public function get(string $serviceClass): object
    {
        $serviceClass = ltrim(trim($serviceClass), '\\');

        if ($serviceClass === '') {
            throw new InvalidArgumentException('Requested page service class must not be empty.');
        }

        try {
            return $this->appServices->get($serviceClass);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'Page-defined service [' . $serviceClass . '] is unavailable; service ' . $serviceClass . ' was not resolved.',
                0,
                $exception
            );
        }
    }

    public function has(string $serviceClass): bool
    {
        $serviceClass = ltrim(trim($serviceClass), '\\');

        if ($serviceClass === '') {
            return false;
        }

        try {
            $this->appServices->get($serviceClass);
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}