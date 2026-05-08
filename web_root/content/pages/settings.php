<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _settings extends PageContextFramework
{
    public function id(): string
    {
        return 'settings';
    }

    public function title(): string
    {
        return 'Settings';
    }

    public function subtitle(): string
    {
        return 'Application Settings.';
    }

    public function cards(): array
    {
        return [
            // 'dump_context'
         ];
    }

    public function services(): array
    {
        return [];
    }

}
