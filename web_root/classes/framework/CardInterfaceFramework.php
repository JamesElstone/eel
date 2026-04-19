<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

interface CardInterfaceFramework
{
    public function key(): string;

    public function services(): array;

    public function invalidationFacts(): array;

    public function handleError(string $serviceKey, array $error, array $context): string;

    public function render(array $context): string;
}
