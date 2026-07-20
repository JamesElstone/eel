<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _incorporation_statusCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'incorporation_status';
    }

    public function title(): string
    {
        return 'Share Status';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'incorporationShares',
                'service' => \eel_accounts\Service\IncorporationShareCapitalService::class,
                'method' => 'fetchSummary',
                'params' => ['companyId' => ':company.id'],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['incorporation.share.capital', 'incorporation.payment.matching', 'year.end.checklist'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $summary = (array)($context['services']['incorporationShares'] ?? []);
        if (empty($summary['available'])) {
            return '<section class="settings-stack"><div class="helper">' . HelperFramework::escape((string)(($summary['errors'] ?? [])[0] ?? 'Incorporation status is not available.')) . '</div></section>';
        }

        $settings = (array)(($context['company'] ?? [])['settings'] ?? []);
        $totals = (array)($summary['totals'] ?? []);

        return '<section class="settings-stack" id="incorporation-status">
            <div class="summary-grid four">
                ' . $this->summaryCard('Issued nominal capital', $this->money($settings, $totals['issued_nominal_total'] ?? 0)) . '
                ' . $this->summaryCard('Expected paid total', $this->money($settings, $totals['expected_paid_total'] ?? 0)) . '
                ' . $this->summaryCard('Matched receipts', $this->money($settings, $totals['matched_total'] ?? 0)) . '
                ' . $this->summaryCard('Unpaid share capital', $this->money($settings, $totals['paid_up_unpaid_total'] ?? ($totals['unpaid_total'] ?? 0))) . '
            </div>
        </section>';
    }

    private function summaryCard(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }
}
