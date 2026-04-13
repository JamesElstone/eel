<?php
declare(strict_types=1);

interface CardInterfaceFramework
{
    public function key(): string;

    public function services(): array;

    public function invalidationFacts(): array;

    public function handleError(string $serviceKey, array $error, array $context): string;

    public function render(array $context): string;
}
