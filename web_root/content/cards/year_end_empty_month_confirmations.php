<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_empty_month_confirmationsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'year_end_empty_month_confirmations';
    }

    public function title(): string
    {
        return 'Empty Month Confirmations';
    }

    public function helper(array $context): string
    {
        return 'Manual confirmations for inferred no-activity months used by Year End readiness.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'emptyMonthConfirmations',
                'service' => \eel_accounts\Service\EmptyMonthConfirmationService::class,
                'method' => 'fetchContext',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['year.end.state'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $data = (array)($context['services']['emptyMonthConfirmations'] ?? []);
        if (empty($data['available'])) {
            return '<section class="panel-soft"><div class="helper">' . HelperFramework::escape((string)($data['errors'][0] ?? 'Empty month confirmations are not available.')) . '</div></section>';
        }

        $months = (array)($data['months'] ?? []);
        if ($months === []) {
            return '<section class="panel-soft"><div class="helper">' . HelperFramework::escape((string)($data['empty_message'] ?? 'No empty-month confirmations are available for this accounting period.')) . '</div></section>';
        }

        $company = (array)($context['company'] ?? []);
        $companySettings = (array)($company['settings'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $html = '<div class="settings-stack">';
        $confirmableMonths = [];

        foreach ($months as $month) {
            if (!is_array($month)) {
                continue;
            }

            if (!empty($month['can_confirm'])) {
                $confirmableMonths[] = $month;
            }

            $html .= $this->renderMonth($month, $companyId, $accountingPeriodId, $companySettings);
        }

        $html .= $this->batchActionHtml($confirmableMonths, $companyId, $accountingPeriodId);

        return $html . '</div>';
    }

    private function renderMonth(array $month, int $companyId, int $accountingPeriodId, array $companySettings): string
    {
        $status = (string)($month['status'] ?? 'not_available');
        $confirmation = (array)($month['confirmation'] ?? []);
        $action = $this->confirmedActionHtml($month, $confirmation, $companyId, $accountingPeriodId);

        return '<div class="panel-soft">
            <h3 class="card-title">' . HelperFramework::escape($this->issueTitle($month)) . '</h3>
            ' . $this->evidenceHtml((array)($month['evidence'] ?? []), $companySettings) . '
        </div>
        ' . $action;
    }

    private function evidenceHtml(array $evidence, array $companySettings): string
    {
        $counts = (array)($evidence['activity_counts'] ?? []);
        $statement = (array)($evidence['first_later_statement'] ?? []);
        $rows = [
            'Basis' => (string)($evidence['confirmation_basis_label'] ?? ''),
            'Transactions' => (string)(int)($counts['transactions'] ?? 0),
            'Raw rows' => (string)(int)($counts['raw_rows'] ?? $counts['uploads'] ?? 0),
            'Posted journals' => (string)(int)($counts['posted_journals'] ?? 0),
        ];

        if ((string)($evidence['confirmation_basis'] ?? '') === 'initial_opening_month') {
            $rows = array_merge(
                ['Incorporation date' => (string)($evidence['incorporation_date'] ?? '')],
                $rows
            );
        }

        if ($statement !== []) {
            $rows['First later statement'] = trim((string)($statement['chosen_txn_date'] ?? '') . ' ' . (string)($statement['original_filename'] ?? ''));
            $rows['Opening balance'] = $this->money($companySettings, $statement['opening_balance'] ?? 0);
        }

        $html = '<div class="summary-grid four">';
        foreach ($rows as $label => $value) {
            if (trim($value) === '') {
                continue;
            }

            $html .= '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
        }

        return $html . '</div>';
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function batchActionHtml(array $months, int $companyId, int $accountingPeriodId): string
    {
        $monthStarts = [];
        $confirmableMonths = [];
        foreach ($months as $month) {
            if (!is_array($month) || empty($month['can_confirm'])) {
                continue;
            }

            $monthStart = (string)($month['month_start'] ?? '');
            if ($monthStart === '') {
                continue;
            }

            $monthStarts[] = $monthStart;
            $confirmableMonths[] = $month;
        }

        if ($monthStarts === []) {
            return '';
        }

        return \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => $this->batchApprovalSubject($confirmableMonths),
            'companyId' => $companyId,
            'accountingPeriodId' => $accountingPeriodId,
            'acknowledged' => false,
            'intent' => 'confirm_empty_months',
            'approveFields' => ['month_start' => $monthStarts],
            'noteName' => 'confirmation_notes',
            'noteId' => 'empty-month-notes-batch',
        ]);
    }

    private function confirmedActionHtml(array $month, array $confirmation, int $companyId, int $accountingPeriodId): string
    {
        $monthStart = (string)($month['month_start'] ?? '');
        $status = (string)($month['status'] ?? '');
        $subject = $this->approvalSubject($month);
        $noteId = 'empty-month-notes-' . str_replace('-', '', $monthStart);

        if ($status === 'confirmed') {
            return \eel_accounts\Renderer\YearEndApprovalRenderer::render([
                'subject' => $subject,
                'companyId' => $companyId,
                'accountingPeriodId' => $accountingPeriodId,
                'acknowledged' => true,
                'acknowledgedAt' => (string)($confirmation['confirmed_at'] ?? ''),
                'acknowledgedBy' => (string)($confirmation['confirmed_by'] ?? ''),
                'note' => (string)($confirmation['notes'] ?? ''),
                'intent' => 'confirm_empty_month',
                'revokeIntent' => 'revoke_empty_month',
                'approveFields' => ['month_start' => $monthStart],
                'revokeFields' => ['month_start' => $monthStart],
                'noteName' => 'confirmation_notes',
                'noteId' => $noteId,
            ]);
        }

        return '';
    }

    private function basisLabel(array $month): string
    {
        $basisLabel = trim((string)($month['basis_label'] ?? ''));
        if ($basisLabel !== '') {
            return $basisLabel;
        }

        return (string)($month['confirmation_basis'] ?? '') === 'initial_opening_month'
            ? 'First-period initial month'
            : 'No-activity month';
    }

    private function approvalSubject(array $month): string
    {
        $monthLabel = $this->shortMonthLabel($month);
        if ($monthLabel === '') {
            $monthLabel = (string)($month['month_label'] ?? 'this month');
        }

        return (string)($month['confirmation_basis'] ?? '') === 'initial_opening_month'
            ? 'first-period initial no activity for ' . $monthLabel
            : 'no financial activity for ' . $monthLabel;
    }

    private function batchApprovalSubject(array $months): string
    {
        $labels = [];
        $basis = [];
        foreach ($months as $month) {
            if (!is_array($month)) {
                continue;
            }

            $label = $this->shortMonthLabel($month);
            if ($label !== '') {
                $labels[] = $label;
            }
            $basis[(string)($month['confirmation_basis'] ?? '')] = true;
        }

        $labelText = $this->joinLabels($labels);
        if ($labelText === '') {
            $labelText = 'the selected month(s)';
        }

        if (count($basis) === 1 && isset($basis['initial_opening_month'])) {
            return 'first-period initial no activity for ' . $labelText;
        }

        if (count($basis) === 1 && isset($basis['no_activity_month'])) {
            return 'no financial activity for ' . $labelText;
        }

        return 'empty-month confirmations for ' . $labelText;
    }

    private function issueTitle(array $month): string
    {
        $monthLabel = $this->shortMonthLabel($month);
        if ($monthLabel === '') {
            $monthLabel = trim((string)($month['month_label'] ?? 'this month'));
        }

        return 'No activity in Month: ' . $monthLabel;
    }

    private function shortMonthLabel(array $month): string
    {
        $monthStart = trim((string)($month['month_start'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $monthStart)) {
            return (new DateTimeImmutable($monthStart))->format('m/Y');
        }

        return trim((string)($month['month_label'] ?? ''));
    }

    private function joinLabels(array $labels): string
    {
        $labels = array_values(array_filter(array_map(static fn (mixed $label): string => trim((string)$label), $labels), static fn (string $label): bool => $label !== ''));
        $count = count($labels);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels) . ' and ' . $last;
    }

    private function statusLabel(string $status, bool $canConfirm): string
    {
        if ($canConfirm && $status === 'available') {
            return 'Needs confirmation';
        }

        return match ($status) {
            'confirmed' => 'Approved',
            'superseded' => 'Superseded',
            'revoked' => 'Revoked',
            default => 'Not available',
        };
    }

    private function badgeClass(string $status, bool $canConfirm): string
    {
        if ($canConfirm && $status === 'available') {
            return 'warning';
        }

        return match ($status) {
            'confirmed' => 'success',
            'superseded' => 'warning',
            'revoked' => 'info',
            default => 'info',
        };
    }
}
