<?php
declare(strict_types=1);

final class _hmrc_submission_overviewCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_submission_overview'; }
    public function title(): string { return 'Submission Overview'; }
    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $data = (array)($context['hmrc_submission'] ?? []);
        $settings = (array)($data['settings'] ?? []);
        $accounts = (array)($data['accounts_ixbrl'] ?? []);
        $computations = (array)($data['computations_ixbrl'] ?? []);
        $latest = (array)($data['latest_submission'] ?? []);
        $ctPeriods = (array)($data['ct_periods'] ?? []);
        $selectedCtPeriodId = (int)($data['selected_ct_period_id'] ?? 0);

        return '<div class="summary-grid">'
            . $this->metric('Company', (string)($company['name'] ?? ''))
            . $this->metric('Accounting period ID', (string)($company['accounting_period_id'] ?? ''))
            . $this->metric('Selected CT period', $this->selectedCtPeriodLabel($ctPeriods, $selectedCtPeriodId))
            . $this->metric('UTR status', trim((string)($settings['utr'] ?? '')) !== '' ? 'Present' : 'Missing')
            . $this->metric('API mode', (string)($data['mode'] ?? 'TEST'))
            . $this->metric('Accounts iXBRL', !empty($accounts['ok']) ? (string)$accounts['filename'] : 'Missing')
            . $this->metric('Computations iXBRL', !empty($computations['ok']) ? (string)$computations['filename'] : 'Missing')
            . $this->metric('Last submission', (string)($latest['status'] ?? 'None'))
            . '</div>';
    }

    private function metric(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function selectedCtPeriodLabel(array $ctPeriods, int $selectedCtPeriodId): string
    {
        foreach ($ctPeriods as $period) {
            if ((int)($period['id'] ?? 0) !== $selectedCtPeriodId) {
                continue;
            }

            return (string)($period['display_label'] ?? ('CT Period ' . (int)($period['sequence_no'] ?? 0)))
                . ' (' . (string)($period['period_start'] ?? '')
                . ' to ' . (string)($period['period_end'] ?? '') . ')';
        }

        return 'None';
    }
}
