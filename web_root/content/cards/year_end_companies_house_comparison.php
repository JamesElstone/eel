<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_companies_house_comparisonCard extends CardBaseFramework
{
    private const CHECK_CODE = 'companies_house_mismatch_acknowledgement';

    public function key(): string
    {
        return 'year_end_companies_house_comparison';
    }

    public function title(): string
    {
        return 'Year End Companies House Comparison';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context', 'year.end.checklist'];
    }

    public function services(): array
    {
        return [
            [
                'key' => 'yearEndCompaniesHouseComparison',
                'service' => \eel_accounts\Service\YearEndCompaniesHouseComparisonService::class,
                'method' => 'fetchComparison',
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
        $comparison = (array)($context['services']['yearEndCompaniesHouseComparison'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $companySettings = (array)($company['settings'] ?? []);
        $acknowledgement = $this->acknowledgement($context);
        $mismatchCount = $this->mismatchCount($comparison);

        return '<section class="settings-stack" id="year-end-companies-house-comparison">
            ' . $this->renderComparisonPanel($comparison, $companySettings) . '
            ' . $this->renderAcknowledgementPanel($companyId, $accountingPeriodId, $comparison, $acknowledgement, $mismatchCount) . '
        </section>';
    }

    private function renderComparisonPanel(array $comparison, array $companySettings): string
    {
        if (empty($comparison['available'])) {
            return $this->panel('Companies House Comparison', $this->renderErrors((array)($comparison['errors'] ?? ['No Companies House comparison is available.'])));
        }

        $rowsHtml = '';
        foreach ((array)($comparison['rows'] ?? []) as $row) {
            $status = (string)($row['status'] ?? '');
            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['label'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($this->nullableMoney($companySettings, $row['app_value'] ?? null)) . '</td>
                <td>' . HelperFramework::escape($this->nullableMoney($companySettings, $row['filed_value'] ?? null)) . '</td>
                <td>' . HelperFramework::escape($this->nullableMoney($companySettings, $row['variance'] ?? null)) . '</td>
                <td><span class="badge ' . $this->badgeClass($status) . '">' . HelperFramework::escape(HelperFramework::labelFromKey($status, '_')) . '</span></td>
            </tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="5">No Companies House comparison rows were found for this accounting period.</td></tr>';
        }

        return '<section class="panel-soft" id="companies-house-comparison">
            <div class="status-head"><h3 class="card-title">Companies House Comparison</h3></div>
            <div class="helper">' . HelperFramework::escape((string)($comparison['comparison_note'] ?? '')) . '</div>
            <div class="helper">Stored filing date: ' . HelperFramework::escape((string)($comparison['filing']['filing_date'] ?? '')) . '</div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Metric</th><th>App</th><th>Filed</th><th>Variance</th><th>Status</th></tr></thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>
        </section>';
    }

    private function renderAcknowledgementPanel(int $companyId, int $accountingPeriodId, array $comparison, ?array $acknowledgement, int $mismatchCount): string
    {
        if (empty($comparison['available'])) {
            return $this->panel('Year End Acknowledgement', '<div class="helper">A Companies House filing must be available before a mismatch can be acknowledged.</div>');
        }

        if ($mismatchCount <= 0) {
            return $this->panel('Year End Acknowledgement', '<div class="helper">No Companies House mismatch acknowledgement is needed for this accounting period.</div>');
        }

        $isAcknowledged = is_array($acknowledgement);
        $note = $isAcknowledged ? trim((string)($acknowledgement['note'] ?? '')) : '';
        $form = $isAcknowledged
            ? '<section class="panel-soft success settings-stack">
                <div class="eyebrow">Acknowledgement</div>
                ' . ($note !== '' ? '<div class="summary-value">' . HelperFramework::escape($note) . '</div>' : '') . '
                <div class="stat-foot">' . HelperFramework::escape($this->confirmationFoot((string)($acknowledgement['acknowledged_at'] ?? ''), (string)($acknowledgement['acknowledged_by'] ?? ''))) . '</div>
                <form method="post" data-ajax="true">
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="reopen_review_check">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="check_code" value="' . HelperFramework::escape(self::CHECK_CODE) . '">
                <button class="button" type="submit">Revoke acknowledgement</button>
            </form>
            </section>'
            : '<section class="panel-soft warn full settings-stack">
                <div class="eyebrow">Acknowledgement</div>
                <div class="helper">This acknowledgement clears the Year End checklist only. HMRC/iXBRL submission remains blocked until the Companies House filing is corrected and refreshed.</div>
                <form method="post" data-ajax="true" class="form-grid" data-year-end-ack-form="true">
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="acknowledge_review_check">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="check_code" value="' . HelperFramework::escape(self::CHECK_CODE) . '">
                <label class="checkbox-row full">
                    <input type="checkbox" name="companies_house_mismatch_acknowledgement" value="1" required data-year-end-ack-checkbox>
                    <span>I acknowledge the stored Companies House filing differs from the reviewed app figures and will be corrected before HMRC submission.</span>
                </label>
                <div class="form-row full">
                    <label for="companies-house-mismatch-note">Correction plan/reference</label>
                    <textarea class="input" id="companies-house-mismatch-note" name="review_acknowledgement_note" rows="3"></textarea>
                </div>
                <div class="actions-row"><button class="button primary" type="submit" disabled data-year-end-ack-submit>Save acknowledgement</button></div>
            </form>
            </section>';

        return '<section class="settings-stack" id="companies-house-mismatch-acknowledgement">
            <div class="status-head">
                <h3 class="card-title">Year End Acknowledgement</h3>
                <span class="badge ' . HelperFramework::escape($isAcknowledged ? 'success' : 'warning') . '">' . HelperFramework::escape($isAcknowledged ? 'Acknowledged' : 'Acknowledgement pending') . '</span>
            </div>
            ' . $form . '
        </section>';
    }

    private function confirmationFoot(string $acknowledgedAt, string $acknowledgedBy): string
    {
        $acknowledgedAt = trim($acknowledgedAt);
        $acknowledgedBy = trim($acknowledgedBy);

        return 'Confirmed'
            . ($acknowledgedAt !== '' ? ' at ' . $acknowledgedAt : '')
            . ($acknowledgedBy !== '' ? ' by ' . $acknowledgedBy : '')
            . '.';
    }

    private function acknowledgement(array $context): ?array
    {
        $acknowledgement = ((array)(($context['year_end'] ?? [])['checklist'] ?? []))['review_acknowledgements'][self::CHECK_CODE] ?? null;
        return is_array($acknowledgement) ? $acknowledgement : null;
    }

    private function mismatchCount(array $comparison): int
    {
        $count = 0;
        foreach ((array)($comparison['rows'] ?? []) as $row) {
            if (is_array($row) && (string)($row['status'] ?? '') === 'fail') {
                $count++;
            }
        }

        return $count;
    }

    private function panel(string $title, string $body): string
    {
        return '<section class="panel-soft"><div class="status-head"><h3 class="card-title">' . HelperFramework::escape($title) . '</h3></div>' . $body . '</section>';
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

    private function nullableMoney(array $companySettings, mixed $value): string
    {
        return $value === null || $value === '' ? '-' : $this->money($companySettings, $value);
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
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
