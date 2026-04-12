<?php
declare(strict_types=1);

interface WebCardInterface
{
    public function key(): string;

    public function invalidationFacts(): array;

    public function render(array $context): string;
}
