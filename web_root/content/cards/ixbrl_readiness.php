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

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $readiness = (array)($context['ixbrl']['readiness'] ?? []);
        $company = (array)($readiness['company'] ?? $context['company'] ?? []);
        $accountingPeriod = (array)($readiness['accounting_period'] ?? []);
        $checks = (array)($readiness['checks'] ?? []);

        $items = '';
        foreach ($checks as $check) {
            $items .= '<div class="summary-card">
                <div class="summary-label">' . HelperFramework::escape((string)($check['label'] ?? 'Check')) . '</div>
                <div class="summary-value"><span class="badge ' . HelperFramework::escape((string)($check['status'] ?? 'warning')) . '">' . HelperFramework::escape(!empty($check['complete']) ? 'Ready' : (!empty($check['blocking']) ? 'Blocked' : 'Warning')) . '</span></div>
                <div class="helper">' . HelperFramework::escape((string)($check['detail'] ?? '')) . '</div>
            </div>';
        }

        return '<div class="settings-stack">
            <section class="panel-soft">
                <div class="status-head">
                    <h3 class="card-title">' . HelperFramework::escape((string)($company['company_name'] ?? 'Selected company')) . '</h3>
                    <span class="badge ' . (!empty($readiness['can_build_facts']) ? 'success' : 'danger') . '">' . HelperFramework::escape(!empty($readiness['can_build_facts']) ? 'Ready to build facts' : 'Blocked') . '</span>
                </div>
                <div class="helper">Period: ' . HelperFramework::escape((string)($accountingPeriod['period_start'] ?? '')) . ' to ' . HelperFramework::escape((string)($accountingPeriod['period_end'] ?? '')) . '</div>
                <div class="helper">This builder creates an internal generated FRS 105 micro-entity accounts preview. It is not a complete HMRC CT600 submission package.</div>
            </section>
            <section class="summary-grid">' . $items . '</section>
        </div>';
    }
}
