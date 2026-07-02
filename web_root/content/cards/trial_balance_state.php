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

        return '<div id="trial-balance-app" class="settings-stack">
            ' . $this->renderSummaryPanel($summary, (string)($validation['ready_for_ct_working_papers'] ?? 'Not ready')) . '
        </div>';
    }

    private function renderSummaryPanel(array $summary, string $readiness): string
    {
        $status = (array)($summary['trial_balance_status'] ?? []);

        return '<div>
            <div class="summary-grid" style="grid-template-columns: repeat(5, minmax(0, 1fr));">
                ' . $this->readinessGaugeCard($readiness) . '
                ' . $this->summaryCard('Trial Balance status', '<span class="badge ' . (!empty($status['is_balanced']) ? 'success' : 'danger') . '">' . HelperFramework::escape((string)($status['label'] ?? 'Not balanced')) . '</span>', true) . '
                ' . $this->summaryCard('Profit before tax', FormattingFramework::money($summary['profit_before_tax'] ?? 0)) . '
                ' . $this->summaryCard('Net assets', FormattingFramework::money($summary['net_assets'] ?? 0)) . '
                ' . $this->summaryCard('Solvency flag', $this->solvencyFlag($summary['net_assets'] ?? 0), true) . '
                ' . $this->summaryCard('Bank balance total', FormattingFramework::money($summary['bank_balance_total'] ?? 0)) . '
                ' . $this->summaryCard('Director loan balance', FormattingFramework::money($summary['director_loan_balance'] ?? 0)) . '
                ' . $this->summaryCard('VAT control balance', FormattingFramework::money($summary['vat_control_balance'] ?? 0)) . '
                ' . $this->summaryCard('Uncategorised / suspense', FormattingFramework::money($summary['uncategorised_exposure'] ?? 0)) . '
                ' . $this->summaryCard('Corporation tax nominal', FormattingFramework::money($summary['corporation_tax_balance'] ?? 0)) . '
            </div>
        </div>';
    }

    private function readinessGaugeCard(string $readiness): string
    {
        $score = $this->readinessScore($readiness);
        $chart = (new ChartService())->gauge($score, [
            'title' => 'Trial balance readiness',
            'label' => $readiness,
            'color' => $this->readinessColor($score),
            'width' => 220,
            'height' => 160,
        ]);

        return $this->summaryCard('Readiness', $chart, true);
    }

    private function readinessScore(string $readiness): int
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

    private function summaryCard(string $label, string $value, bool $trustedValue = false): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . ($trustedValue ? $value : HelperFramework::escape($value)) . '</div></div>';
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
