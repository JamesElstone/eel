<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _pl_summaryCard extends CardBaseFramework
{
    public function key(): string { return 'pl_summary'; }

    public function title(): string { return 'P&L Summary'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $summary = (array)($context['profit_loss']['summary'] ?? []);
        if (empty($summary['available'])) {
            return $this->messages((array)($summary['errors'] ?? ['Profit & Loss is not available.']));
        }

        $hasJournals = !empty($summary['has_journals']);
        $hasTransactions = !empty($summary['has_transactions']);
        $netProfit = (float)($summary['net_profit'] ?? 0);
        $badgeClass = !$hasJournals ? 'warning' : ($netProfit >= 0 ? 'success' : 'danger');
        $badgeLabel = !$hasJournals ? 'No posted journals' : ($netProfit >= 0 ? 'Profit' : 'Loss');
        $notice = '';
        if (!$hasJournals && $hasTransactions) {
            $notice = '<div class="helper">Transactions exist but no posted journals were found for this period.</div>';
        } elseif (!$hasJournals) {
            $notice = '<div class="helper">No posted journals or transactions exist yet for this period.</div>';
        }

        return '<div class="settings-stack">
            <section class="panel-soft">
                <div class="status-head">
                    <h3 class="card-title">' . HelperFramework::escape((string)($summary['period_label'] ?? 'Selected period')) . '</h3>
                    <span class="badge ' . $badgeClass . '">' . HelperFramework::escape($badgeLabel) . '</span>
                </div>
                ' . $notice . '
                <div class="summary-grid">
                    ' . $this->summaryCard('Income', $summary['income_total'] ?? 0) . '
                    ' . $this->summaryCard('Cost of sales', $summary['cost_of_sales_total'] ?? 0) . '
                    ' . $this->summaryCard('Gross profit', $summary['gross_profit'] ?? 0) . '
                    ' . $this->summaryCard('Expenses', $summary['expense_total'] ?? 0) . '
                    ' . $this->summaryCard('Net profit / loss', $summary['net_profit'] ?? 0) . '
                    <div class="summary-card"><div class="summary-label">Profit margin</div><div class="summary-value">' . HelperFramework::escape(number_format((float)($summary['profit_margin_percent'] ?? 0), 1)) . '%</div></div>
                </div>
            </section>
        </div>';
    }

    private function summaryCard(string $label, mixed $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape(FormattingFramework::money($value)) . '</div></div>';
    }

    private function messages(array $messages): string
    {
        return implode('', array_map(static fn(mixed $message): string => '<div class="helper">' . HelperFramework::escape((string)$message) . '</div>', $messages));
    }
}
