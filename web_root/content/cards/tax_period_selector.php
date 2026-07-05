<?php
declare(strict_types=1);

final class _tax_period_selectorCard extends CardBaseFramework
{
    public function key(): string { return 'tax_period_selector'; }
    public function title(): string { return 'Corporation Tax Period'; }
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

        return '<form method="get" action="" class="form-grid">
            <input type="hidden" name="page" value="tax">
            <div class="form-row">
                <label for="tax_ct_period_id">CT period</label>
                <select class="select" id="tax_ct_period_id" name="ct_period_id">' . $this->options($ctPeriods, $selectedCtPeriodId) . '</select>
            </div>
            <div class="actions-row"><button class="button primary" type="submit">Apply</button></div>
        </form>'
        . $this->periodSummary($ctPeriods, $selectedCtPeriodId)
        . '<div class="helper">The accounting period can remain thirteen months, while CT computations and CT600 submission gates are handled per CT period.</div>';
    }

    private function options(array $ctPeriods, int $selectedCtPeriodId): string
    {
        $html = '';
        foreach ($ctPeriods as $period) {
            $id = (int)($period['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $label = 'CT period ' . (int)($period['sequence_no'] ?? 0)
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

            return \eel_accounts\Ui\TaxCardRenderer::summaryGrid([
                ['Selected CT period', 'CT period ' . (int)($period['sequence_no'] ?? 0)],
                ['Start', (string)($period['period_start'] ?? '')],
                ['End', (string)($period['period_end'] ?? '')],
                ['Status', HelperFramework::labelFromKey((string)($period['status'] ?? 'pending'), '_')],
            ]);
        }

        return '';
    }
}
