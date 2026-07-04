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
        return 'Incorporation Status';
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
        $status = (string)($summary['status'] ?? 'missing');

        return '<section class="settings-stack" id="incorporation-status">
            <div class="status-head"><span class="badge ' . HelperFramework::escape($this->badgeClass($status)) . '">' . HelperFramework::escape($this->statusLabel($status)) . '</span></div>
            <div class="summary-grid">
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

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'complete' => 'Paid and matched',
            'shares_not_paid_up' => 'Shares not paid up',
            'payment_unmatched' => 'Payment not matched',
            default => 'Not recorded',
        };
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'complete' => 'success',
            'shares_not_paid_up' => 'warning',
            'payment_unmatched' => 'warning',
            default => 'danger',
        };
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }
}
