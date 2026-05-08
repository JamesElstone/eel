<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dashboard_notesCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'dashboard_notes';
    }

    public function services(): array
    {
        return [];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        return '
            <div class="list">
                <div class="list-item">
                    <strong>Note 1</strong>
                    <span>Example 1.</span>
                </div>
                <div class="list-item">
                    <strong>Note 2</strong>
                    <span>Example 2.</span>
                </div>
                <div class="list-item">
                    <strong>Note 2</strong>
                    <span>Example 2.</span>
                </div>
            </div>
        ';
    }
}
