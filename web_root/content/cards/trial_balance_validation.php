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

        if (empty($validation['available'])) {
            return $this->renderErrors((array)($validation['errors'] ?? []));
        }

        $checksHtml = '';
        foreach ((array)($validation['checks'] ?? []) as $check) {
            $status = (string)($check['status'] ?? 'warning');
            $checksHtml .= '<div class="panel-soft">
                <div class="status-head">
                    <h4 class="card-title">' . HelperFramework::escape((string)($check['title'] ?? 'Check')) . '</h4>
                    <span class="badge ' . $this->badgeClass($status) . '">' . HelperFramework::escape($status) . '</span>
                </div>
                <div class="helper">' . HelperFramework::escape((string)($check['detail'] ?? '')) . '</div>
                ' . $this->metricValue($check['metric_value'] ?? null) . '
            </div>';
        }

        return '<div class="settings-stack">' . $checksHtml . '</div>';
    }

    private function metricValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $html = '';
        foreach ($this->metricLines($value) as $line) {
            $html .= '<div><strong>' . HelperFramework::escape($line) . '</strong></div>';
        }

        return $html;
    }

    private function metricLines(mixed $value): array
    {
        if (!is_array($value) || $this->isListArray($value)) {
            return [$this->metricText($value)];
        }

        $lines = [];
        foreach ($value as $key => $metric) {
            $label = HelperFramework::labelFromKey((string)$key, '_');
            $lines[] = $label . ': ' . $this->metricText($metric);
        }

        return $lines;
    }

    private function metricText(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_numeric($value)) {
            return FormattingFramework::money($value);
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
            $parts[] = $label . ': ' . $this->metricText($metric);
        }

        return implode(', ', $parts);
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
