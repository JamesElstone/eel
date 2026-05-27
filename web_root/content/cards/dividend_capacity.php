<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dividend_capacityCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'dividend_capacity';
    }

    public function title(): string
    {
        return 'Dividend Capacity';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $capacity = (array)($context['dividends']['capacity'] ?? []);
        if (empty($capacity['available'])) {
            return $this->renderErrors((array)($capacity['errors'] ?? ['Dividend capacity is not available.']));
        }

        $accountingPeriod = (array)($capacity['accounting_period'] ?? []);
        $badgeClass = (string)($capacity['status_badge_class'] ?? 'info');
        $statusLabel = (string)($capacity['status_label'] ?? 'Unknown');

        return '<div class="settings-stack">
            <section class="panel-soft">
                <div class="status-head">
                    <h3 class="card-title">Selected scope</h3>
                    <span class="badge ' . HelperFramework::escape($badgeClass) . '">' . HelperFramework::escape($statusLabel) . '</span>
                </div>
                <div class="summary-grid">
                    ' . $this->summaryCard('Company', (string)($company['name'] ?? '')) . '
                    ' . $this->summaryCard('Company number', (string)($company['number'] ?? '')) . '
                    ' . $this->summaryCard('Accounting period', (string)($accountingPeriod['label'] ?? '')) . '
                    ' . $this->summaryCard('Capacity date', (string)($capacity['as_at_date'] ?? '')) . '
                </div>
            </section>
            <section class="panel-soft">
                <div class="summary-grid">
                    ' . $this->summaryCard('Retained earnings brought forward', FormattingFramework::money($capacity['retained_earnings_brought_forward'] ?? 0)) . '
                    ' . $this->summaryCard('Current year profit / loss', FormattingFramework::money($capacity['current_year_profit_loss'] ?? 0)) . '
                    ' . $this->summaryCard('Dividends already declared', FormattingFramework::money($capacity['dividends_declared'] ?? 0)) . '
                    ' . $this->summaryCard('Available distributable reserves', FormattingFramework::money($capacity['available_distributable_reserves'] ?? 0)) . '
                </div>
                <div class="helper">Retained earnings brought forward: ' . HelperFramework::escape((string)($capacity['retained_earnings_status'] ?? 'Pending prior-period close')) . '.</div>
            </section>
        </div>';
    }

    private function summaryCard(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value !== '' ? $value : '-') . '</div></div>';
    }

    private function renderErrors(array $errors): string
    {
        $html = '';
        foreach ($errors as $error) {
            $html .= '<div class="helper">' . HelperFramework::escape((string)$error) . '</div>';
        }

        return $html;
    }
}
