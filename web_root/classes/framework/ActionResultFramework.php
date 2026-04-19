<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ActionResultFramework
{
    public function __construct(
        private readonly bool $success = true,
        private readonly array $changedFacts = [],
        private readonly array $flashMessages = [],
        private readonly array $query = [],
    ) {
    }

    public static function none(): self
    {
        return new self(true, [], [], []);
    }

    public static function success(array $changedFacts = [], array $flashMessages = [], array $query = []): self
    {
        return new self(true, $changedFacts, $flashMessages, $query);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function changedFacts(): array
    {
        return array_values(array_unique(array_filter(array_map('strval', $this->changedFacts))));
    }

    public function flashMessages(): array
    {
        return $this->flashMessages;
    }

    public function query(): array
    {
        return $this->query;
    }
}
