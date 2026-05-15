<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

interface CardInterfaceFramework
{
    public function key(): string;

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array;

    public function title(): string;

    public function contextTitle(array $context): string;

    public function helper(array $context): string|array;

    public function services(): array;

    public function tables(array $context): array;

    public function invalidationFacts(): array;

    public function refreshIntervalMs(array $context): ?int;

    public function handleError(string $serviceKey, array $error, array $context): string;

    public function render(array $context): string;
}
