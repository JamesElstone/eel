<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _pl_tradingCard extends CardBaseFramework
{
    public function key(): string { return 'pl_trading'; }

    public function title(): string { return 'Trading'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $heatmap = (array)($context['profit_loss']['sales_heatmap'] ?? []);
        if (empty($heatmap['available'])) {
            return '<div class="helper">Select an accounting period to review sales activity.</div>';
        }

        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);
        $days = array_map(
            function (mixed $day) use ($companySettings): array {
                $day = is_array($day) ? $day : [];
                $date = (string)($day['date'] ?? '');
                $sales = (float)($day['sales'] ?? $day['value'] ?? 0);
                $dateLabel = $date;
                try {
                    $dateLabel = (new DateTimeImmutable($date))->format('j F Y');
                } catch (Throwable) {
                }

                return [
                    'date' => $date,
                    'value' => (float)($day['value'] ?? max(0.0, $sales)),
                    'title' => $this->money($companySettings, $sales) . ' sales on ' . $dateLabel,
                ];
            },
            (array)($heatmap['days'] ?? [])
        );

        $chart = (new ChartService())->calendarHeatmap($days, [
            'title' => 'Sales by day',
            'start_date' => (string)($heatmap['period_start'] ?? ''),
            'end_date' => (string)($heatmap['period_end'] ?? ''),
            'value_label' => 'sales',
            'input_name' => 'trading_sales_date',
            'range_control' => ['type' => 'date', 'options' => []],
            'legend' => true,
        ]);

        return '<section class="panel-soft pl-trading-heatmap">'
            . '<div class="helper">Posted sales for the accounting period. Darker days have higher sales.</div>'
            . $chart
            . '<div class="helper"><strong>Total sales:</strong> '
            . \eel_accounts\Support\Utf8::html($this->money($companySettings, $heatmap['total_sales'] ?? 0))
            . '</div>'
            . '</section>';
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }
}
