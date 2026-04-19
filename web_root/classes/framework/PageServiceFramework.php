<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class PageServiceFramework
{
    public function __construct(private readonly array $services)
    {
    }

    public function get(string $key): object
    {
        if (!array_key_exists($key, $this->services)) {
            foreach ($this->services as $service) {
                if ($service instanceof $key) {
                    return $service;
                }
            }

            throw new InvalidArgumentException('Page service not provided: ' . $key);
        }

        return $this->services[$key];
    }

    public function all(): array
    {
        return $this->services;
    }
}
