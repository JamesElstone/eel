<?php
declare(strict_types=1);

final class _tax_period_selectorCard extends CardBaseFramework
{
    public function key(): string { return 'tax_period_selector'; }
    public function title(): string { return 'An accounting period can be any length, but a tax period can only be 12 months in length. So multiple tax periods can be within a single accounting period.'; }
    protected function additionalInvalidationFacts(): array { return ['page.context', 'tax.workings']; }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $tax = (array)($context['tax'] ?? []);
        $ctPeriods = (array)($tax['ct_periods'] ?? []);
        $selectedCtPeriodId = (int)($tax['selected_ct_period_id'] ?? 0);

        if ($ctPeriods === []) {
            return '<div class="helper">No CT periods are available for the selected accounting period.</div>';
        }

        return $this->periodSummary($ctPeriods, $selectedCtPeriodId);
    }

    private function options(array $ctPeriods, int $selectedCtPeriodId): string
    {
        $html = '';
        foreach ($ctPeriods as $period) {
            $id = (int)($period['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $label = 'CT Period ' . (int)($period['sequence_no'] ?? 0)
                . ' - ' . (string)($period['period_start'] ?? '')
                . ' to ' . (string)($period['period_end'] ?? '')
                . ' (' . (string)($period['status'] ?? 'pending') . ')';
            $html .= '<option value="' . $id . '"' . ($id === $selectedCtPeriodId ? ' selected' : '') . '>' . HelperFramework::escape($label) . '</option>';
        }

        return $html;
    }

    private function periodSummary(array $ctPeriods, int $selectedCtPeriodId): string
    {
        foreach ($ctPeriods as $period) {
            if ((int)($period['id'] ?? 0) !== $selectedCtPeriodId) {
                continue;
            }

            return '<div class="summary-grid five">'
                . '<form method="get" action="?page=tax" data-ajax="true" class="summary-card tax-period-selector-summary-card">
                    <input type="hidden" name="page" value="tax">
                    <label class="summary-label" for="tax_ct_period_id">CT period</label>
                    <select class="select" id="tax_ct_period_id" name="ct_period_id">' . $this->options($ctPeriods, $selectedCtPeriodId) . '</select>
                </form>'
                . $this->summaryCard('Showing CT Period', 'CT Period ' . (int)($period['sequence_no'] ?? 0))
                . $this->summaryCard('Start', (string)($period['period_start'] ?? ''))
                . $this->summaryCard('End', (string)($period['period_end'] ?? ''))
                . $this->summaryCard('Status', HelperFramework::labelFromKey((string)($period['status'] ?? 'pending'), '_'))
                . '</div>';
        }

        return '';
    }

    private function summaryCard(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">'
            . HelperFramework::escape($label)
            . '</div><div class="summary-value">'
            . HelperFramework::escape($value)
            . '</div></div>';
    }
}
