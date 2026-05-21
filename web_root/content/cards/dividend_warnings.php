<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dividend_warningsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'dividend_warnings';
    }

    public function title(): string
    {
        return 'Dividend Warnings';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function render(array $context): string
    {
        $warnings = (array)($context['dividends']['warnings'] ?? []);
        if ($warnings === []) {
            return '<div class="helper">No dividend warnings for the selected period.</div>';
        }

        $html = '';
        foreach ($warnings as $warning) {
            $severity = (string)($warning['severity'] ?? 'info');
            $html .= '<section class="panel-soft">
                <div class="status-head">
                    <h3 class="card-title">' . HelperFramework::escape((string)($warning['title'] ?? 'Warning')) . '</h3>
                    <span class="badge ' . HelperFramework::escape($this->badgeClass($severity)) . '">' . HelperFramework::escape(HelperFramework::labelFromKey($severity, '_')) . '</span>
                </div>
                <div class="helper">' . HelperFramework::escape((string)($warning['detail'] ?? '')) . '</div>
            </section>';
        }

        return '<div class="settings-stack">' . $html . '</div>';
    }

    private function badgeClass(string $severity): string
    {
        return match ($severity) {
            'danger', 'fail', 'error' => 'danger',
            'warning' => 'warning',
            'success', 'pass' => 'success',
            default => 'info',
        };
    }
}
