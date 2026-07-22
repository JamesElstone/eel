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
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $reserveReviewCurrent = (string)($capacity['reserve_review_status'] ?? '') === 'current'
            && !empty($capacity['reserve_review_profit_reconciles']);
        $pendingReviewValue = 'Pending reserve review';
        $availableReservesValue = $reserveReviewCurrent
            ? $this->money($companySettings, $capacity['available_distributable_reserves'] ?? 0)
            : $pendingReviewValue;
        $profitAfterTaxValue = $reserveReviewCurrent
            ? $this->money($companySettings, $capacity['current_year_profit_loss_after_tax'] ?? $capacity['current_year_profit_loss'] ?? 0)
            : $pendingReviewValue;
        $reviewScopeWarnings = array_values(array_filter(
            $warnings,
            static fn(mixed $warning): bool => is_array($warning)
                && (string)($warning['title'] ?? '') === 'Dividend review scope'
        ));
        $otherWarnings = array_values(array_filter(
            $warnings,
            static fn(mixed $warning): bool => !is_array($warning)
                || (string)($warning['title'] ?? '') !== 'Dividend review scope'
        ));

        return '<div class="settings-stack">
            <div class="summary-grid four dividend-capacity-summary-grid">
                ' . $this->summaryCard('Capacity date', (string)($capacity['as_at_date'] ?? '')) . '
                ' . $this->warningCards($reviewScopeWarnings, $companyId, $accountingPeriodId) . '
                ' . $this->summaryCard('Available distributable reserves', $availableReservesValue) . '
                ' . $this->reserveBasisCard($capacity) . '
                ' . $this->warningCards($otherWarnings, $companyId, $accountingPeriodId) . '
                ' . $this->warningCards($reliabilityWarnings, $companyId, $accountingPeriodId) . '
            </div>
            <div class="table-scroll">
                <table class="table dividend-capacity-summary">
                    <thead><tr><th>Title</th><th>Description</th><th class="numeric">Value</th></tr></thead>
                    <tbody>
                        ' . $this->summaryTableRow('Distributable Reserves B/F', 'Distributable Reserves from the previous accounting period', $this->money($companySettings, $capacity['distributable_reserves_brought_forward'] ?? $capacity['retained_earnings_brought_forward'] ?? 0)) . '
                        ' . $this->summaryTableRow('Ledger profit / loss', 'Profit before Tax', $this->money($companySettings, $capacity['ledger_current_year_profit_loss'] ?? 0)) . '
                        ' . ($reserveReviewCurrent
                            ? $this->summaryTableRow('Classified Realised Profit', 'The current-period profit accepted by the reserve review as realised/distributable, before the remaining unposted CT adjustment.', $this->money($companySettings, $capacity['classified_current_year_profit_loss'] ?? 0))
                            : $this->summaryTableActionRow('Classified Realised Profit', 'Complete the reserve review before relying on a classified realised profit or loss.', '<a class="button warn" href="?page=profit_loss&amp;show_card=reserve_review">Complete Reserve Review</a>')) . '
                        ' . $this->summaryTableRow('Corporation Tax payable', $this->estimatedCorporationTaxHelper($companySettings, $capacity), $this->money($companySettings, $capacity['estimated_corporation_tax'] ?? 0)) . '
                        ' . $this->summaryTableRow('L2P relief receivable', 'Relief receivable for qualifying later repayments; it reduces the tax charge but does not rewrite the accepted CT600A A80 amount.', $this->money($companySettings, $capacity['l2p_relief_receivable'] ?? 0)) . '
                        ' . $this->summaryTableRow('Net estimated tax charge', 'Corporation Tax payable less any L2P relief receivable. This is the tax charge used in profit and reserves.', $this->money($companySettings, $capacity['estimated_tax_charge'] ?? $capacity['estimated_corporation_tax'] ?? 0)) . '
                        ' . $this->summaryTableRow('Unposted tax charge deducted', $this->unpostedCorporationTaxHelper($companySettings, $capacity), $this->money($companySettings, $capacity['unposted_corporation_tax_adjustment'] ?? 0)) . '
                        ' . $this->summaryTableRow('Profit after Tax', 'The reviewed, distributable portion of current-period profit after deducting any unposted Corporation Tax charge.', $profitAfterTaxValue) . '
                        ' . $this->summaryTableRow('Declared Dividends', 'Declared in this Accounting Period', $this->money($companySettings, $capacity['dividends_declared'] ?? 0)) . '
                        ' . $this->summaryTableRow('Available distributable reserves', 'Distributable Reserves B/F + Profit after Tax - Declared Dividends', $availableReservesValue) . '
                    </tbody>
                </table>
            </div>
        </div>';
    }

    private function summaryCard(string $label, string $value, string $extraClass = '', string $helper = ''): string
    {
        $class = trim('summary-card dividend-capacity-summary-card ' . $extraClass);

        return '<div class="' . HelperFramework::escape($class) . '"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value !== '' ? $value : '-') . '</div>' . ($helper !== '' ? '<div class="helper">' . HelperFramework::escape($helper) . '</div>' : '') . '</div>';
    }

    private function reserveBasisCard(array $capacity): string
    {
        $reliable = !empty($capacity['reserves_reliable']);
        $statusClass = $reliable ? 'is-success' : 'is-danger';

        return '<div class="summary-card dividend-capacity-summary-card has-summary-card-pill ' . $statusClass . '">
            <div class="summary-card-header"><div class="summary-label">Reserve basis</div><div class="summary-card-pill"><span class="badge ' . HelperFramework::escape($reliable ? 'success' : 'danger') . '">' . HelperFramework::escape($reliable ? 'Reserve basis verified' : 'Reserve basis blocked') . '</span></div></div>
        </div>';
    }

    private function summaryTableRow(string $title, string $description, string $value): string
    {
        return '<tr><td>' . HelperFramework::escape($title) . '</td><td>'
            . HelperFramework::escape($description !== '' ? $description : '—')
            . '</td><td class="numeric">' . HelperFramework::escape($value !== '' ? $value : '-') . '</td></tr>';
    }

    private function summaryTableActionRow(string $title, string $description, string $actionHtml): string
    {
        return '<tr><td>' . HelperFramework::escape($title) . '</td><td>'
            . HelperFramework::escape($description !== '' ? $description : '—')
            . '</td><td class="numeric">' . $actionHtml . '</td></tr>';
    }

    private function warningCards(array $warnings, int $companyId = 0, int $accountingPeriodId = 0): string
    {
        if ($warnings === []) {
            return '';
        }

        $html = '';
        foreach ($warnings as $warning) {
            if (!is_array($warning)) {
                continue;
            }
            $severity = (string)($warning['severity'] ?? 'info');
            $metricValue = trim((string)($warning['metric_value'] ?? ''));
            $actionLabel = trim((string)($warning['action_label'] ?? 'Open Related Workflow'));
            $actionHtml = \eel_accounts\Renderer\WorkflowHandoffRenderer::fromWorkflow(
                $warning,
                $actionLabel,
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]
            );
            $badgeClass = $this->badgeClass($severity);
            $badgeHtml = '<span class="badge ' . HelperFramework::escape($badgeClass) . '">' . HelperFramework::escape(HelperFramework::labelFromKey($severity, '_')) . '</span>';
            $html .= '<div class="summary-card dividend-capacity-summary-card has-summary-card-pill is-' . HelperFramework::escape($badgeClass) . '">
                <div class="summary-card-header"><div class="summary-label">' . HelperFramework::escape((string)($warning['title'] ?? 'Warning')) . '</div><div class="summary-card-pill">' . $badgeHtml . '</div></div>
                ' . ($metricValue !== '' ? '<div class="summary-value">' . HelperFramework::escape($metricValue) . '</div>' : '') . '
                <div class="helper">' . HelperFramework::escape((string)($warning['detail'] ?? '')) . '</div>
                ' . ($actionHtml !== '' ? '<div class="actions-row">' . $actionHtml . '</div>' : '') . '
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

        $ordinary = $this->money($companySettings, $capacity['ordinary_corporation_tax'] ?? 0);
        $ct600a = $this->money($companySettings, $capacity['ct600a_tax'] ?? 0);
        $total = $this->money($companySettings, $capacity['estimated_corporation_tax'] ?? 0);
        $components = 'Ordinary CT ' . $ordinary . ' + CT600A A80 ' . $ct600a . ' = ' . $total;

        $amounts = array_map(
            fn(array $period): string => $this->money($companySettings, $period['estimated_corporation_tax'] ?? 0),
            $periods
        );
        if (count($amounts) === 1) {
            return $components;
        }

        return $components . '. CT periods: ' . implode(' + ', $amounts) . ' = ' . $total;
    }

    private function unpostedCorporationTaxHelper(array $companySettings, array $capacity): string
    {
        $estimate = $this->money($companySettings, $capacity['estimated_tax_charge'] ?? $capacity['estimated_corporation_tax'] ?? 0);
        $posted = $this->money($companySettings, $capacity['posted_corporation_tax_charge'] ?? 0);
        $unposted = $this->money($companySettings, $capacity['unposted_corporation_tax_adjustment'] ?? 0);

        return 'Estimated total tax charge ' . $estimate . ' - posted tax charge ' . $posted . ' = ' . $unposted;
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
