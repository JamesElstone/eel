<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _trial_balance_validationCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'trial_balance_validation';
    }

    public function title(): string
    {
        return 'Trial Balance Validation';
    }

    public function services(): array
    {
        return [
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

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $validation = (array)($context['services']['trialBalanceValidation'] ?? []);
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);

        if (empty($validation['available'])) {
            return $this->renderErrors((array)($validation['errors'] ?? []));
        }

        $checks = (array)($validation['checks'] ?? []);
        $summaryHtml = $this->renderSummaryCards($checks);
        $checksHtml = '';
        foreach ($checks as $check) {
            $status = (string)($check['status'] ?? 'warning');
            $checksHtml .= '<div class="panel-soft">
                <div class="status-head">
                    <h4 class="card-title">' . HelperFramework::escape((string)($check['title'] ?? 'Check')) . '</h4>
                    <span class="badge ' . $this->badgeClass($status) . '">' . HelperFramework::escape($status) . '</span>
                </div>
                <div class="helper">' . HelperFramework::escape((string)($check['detail'] ?? '')) . '</div>
                ' . $this->metricValue($check['metric_value'] ?? null, $companySettings) . '
            </div>';
        }

        return '<div class="settings-stack">' . $summaryHtml . $checksHtml . '</div>';
    }

    private function renderSummaryCards(array $checks): string
    {
        $total = count($checks);
        $okCount = 0;
        $warningCount = 0;
        $failCount = 0;

        foreach ($checks as $check) {
            $statusClass = $this->badgeClass((string)($check['status'] ?? 'warning'));
            if ($statusClass === 'success') {
                $okCount++;
            } elseif ($statusClass === 'danger') {
                $failCount++;
            } else {
                $warningCount++;
            }
        }

        $percentOk = $total > 0 ? (int)round(($okCount / $total) * 100) : 0;
        $overallClass = $failCount > 0 ? 'danger' : ($warningCount > 0 ? 'warning' : 'success');
        $overallLabel = match ($overallClass) {
            'success' => 'OK',
            'warning' => 'Warnings',
            default => 'Blocked',
        };
        $overallDetail = $total > 0
            ? $okCount . ' of ' . $total . ' checks OK'
                . ($warningCount > 0 ? ', ' . $warningCount . ' warning' . ($warningCount === 1 ? '' : 's') : '')
                . ($failCount > 0 ? ', ' . $failCount . ' failing' : '')
                . '.'
            : 'No validation checks were returned.';
        $overallValue = '<div class="trial-balance-validation-status">
            <div class="trial-balance-validation-status-main">
                <span class="badge ' . $overallClass . '">' . HelperFramework::escape($overallLabel) . '</span>
                <span class="trial-balance-validation-percent">' . HelperFramework::escape($percentOk . '% ready') . '</span>
            </div>
        </div>';

        return '<section class="summary-grid trial-balance-validation-summary">
            ' . $this->summaryCard('Overall status', $overallValue, $overallDetail, true) . '
        </section>';
    }

    private function summaryCard(string $label, string $value, string $helper = '', bool $trustedValue = false): string
    {
        return '<div class="summary-card">
            <div class="summary-label">' . HelperFramework::escape($label) . '</div>
            <div class="summary-value">' . ($trustedValue ? $value : HelperFramework::escape($value)) . '</div>
            ' . ($helper !== '' ? '<div class="helper">' . HelperFramework::escape($helper) . '</div>' : '') . '
        </div>';
    }

    private function metricValue(mixed $value, array $companySettings): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $html = '';
        foreach ($this->metricLines($value, $companySettings) as $line) {
            $html .= '<div><strong>' . HelperFramework::escape($line) . '</strong></div>';
        }

        return $html;
    }

    private function metricLines(mixed $value, array $companySettings): array
    {
        if (!is_array($value) || $this->isListArray($value)) {
            return [$this->metricText($value, $companySettings)];
        }

        $lines = [];
        foreach ($value as $key => $metric) {
            $label = HelperFramework::labelFromKey((string)$key, '_');
            $lines[] = $label . ': ' . $this->metricText($metric, $companySettings);
        }

        return $lines;
    }

    private function metricText(mixed $value, array $companySettings): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_numeric($value)) {
            return $this->money($companySettings, $value);
        }

        if (!is_array($value)) {
            return (string)$value;
        }

        if ($this->isListArray($value)) {
            return count($value) . ' item' . (count($value) === 1 ? '' : 's');
        }

        $parts = [];
        foreach ($value as $key => $metric) {
            $label = HelperFramework::labelFromKey((string)$key, '_');
            $parts[] = $label . ': ' . $this->metricText($metric, $companySettings);
        }

        return implode(', ', $parts);
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function isListArray(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'pass', 'success', 'matches' => 'success',
            'fail', 'danger', 'differs' => 'danger',
            'warning' => 'warning',
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
