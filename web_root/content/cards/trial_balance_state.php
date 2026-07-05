<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _trial_balance_stateCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'trial_balance_state';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'trialBalancePageData',
                'service' => \eel_accounts\Service\TrialBalanceService::class,
                'method' => 'fetchTrialBalance',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'includeZero' => false,
                    'includeUnposted' => false,
                    'filters' => [],
                ],
            ],
            [
                'key' => 'trialBalanceValidation',
                'service' => \eel_accounts\Service\TrialBalanceValidationService::class,
                'method' => 'fetchValidation',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Trial Balance';
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $trialBalance = (array)($context['services']['trialBalancePageData'] ?? []);
        if (empty($trialBalance['available'])) {
            return $this->renderErrors((array)($trialBalance['errors'] ?? ['Trial balance is not available for the selected period.']));
        }

        $validation = (array)($context['services']['trialBalanceValidation'] ?? []);
        $summary = (array)($trialBalance['summary'] ?? []);
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);

        return '<div id="trial-balance-app" class="settings-stack">
            ' . $this->renderSummaryPanel($summary, $validation, $companySettings) . '
        </div>';
    }

    private function renderSummaryPanel(array $summary, array $validation, array $companySettings): string
    {
        $status = (array)($summary['trial_balance_status'] ?? []);

        return '<div class="trial-balance-summary-layout">
            <div>' . $this->readinessGaugeCard($validation) . '</div>
            <div class="summary-grid trial-balance-summary-grid">
                ' . $this->readinessDriverCards($validation, $companySettings) . '
                ' . $this->summaryCard('Trial Balance status', '<span class="badge ' . (!empty($status['is_balanced']) ? 'success' : 'danger') . '">' . HelperFramework::escape((string)($status['label'] ?? 'Not balanced')) . '</span>', true) . '
                ' . $this->summaryCard('Profit before tax', $this->money($companySettings, $summary['profit_before_tax'] ?? 0)) . '
                ' . $this->summaryCard('Net assets', $this->money($companySettings, $summary['net_assets'] ?? 0)) . '
                ' . $this->summaryCard('Solvency flag', $this->solvencyFlag($summary['net_assets'] ?? 0), true) . '
                ' . $this->summaryCard('Bank balance total', $this->money($companySettings, $summary['bank_balance_total'] ?? 0)) . '
                ' . $this->summaryCard('Director loan balance', $this->money($companySettings, $summary['director_loan_balance'] ?? 0)) . '
                ' . $this->summaryCard('VAT control balance', $this->money($companySettings, $summary['vat_control_balance'] ?? 0)) . '
                ' . $this->summaryCard('Uncategorised / suspense', $this->money($companySettings, $summary['uncategorised_exposure'] ?? 0)) . '
                ' . $this->summaryCard('Corporation tax nominal', $this->money($companySettings, $summary['corporation_tax_balance'] ?? 0)) . '
            </div>
        </div>';
    }

    private function readinessGaugeCard(array $validation): string
    {
        $readiness = (string)($validation['ready_for_ct_working_papers'] ?? 'Not ready');
        $score = $this->readinessScore($validation, $readiness);
        $chart = (new ChartService())->gauge($score, [
            'title' => 'Trial balance readiness',
            'label' => $readiness,
            'color' => $this->readinessColor($score),
            'width' => 220,
            'height' => 160,
        ]);

        return $this->summaryCard('Readiness', $chart, true);
    }

    private function readinessDriverCards(array $validation, array $companySettings): string
    {
        $checks = $this->checksByCode($validation);
        $uncategorisedMetrics = (array)($checks['uncategorised_transactions']['metric_value'] ?? []);
        $bankMetrics = (array)($checks['bank_ledger_reasonableness']['metric_value'] ?? []);
        $periodTiles = (array)($checks['period_completeness']['metric_value'] ?? []);
        $deferredTaxMetrics = (array)($checks['frs105_deferred_tax_nominal']['metric_value'] ?? []);

        return ''
            . $this->summaryCard('Posted ledger', $this->yesNoBadge(!empty($validation['has_posted_ledger'])), true)
            . $this->checkSummaryCard('TB equality', $checks['trial_balance_equality'] ?? [], $this->money($companySettings, $checks['trial_balance_equality']['metric_value'] ?? 0))
            . $this->checkSummaryCard('Uncategorised txns', $checks['uncategorised_transactions'] ?? [], (string)(int)($uncategorisedMetrics['uncategorised_transactions'] ?? 0))
            . $this->checkSummaryCard('Missing posting routes', $checks['uncategorised_transactions'] ?? [], (string)(int)($uncategorisedMetrics['missing_posting_routes'] ?? 0))
            . $this->checkSummaryCard('Suspense exposure', $checks['suspense_check'] ?? [], $this->money($companySettings, $checks['suspense_check']['metric_value'] ?? 0))
            . $this->checkSummaryCard('Unposted journals', $checks['unposted_journals'] ?? [], (string)(int)($checks['unposted_journals']['metric_value'] ?? 0))
            . $this->checkSummaryCard('Bank ledger diff', $checks['bank_ledger_reasonableness'] ?? [], $this->money($companySettings, $bankMetrics['difference'] ?? 0))
            . $this->checkSummaryCard('Period completeness', $checks['period_completeness'] ?? [], $this->greenMonthCount($periodTiles))
            . $this->checkSummaryCard('FRS 105 deferred tax', $checks['frs105_deferred_tax_nominal'] ?? [], (string)(int)($deferredTaxMetrics['deferred_tax_nominal_count'] ?? 0))
            . $this->summaryCard('Review notes', $this->yesNoBadge(!empty($validation['review_warnings_acknowledged']), 'Acknowledged', 'Needed'), true)
            . $this->summaryCard('TB comparison diffs', HelperFramework::escape((string)(int)($validation['comparison_differences'] ?? 0)) . ' ' . $this->statusBadge(((int)($validation['comparison_differences'] ?? 0) === 0) ? 'pass' : 'warning'), true);
    }

    private function checksByCode(array $validation): array
    {
        $byCode = [];
        foreach ((array)($validation['checks'] ?? []) as $check) {
            if (!is_array($check)) {
                continue;
            }

            $code = (string)($check['code'] ?? '');
            if ($code !== '') {
                $byCode[$code] = $check;
            }
        }

        return $byCode;
    }

    private function checkSummaryCard(string $label, array $check, string $value): string
    {
        $status = (string)($check['status'] ?? '');
        if ($status === '') {
            return $this->summaryCard($label, $value);
        }

        return $this->summaryCard($label, HelperFramework::escape($value) . ' ' . $this->statusBadge($status), true);
    }

    private function readinessScore(array $validation, string $readiness): int
    {
        $checks = array_values(array_filter(
            (array)($validation['checks'] ?? []),
            fn(mixed $check): bool => is_array($check) && $this->isActionableReadinessCheck($check)
        ));

        if ($checks === []) {
            return $this->readinessLabelScore($readiness);
        }

        $passed = count(array_filter(
            $checks,
            static fn(array $check): bool => in_array((string)($check['status'] ?? ''), ['pass', 'success', 'matches'], true)
        ));
        $score = (int)round(($passed / count($checks)) * 100);
        $labelScore = $this->readinessLabelScore($readiness);
        if ($score >= 100 && $labelScore < 100) {
            return $labelScore;
        }

        return $score;
    }

    private function isActionableReadinessCheck(array $check): bool
    {
        $code = strtolower((string)($check['code'] ?? ''));
        $title = strtolower((string)($check['title'] ?? ''));

        return !str_contains($code, 'solvency') && !str_contains($title, 'solvency');
    }

    private function readinessLabelScore(string $readiness): int
    {
        $normalised = strtolower(trim($readiness));

        if (str_contains($normalised, 'ready for ct')) {
            return 100;
        }

        if (str_contains($normalised, 'nearly ready')) {
            return 70;
        }

        return 0;
    }

    private function readinessColor(int $score): string
    {
        return match (true) {
            $score >= 100 => '#16a34a',
            $score > 0 => '#d97706',
            default => '#dc2626',
        };
    }

    private function yesNoBadge(bool $value, string $yes = 'Yes', string $no = 'No'): string
    {
        return '<span class="badge ' . ($value ? 'success' : 'warning') . '">' . HelperFramework::escape($value ? $yes : $no) . '</span>';
    }

    private function statusBadge(string $status): string
    {
        $class = match ($status) {
            'pass', 'success', 'matches' => 'success',
            'fail', 'danger', 'differs' => 'danger',
            'warning' => 'warning',
            default => 'info',
        };

        return '<span class="badge ' . $class . '">' . HelperFramework::escape($status) . '</span>';
    }

    private function greenMonthCount(array $monthTiles): string
    {
        if ($monthTiles === []) {
            return '0 / 0';
        }

        $green = count(array_filter($monthTiles, static fn(array $tile): bool => (string)($tile['status'] ?? '') === 'green'));

        return $green . ' / ' . count($monthTiles);
    }

    private function summaryCard(string $label, string $value, bool $trustedValue = false): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . ($trustedValue ? $value : HelperFramework::escape($value)) . '</div></div>';
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function solvencyFlag(mixed $netAssets): string
    {
        $potentiallyInsolvent = (float)$netAssets < 0.0;
        $class = $potentiallyInsolvent ? 'danger' : 'success';
        $label = $potentiallyInsolvent ? 'Potentially Insolvent' : 'OK';

        return '<span class="badge ' . $class . '">' . HelperFramework::escape($label) . '</span>';
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
