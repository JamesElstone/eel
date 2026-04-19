<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

interface PageInterfaceFramework
{
    public function id(): string;

    public function title(): string;

    public function subtitle(): string;

    public function showsTaxYearSelector(): bool;

    public function services(): array;

    public function cards(): array;

    public function handle(RequestFramework $request, PageServiceFramework $services): ResponseFramework;
}
