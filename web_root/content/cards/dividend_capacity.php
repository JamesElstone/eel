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
        $warnings = (array)($context['dividends']['warnings'] ?? []);
        if (empty($capacity['available'])) {
            return '<div class="settings-stack">
                ' . $this->renderErrors((array)($capacity['errors'] ?? ['Dividend capacity is not available.'])) . '
                <div class="summary-grid four">' . $this->warningCards($warnings) . '</div>
            </div>';
        }

        $companySettings = (array)($company['settings'] ?? []);

        return '<div class="settings-stack">
            ' . $this->summaryCard('Capacity date', (string)($capacity['as_at_date'] ?? ''), 'summary-card-fit') . '
            <section class="panel-soft settings-stack">
                <div class="summary-label">Distributable reserves</div>
                <div class="helper">' . HelperFramework::escape($this->reservesEquation($companySettings, $capacity)) . '</div>
                <div><span class="badge ' . HelperFramework::escape(!empty($capacity['reserves_reliable']) ? 'success' : 'danger') . '">' . HelperFramework::escape(!empty($capacity['reserves_reliable']) ? 'Reserve basis verified' : 'Reserve basis blocked') . '</span></div>
                <div class="helper">' . HelperFramework::escape((string)($capacity['retained_earnings_detail'] ?? '')) . '</div>
            </section>
            <div class="summary-grid four">
                ' . $this->summaryCard('Retained earnings brought forward', $this->money($companySettings, $capacity['retained_earnings_brought_forward'] ?? 0)) . '
                ' . $this->summaryCard('Current year profit / loss', $this->money($companySettings, $capacity['current_year_profit_loss'] ?? 0)) . '
                ' . $this->summaryCard('Dividends already declared', $this->money($companySettings, $capacity['dividends_declared'] ?? 0)) . '
                ' . $this->summaryCard('Available distributable reserves', $this->money($companySettings, $capacity['available_distributable_reserves'] ?? 0)) . '
            </div>
            <div class="summary-grid four">' . $this->warningCards($warnings) . '</div>
        </div>';
    }

    private function summaryCard(string $label, string $value, string $extraClass = ''): string
    {
        $class = trim('summary-card ' . $extraClass);

        return '<div class="' . HelperFramework::escape($class) . '"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value !== '' ? $value : '-') . '</div></div>';
    }

    private function warningCards(array $warnings): string
    {
        if ($warnings === []) {
            return '<div class="summary-card"><div class="summary-label">Dividend warnings</div><div class="summary-value">-</div><div class="helper">No dividend warnings for the selected period.</div></div>';
        }

        $html = '';
        foreach ($warnings as $warning) {
            $severity = (string)($warning['severity'] ?? 'info');
            $html .= '<div class="summary-card">
                <div class="summary-label">' . HelperFramework::escape((string)($warning['title'] ?? 'Warning')) . '</div>
                <div class="summary-value"><span class="badge ' . HelperFramework::escape($this->badgeClass($severity)) . '">' . HelperFramework::escape(HelperFramework::labelFromKey($severity, '_')) . '</span></div>
                <div class="helper">' . HelperFramework::escape((string)($warning['detail'] ?? '')) . '</div>
            </div>';
        }

        return $html;
    }

    private function reservesEquation(array $companySettings, array $capacity): string
    {
        return 'Retained profits brought forward ('
            . $this->money($companySettings, $capacity['retained_earnings_brought_forward'] ?? 0)
            . ') + current year profit/loss ('
            . $this->money($companySettings, $capacity['current_year_profit_loss'] ?? 0)
            . ') - dividends already declared ('
            . $this->money($companySettings, $capacity['dividends_declared'] ?? 0)
            . ') = available distributable reserves ('
            . $this->money($companySettings, $capacity['available_distributable_reserves'] ?? 0)
            . ')';
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
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

    private function renderErrors(array $errors): string
    {
        $html = '';
        foreach ($errors as $error) {
            $html .= '<div class="helper">' . HelperFramework::escape((string)$error) . '</div>';
        }

        return $html;
    }
}
