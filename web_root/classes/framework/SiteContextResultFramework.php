<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SiteContextResultFramework
{
    public function __construct(
        private readonly array $context = [],
        private readonly array $selectors = [],
    ) {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    public function context(): array
    {
        return $this->context;
    }

    public function selectors(): array
    {
        return $this->selectors;
    }
}
