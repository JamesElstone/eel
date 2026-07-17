<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _ixbrl_readinessCard extends CardBaseFramework
{
    public function key(): string { return 'ixbrl_readiness'; }

    public function title(): string { return 'iXBRL Readiness'; }

    public function helper(array $context): string
    {
        return 'This builder creates a generated FRS 105 micro-entity accounts iXBRL export for review and validation before filing.';
    }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $readiness = (array)($context['ixbrl']['readiness'] ?? []);
        $accountingPeriod = (array)($readiness['accounting_period'] ?? []);
        $checks = (array)($readiness['checks'] ?? []);
        [$headline, $headlineClass] = $this->headline($readiness);
        $period = (string)($accountingPeriod['period_start'] ?? '') . ' to ' . (string)($accountingPeriod['period_end'] ?? '');

        $items = '';
        foreach ($checks as $check) {
            $statusLabel = $this->statusLabel((string)($check['status_label'] ?? (!empty($check['complete']) ? 'Ready' : 'Warning')));
            $items .= '<div class="summary-card">
                <div class="summary-label">' . HelperFramework::escape((string)($check['label'] ?? 'Check')) . '</div>
                <div class="summary-value"><span class="badge ' . HelperFramework::escape((string)($check['status'] ?? 'warning')) . '">' . HelperFramework::escape($statusLabel) . '</span></div>
                <div class="helper">' . HelperFramework::escape((string)($check['detail'] ?? '')) . '</div>
            </div>';
        }

        return '<div class="settings-stack">
            <section class="summary-grid">
                <div class="summary-card">
                    <div class="summary-label">Period</div>
                    <div class="summary-value">' . HelperFramework::escape($period) . '</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Status</div>
                    <div class="summary-value"><span class="badge ' . HelperFramework::escape($headlineClass) . '">' . HelperFramework::escape($headline) . '</span></div>
                </div>
                ' . $this->capability('Build facts', !empty($readiness['can_build_facts'])) . '
                ' . $this->capability('Generate filing', !empty($readiness['can_generate'])) . '
                ' . $this->capability('Run Arelle', !empty($readiness['can_validate'])) . '
                ' . $this->capability('Filing ready', !empty($readiness['ready_for_filing'])) . '
            </section>
            <section class="summary-grid">' . $items . '</section>
        </div>';
    }

    private function headline(array $readiness): array
    {
        if (!empty($readiness['ready_for_filing'])) {
            return ['Filing ready', 'success'];
        }
        if (!empty($readiness['can_generate'])) {
            return ['Ready to generate', 'warning'];
        }
        if (!empty($readiness['can_build_facts'])) {
            return ['Ready to build facts', 'info'];
        }

        return ['Not ready', 'danger'];
    }

    private function statusLabel(string $label): string
    {
        return preg_match('/^(?:Build|Generation|Filing) blocked$/i', trim($label)) === 1
            ? 'Not ready'
            : $label;
    }

    private function capability(string $label, bool $available): string
    {
        return '<div class="summary-card">
            <div class="summary-label">' . HelperFramework::escape($label) . '</div>
            <div class="summary-value"><span class="badge ' . ($available ? 'success' : 'muted') . '">' . ($available ? 'Available' : 'Not available') . '</span></div>
        </div>';
    }
}
