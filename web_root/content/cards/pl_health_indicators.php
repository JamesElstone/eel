<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _pl_health_indicatorsCard extends CardBaseFramework
{
    public function key(): string { return 'pl_health_indicators'; }

    public function title(): string { return 'P&L Health Indicators'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $health = (array)($context['profit_loss']['health'] ?? []);
        if (empty($health['available'])) {
            return $this->messages((array)($health['errors'] ?? ['Profit & Loss health is not available.']));
        }

        $score = (int)($health['books_health_score'] ?? 0);
        $scoreClass = $score >= 80 ? 'success' : ($score >= 50 ? 'warning' : 'danger');

        return '<div class="settings-stack">
            <section class="panel-soft">
                <div class="status-head">
                    <h3 class="card-title">Books health score</h3>
                    <span class="badge ' . $scoreClass . '">' . $score . '/100</span>
                </div>
                <div class="summary-grid">
                    ' . $this->metric('Categorised', number_format((float)($health['categorised_percent'] ?? 0), 1) . '%') . '
                    ' . $this->metric('Uncategorised transactions', (string)(int)($health['uncategorised_transactions'] ?? 0)) . '
                    ' . $this->metric('Missing months', (string)(int)($health['missing_month_count'] ?? 0)) . '
                    ' . $this->metric('Uploaded months', (string)(int)($health['uploaded_month_count'] ?? 0)) . '
                    ' . $this->metric('Committed months', (string)(int)($health['committed_month_count'] ?? 0)) . '
                    ' . $this->metric('Uploads in progress', (string)(int)($health['upload_in_progress_count'] ?? 0)) . '
                </div>
            </section>
        </div>';
    }

    private function metric(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function messages(array $messages): string
    {
        return implode('', array_map(static fn(mixed $message): string => '<div class="helper">' . HelperFramework::escape((string)$message) . '</div>', $messages));
    }
}
