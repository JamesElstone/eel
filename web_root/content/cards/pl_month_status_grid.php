<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _pl_month_status_gridCard extends CardBaseFramework
{
    public function key(): string { return 'pl_month_status_grid'; }

    public function title(): string { return 'Month Status Grid'; }

    protected function additionalInvalidationFacts(): array { return ['transactions.imported', 'page.context']; }

    public function render(array $context): string
    {
        $months = (array)($context['profit_loss']['month_status_grid'] ?? []);
        if ($months === []) {
            return '<div class="helper">No accounting period months are available.</div>';
        }
        $html = '';
        foreach ($months as $month) {
            $status = (string)($month['status'] ?? 'no_data');
            $html .= '<div class="month-tile ' . HelperFramework::escape($this->tileClass($status)) . '">
                <div class="month-head"><div><div class="month-name">' . HelperFramework::escape((string)($month['month_label'] ?? '')) . '</div></div><span class="month-dot"></span></div>
                <div class="helper"><strong>' . (int)($month['transaction_count'] ?? 0) . ' transactions</strong></div>
                <div class="helper">' . (int)($month['uncategorised_count'] ?? 0) . ' uncategorised</div>
                <div class="helper">' . (int)($month['upload_count'] ?? 0) . ' upload(s)</div>
                <span class="badge ' . HelperFramework::escape($this->badgeClass($status)) . '">' . HelperFramework::escape(HelperFramework::labelFromKey($status, '_')) . '</span>
            </div>';
        }
        return '<div class="month-grid">' . $html . '</div>';
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'ready' => 'success',
            'needs_categorisation', 'upload_in_progress' => 'warning',
            'outside_period' => 'info',
            default => 'danger',
        };
    }

    private function tileClass(string $status): string
    {
        return match ($status) {
            'ready' => 'green',
            'needs_categorisation', 'upload_in_progress' => 'amber',
            default => 'red',
        };
    }
}
