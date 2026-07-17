<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _hmrc_obligations_period_checklistCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_obligations_period_checklist'; }

    public function title(): string { return 'Selected Period Checklist'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $items = (array)($context['hmrc_obligations']['checklist'] ?? []);
        if ($items === []) {
            return '<div class="helper">Select a company and accounting period to see the HMRC checklist.</div>';
        }

        $html = '';
        foreach ($items as $item) {
            $complete = !empty($item['complete']);
            $html .= '<div class="summary-card">
                <div class="status-head">
                    <div class="summary-label">' . HelperFramework::escape((string)($item['label'] ?? 'Checklist item')) . '</div>
                    <span class="badge ' . ($complete ? 'success' : 'warning') . '">' . ($complete ? 'Done' : 'Needs work') . '</span>
                </div>
                <div class="helper">' . HelperFramework::escape((string)($item['detail'] ?? '')) . '</div>
            </div>';
        }

        return '<div class="summary-grid four">' . $html . '</div>';
    }
}
