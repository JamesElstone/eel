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
            return '<div class="helper">' . HelperFramework::escape((string)($data['errors'][0] ?? 'Empty month confirmations are not available.')) . '</div>';
        }

        $months = (array)($data['months'] ?? []);
        if ($months === []) {
            return '<div class="helper">No first-month empty activity confirmations are available for this accounting period.</div>';
        }

        $company = (array)($context['company'] ?? []);
        $companySettings = (array)($company['settings'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $html = '<div class="settings-stack">';

        foreach ($months as $month) {
            if (!is_array($month)) {
                continue;
            }

            $html .= $this->renderMonth($month, $companyId, $accountingPeriodId, $companySettings);
        }

        return $html . '</div>';
    }

    private function renderMonth(array $month, int $companyId, int $accountingPeriodId, array $companySettings): string
    {
        $status = (string)($month['status'] ?? 'not_available');
        $confirmation = (array)($month['confirmation'] ?? []);
        $badgeLabel = $this->statusLabel($status, !empty($month['can_confirm']));
        $action = $this->actionHtml($month, $confirmation, $companyId, $accountingPeriodId);

        return '<section class="settings-stack">
            <div class="status-head">
                <h3 class="card-title">' . HelperFramework::escape((string)($month['month_label'] ?? '')) . '</h3>
                <span class="badge ' . HelperFramework::escape($this->badgeClass($status, !empty($month['can_confirm']))) . '">' . HelperFramework::escape($badgeLabel) . '</span>
            </div>
            <div class="helper">' . HelperFramework::escape((string)($month['reason'] ?? '')) . '</div>
            ' . $this->evidenceHtml((array)($month['evidence'] ?? []), $companySettings) . '
            ' . $action . '
        </section>';
    }

    private function evidenceHtml(array $evidence, array $companySettings): string
    {
        $counts = (array)($evidence['activity_counts'] ?? []);
        $statement = (array)($evidence['first_later_statement'] ?? []);
        $rows = [
            'Incorporation date' => (string)($evidence['incorporation_date'] ?? ''),
            'Transactions' => (string)(int)($counts['transactions'] ?? 0),
            'Uploads' => (string)(int)($counts['uploads'] ?? 0),
            'Posted journals' => (string)(int)($counts['posted_journals'] ?? 0),
        ];

        if ($statement !== []) {
            $rows['First later statement'] = trim((string)($statement['chosen_txn_date'] ?? '') . ' ' . (string)($statement['original_filename'] ?? ''));
            $rows['Opening balance'] = $this->money($companySettings, $statement['opening_balance'] ?? 0);
        }

        $html = '<div class="summary-grid">';
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

    private function actionHtml(array $month, array $confirmation, int $companyId, int $accountingPeriodId): string
    {
        $monthStart = (string)($month['month_start'] ?? '');
        $confirmedAt = trim((string)($confirmation['confirmed_at'] ?? ''));
        $confirmedBy = trim((string)($confirmation['confirmed_by'] ?? ''));
        $notes = trim((string)($confirmation['notes'] ?? ''));
        $canConfirm = !empty($month['can_confirm']);
        $status = (string)($month['status'] ?? '');

        if ($canConfirm) {
            return '<form method="post" data-ajax="true" class="form-grid">
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="confirm_empty_month">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="month_start" value="' . HelperFramework::escape($monthStart) . '">
                <div class="form-row full">
                    <label for="empty-month-notes-' . HelperFramework::escape(str_replace('-', '', $monthStart)) . '">Confirmation notes</label>
                    <textarea class="input" id="empty-month-notes-' . HelperFramework::escape(str_replace('-', '', $monthStart)) . '" name="confirmation_notes" rows="3"></textarea>
                </div>
                <div class="actions-row"><button class="button primary" type="submit">Confirm no financial activity</button></div>
            </form>';
        }

        if ($status === 'confirmed') {
            $confirmationFoot = $this->confirmationFoot($confirmedAt, $confirmedBy);

            return '<section class="panel-soft settings-stack">
                <div class="eyebrow">Acknowledgement</div>
                ' . ($notes !== '' ? '<div class="stat-value">' . HelperFramework::escape($notes) . '</div>' : '') . '
                ' . ($confirmationFoot !== '' ? '<div class="stat-foot">' . HelperFramework::escape($confirmationFoot) . '</div>' : '') . '
                <div class="actions-row">
                    <form method="post" data-ajax="true">
                        <input type="hidden" name="card_action" value="YearEnd">
                        <input type="hidden" name="intent" value="revoke_empty_month">
                        <input type="hidden" name="company_id" value="' . $companyId . '">
                        <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                        <input type="hidden" name="month_start" value="' . HelperFramework::escape($monthStart) . '">
                        <button class="button" type="submit">Revoke confirmation</button>
                    </form>
                </div>
            </section>';
        }

        return '';
    }

    private function confirmationFoot(string $confirmedAt, string $confirmedBy): string
    {
        if ($confirmedAt === '' && $confirmedBy === '') {
            return '';
        }

        return 'Confirmed'
            . ($confirmedAt !== '' ? ' at ' . $confirmedAt : '')
            . ($confirmedBy !== '' ? ' by ' . $confirmedBy : '')
            . '.';
    }

    private function statusLabel(string $status, bool $canConfirm): string
    {
        if ($canConfirm && $status === 'available') {
            return 'Needs confirmation';
        }

        return match ($status) {
            'confirmed' => 'Confirmed',
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
