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

    public function services(): array
    {
        return [$this->dividendContextService()];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $dividends = $this->dividendsContext($context);
        $capacity = (array)($dividends['capacity'] ?? []);
        $warnings = (array)($dividends['warnings'] ?? []);
        $reliabilityWarnings = (array)($capacity['reliability_warnings'] ?? []);
        if (empty($capacity['available'])) {
            return '<div class="settings-stack">
                ' . $this->renderErrors((array)($capacity['errors'] ?? ['Dividend capacity is not available.'])) . '
                <div class="summary-grid four">' . $this->warningCards($warnings) . '</div>
            </div>';
        }

        $companySettings = (array)($company['settings'] ?? []);

        return '<div class="settings-stack">
            ' . $this->summaryCard('Capacity date', (string)($capacity['as_at_date'] ?? ''), 'summary-card-fit') . '
            ' . $this->reliabilityWarningPanels($reliabilityWarnings, (int)($company['id'] ?? 0), (int)($company['accounting_period_id'] ?? 0)) . '
            <section class="panel-soft settings-stack">
                <div class="summary-label">Distributable reserves</div>
                <div class="helper">' . HelperFramework::escape($this->reservesEquation($companySettings, $capacity)) . '</div>
                <div><span class="badge ' . HelperFramework::escape(!empty($capacity['reserves_reliable']) ? 'success' : 'danger') . '">' . HelperFramework::escape(!empty($capacity['reserves_reliable']) ? 'Reserve basis verified' : 'Reserve basis blocked') . '</span></div>
                <div class="helper">' . HelperFramework::escape((string)($capacity['reserve_basis_detail'] ?? $capacity['retained_earnings_detail'] ?? '')) . '</div>
            </section>
            <div class="summary-grid four">
                ' . $this->summaryCard('Distributable reserves brought forward', $this->money($companySettings, $capacity['distributable_reserves_brought_forward'] ?? $capacity['retained_earnings_brought_forward'] ?? 0)) . '
                ' . $this->summaryCard('Reviewed current profit after CT', $this->money($companySettings, $capacity['current_year_profit_loss_after_tax'] ?? $capacity['current_year_profit_loss'] ?? 0)) . '
                ' . $this->summaryCard('Dividends already declared', $this->money($companySettings, $capacity['dividends_declared'] ?? 0)) . '
                ' . $this->summaryCard('Available distributable reserves', $this->money($companySettings, $capacity['available_distributable_reserves'] ?? 0)) . '
            </div>
            <div class="summary-grid four">
                ' . $this->summaryCard('Ledger profit / loss', $this->money($companySettings, $capacity['ledger_current_year_profit_loss'] ?? 0)) . '
                ' . $this->summaryCard('Classified realised profit', $this->money($companySettings, $capacity['classified_current_year_profit_loss'] ?? 0)) . '
                ' . $this->summaryCard('Estimated Corporation Tax', $this->money($companySettings, $capacity['estimated_corporation_tax'] ?? 0), '', $this->estimatedCorporationTaxHelper($companySettings, $capacity)) . '
                ' . $this->summaryCard('Unposted Corporation Tax deducted', $this->money($companySettings, $capacity['unposted_corporation_tax_adjustment'] ?? 0), '', $this->unpostedCorporationTaxHelper($companySettings, $capacity)) . '
            </div>
            <div class="summary-grid four">' . $this->warningCards($warnings) . '</div>
        </div>';
    }

    private function summaryCard(string $label, string $value, string $extraClass = '', string $helper = ''): string
    {
        $class = trim('summary-card ' . $extraClass);

        return '<div class="' . HelperFramework::escape($class) . '"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value !== '' ? $value : '-') . '</div>' . ($helper !== '' ? '<div class="helper">' . HelperFramework::escape($helper) . '</div>' : '') . '</div>';
    }

    private function warningCards(array $warnings): string
    {
        if ($warnings === []) {
            return '<div class="summary-card"><div class="summary-label">Dividend warnings</div><div class="summary-value">-</div><div class="helper">No dividend warnings for the selected period.</div></div>';
        }

        $html = '';
        foreach ($warnings as $warning) {
            $severity = (string)($warning['severity'] ?? 'info');
            $metricValue = trim((string)($warning['metric_value'] ?? ''));
            $html .= '<div class="summary-card">
                <div class="summary-label">' . HelperFramework::escape((string)($warning['title'] ?? 'Warning')) . '</div>
                <div class="summary-value">' . ($metricValue !== ''
                    ? HelperFramework::escape($metricValue)
                    : '<span class="badge ' . HelperFramework::escape($this->badgeClass($severity)) . '">' . HelperFramework::escape(HelperFramework::labelFromKey($severity, '_')) . '</span>') . '</div>
                ' . ($metricValue !== '' ? '<div><span class="badge ' . HelperFramework::escape($this->badgeClass($severity)) . '">' . HelperFramework::escape(HelperFramework::labelFromKey($severity, '_')) . '</span></div>' : '') . '
                <div class="helper">' . HelperFramework::escape((string)($warning['detail'] ?? '')) . '</div>
            </div>';
        }

        return $html;
    }

    private function reservesEquation(array $companySettings, array $capacity): string
    {
        return 'Distributable reserves brought forward ('
            . $this->money($companySettings, $capacity['distributable_reserves_brought_forward'] ?? $capacity['retained_earnings_brought_forward'] ?? 0)
            . ') + reviewed current-year realised profit after CT ('
            . $this->money($companySettings, $capacity['current_year_profit_loss_after_tax'] ?? $capacity['current_year_profit_loss'] ?? 0)
            . ') - dividends already declared ('
            . $this->money($companySettings, $capacity['dividends_declared'] ?? 0)
            . ') = available distributable reserves ('
            . $this->money($companySettings, $capacity['available_distributable_reserves'] ?? 0)
            . ')';
    }

    private function estimatedCorporationTaxHelper(array $companySettings, array $capacity): string
    {
        $periods = array_values(array_filter(
            (array)($capacity['tax_periods'] ?? []),
            static fn(mixed $period): bool => is_array($period)
        ));
        if ($periods === []) {
            return '';
        }

        $amounts = array_map(
            fn(array $period): string => $this->money($companySettings, $period['estimated_corporation_tax'] ?? 0),
            $periods
        );
        if (count($amounts) === 1) {
            return $amounts[0];
        }

        return implode(' + ', $amounts) . ' = ' . $this->money($companySettings, $capacity['estimated_corporation_tax'] ?? 0);
    }

    private function unpostedCorporationTaxHelper(array $companySettings, array $capacity): string
    {
        $estimate = $this->money($companySettings, $capacity['estimated_corporation_tax'] ?? 0);
        $posted = $this->money($companySettings, $capacity['posted_corporation_tax_charge'] ?? 0);
        $unposted = $this->money($companySettings, $capacity['unposted_corporation_tax_adjustment'] ?? 0);

        return 'Estimated Corporation Tax ' . $estimate . ' - posted Corporation Tax ' . $posted . ' = ' . $unposted;
    }

    private function reliabilityWarningPanels(array $warnings, int $companyId, int $accountingPeriodId): string
    {
        if ($warnings === []) {
            return '';
        }

        $html = '<div class="settings-stack">';
        foreach ($warnings as $warning) {
            if (!is_array($warning)) {
                continue;
            }
            $actionLabel = trim((string)($warning['action_label'] ?? 'Open Related Workflow'));
            $actionHtml = \eel_accounts\Renderer\WorkflowHandoffRenderer::fromWorkflow(
                $warning,
                $actionLabel,
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]
            );
            $html .= '<section class="panel-soft settings-stack">
                <div><span class="badge warning">Warning</span></div>
                <div class="summary-label">' . HelperFramework::escape((string)($warning['title'] ?? 'Dividend reliability warning')) . '</div>
                <div class="helper">' . HelperFramework::escape((string)($warning['detail'] ?? '')) . '</div>
                ' . ($actionHtml !== '' ? '<div class="actions-row">' . $actionHtml . '</div>' : '') . '
            </section>';
        }

        return $html . '</div>';
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

    private function dividendContextService(): array
    {
        return [
            'key' => 'dividendContext',
            'service' => \eel_accounts\Service\DividendViewDataService::class,
            'method' => 'fetchCapacityContext',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ];
    }

    private function dividendsContext(array $context): array
    {
        $serviceContext = $context['services']['dividendContext'] ?? null;
        if (is_array($serviceContext)) {
            return $serviceContext;
        }

        return (array)($context['dividends'] ?? []);
    }
}
