<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_profit_loss_confirmCard extends CardBaseFramework
{
    private ?\eel_accounts\Service\CompanySettingsService $companySettingsService = null;

    public function key(): string
    {
        return 'year_end_profit_loss_confirm';
    }

    public function title(): string
    {
        return 'Profit & Loss Confirmation';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'yearEndProfitLossConfirm',
                'service' => \eel_accounts\Service\RetainedEarningsCloseService::class,
                'method' => 'fetchContext',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'corporationTaxProvision' => ':profit_loss.summary.corporation_tax_provision',
                    'depreciationPreview' => ':profit_loss.summary.depreciation_preview',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['year.end.state', 'year.end.checklist', 'year.end.retained.earnings'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $close = (array)($context['services']['yearEndProfitLossConfirm'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $companySettings = (array)($company['settings'] ?? []);
        $accountingPeriod = (array)($close['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));

        if (empty($close['available'])) {
            return '<section class="settings-stack" id="year-end-profit-loss-confirm">' . $this->renderErrors((array)($close['errors'] ?? ['Profit & Loss confirmation is not available.'])) . '</section>';
        }

        $summary = (array)($close['summary'] ?? []);
        $depreciationPreview = (array)($close['depreciation_preview'] ?? []);
        $pendingDepreciationAmount = !empty($depreciationPreview['success']) ? (float)($depreciationPreview['total_amount'] ?? 0) : 0.0;
        $pendingDepreciationCount = !empty($depreciationPreview['success']) ? (int)($depreciationPreview['created'] ?? 0) : 0;
        $pendingDepreciationHtml = $pendingDepreciationCount > 0 && abs($pendingDepreciationAmount) >= 0.005
            ? '<div class="helper">These figures include ' . HelperFramework::escape((string)$pendingDepreciationCount) . ' pending depreciation posting(s) totalling ' . HelperFramework::escape($this->money($companySettings, $pendingDepreciationAmount)) . ', which will be posted automatically when Year End is locked.</div>'
            : '';
        $pendingPrepaymentAmount = (float)($summary['prepayment_expense_adjustment'] ?? 0);
        $pendingPrepaymentHtml = abs($pendingPrepaymentAmount) >= 0.005
            ? '<div class="helper">These figures include a pending prepayment expense adjustment of ' . HelperFramework::escape($this->money($companySettings, $pendingPrepaymentAmount)) . '.</div>'
            : '';
        $acknowledged = !empty($close['acknowledged']);
        $stale = !empty($close['acknowledgement_stale']);
        $journalLinesHtml = $this->journalLinesHtml((array)($close['journal_lines'] ?? []), $companySettings);
        $existingJournal = (array)($close['existing_journal'] ?? []);
        $existingHtml = $existingJournal !== []
            ? '<div class="helper">A retained earnings close journal already exists for this period and will be replaced from these agreed figures when the period is locked.</div>'
            : '';
        $staleHtml = $stale
            ? '<div class="helper">Figures have changed since the last agreement. Review them and agree again before locking.</div>'
            : '';
        $acknowledgement = (array)($close['acknowledgement'] ?? []);
        $reserveReview = (array)($close['reserve_review'] ?? []);
        $reserveReviewCurrent = !empty($reserveReview['snapshot_current']);
        $canAcknowledge = !empty($close['can_acknowledge']);
        $blockedReason = (string)(($close['prior_period_dependency'] ?? [])['detail'] ?? '');
        $dependencyHtml = '';
        foreach ((array)($close['warnings'] ?? []) as $warning) {
            $dependencyHtml .= '<div class="helper"><span class="badge warning">Prior period</span> ' . HelperFramework::escape((string)$warning) . '</div>';
        }
        $acknowledgementForm = $this->acknowledgementHtml(
            $acknowledged && !$stale,
            (string)($close['acknowledgement_state'] ?? 'absent'),
            (string)($acknowledgement['acknowledged_at'] ?? ''),
            (string)($acknowledgement['acknowledged_by'] ?? ''),
            (string)($acknowledgement['note'] ?? ''),
            $companyId,
            $accountingPeriodId,
            $canAcknowledge,
            $blockedReason
        );

        $reserveReviewHtml = $reserveReviewCurrent
            ? '<div class="helper"><span class="badge success">Distributable Profit Review included</span> The approved Profit & Loss basis includes the current reserve classifications as at ' . HelperFramework::escape((string)($reserveReview['as_at_date'] ?? '-')) . '.</div>'
            : '<div class="helper"><span class="badge warning">Distributable Profit Review will be captured</span> Review the reserve classifications shown above. They will be saved as part of this combined Profit & Loss approval.</div>';

        return '<section class="settings-stack" id="year-end-profit-loss-confirm">
            <div class="helper">When the period is locked, the app will carry current profit/loss into 3000 Retained Earnings and reset income and expense nominal balances for the next period (clear them). Original transactions, expense claims, and source journals are not changed.</div>
            <div class="month-grid">
                ' . $this->summaryCard('Opening equity', $this->money($companySettings, $summary['opening_equity'] ?? 0)) . '
                ' . $this->summaryCard('Current profit / loss', $this->money($companySettings, $summary['current_profit_loss'] ?? 0)) . '
                ' . $this->summaryCard('Direct equity movements', $this->money($companySettings, $summary['direct_equity_movement'] ?? 0)) . '
                ' . $this->summaryCard('Share capital movement', $this->money($companySettings, $summary['share_capital_movement'] ?? 0)) . '
                ' . $this->summaryCard('Closing equity before close', $this->money($companySettings, $summary['closing_equity_before_close'] ?? 0)) . '
                ' . $this->summaryCard('Retained earnings movement', $this->money($companySettings, $summary['retained_earnings_movement'] ?? 0)) . '
            </div>
            <div class="helper">' . HelperFramework::escape($this->balanceEquation($companySettings, $summary)) . '</div>
            ' . $dependencyHtml . $reserveReviewHtml . $pendingDepreciationHtml . $pendingPrepaymentHtml . $staleHtml . $existingHtml . '
            <div class="table-scroll panel-soft">
                <table>
                    <thead><tr><th>Nominal</th><th>Description</th><th>Debit</th><th>Credit</th></tr></thead>
                    <tbody>' . $journalLinesHtml . '</tbody>
                </table>
            </div>
            ' . $acknowledgementForm . '
        </section>';
    }

    private function acknowledgementHtml(bool $acknowledged, string $state, string $acknowledgedAt, string $acknowledgedBy, string $note, int $companyId, int $accountingPeriodId, bool $canAcknowledge, string $blockedReason): string
    {
        return \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'profit and loss close, including the distributable profit review',
            'companyId' => $companyId,
            'accountingPeriodId' => $accountingPeriodId,
            'acknowledged' => $acknowledged,
            'acknowledgementState' => $state,
            'acknowledgedAt' => $acknowledgedAt,
            'acknowledgedBy' => $acknowledgedBy,
            'note' => $note,
            'disabled' => !$canAcknowledge,
            'disabledReason' => !$canAcknowledge ? $blockedReason : '',
            'intent' => 'save_retained_earnings_close_acknowledgement',
            'revokeIntent' => 'save_retained_earnings_close_acknowledgement',
            'checkboxName' => 'retained_earnings_close_acknowledgement',
            'approveFields' => ['retained_earnings_close_acknowledgement' => '1'],
            'revokeFields' => ['retained_earnings_close_acknowledgement' => '0'],
        ]);
    }

    private function journalLinesHtml(array $journalLines, array $companySettings): string
    {
        if ($journalLines === []) {
            return '<tr><td colspan="4">No retained earnings close journal is needed for the current figures.</td></tr>';
        }

        $html = '';
        foreach ($journalLines as $line) {
            $nominal = trim((string)($line['nominal_code'] ?? '') . ' ' . (string)($line['nominal_name'] ?? ''));
            if ($nominal === '') {
                $nominalId = (int)($line['nominal_account_id'] ?? 0);
                $nominal = $nominalId > 0 ? 'Nominal ' . $nominalId : '-';
            }

            $html .= '<tr>
                <td>' . HelperFramework::escape($nominal) . '</td>
                <td>' . HelperFramework::escape((string)($line['line_description'] ?? '')) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($companySettings, $line['debit'] ?? 0)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($companySettings, $line['credit'] ?? 0)) . '</td>
            </tr>';
        }

        return $html;
    }

    private function summaryCard(string $label, string $value): string
    {
        return '<div class="panel-soft"><div class="eyebrow">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function balanceEquation(array $companySettings, array $summary): string
    {
        $netAssets = round((float)($summary['assets'] ?? 0) - (float)($summary['liabilities'] ?? 0), 2);
        $equity = round((float)($summary['equity'] ?? 0), 2);
        $difference = round((float)($summary['balance_equation_difference'] ?? ($netAssets - $equity)), 2);
        if (!empty($summary['is_balance_sheet_balanced']) || abs($difference) < 0.005) {
            return 'Net assets (' . $this->money($companySettings, $netAssets) . ') agree to equity ('
                . $this->money($companySettings, $equity) . ').';
        }

        return 'Net assets (' . $this->money($companySettings, $netAssets) . ') do not agree to equity ('
            . $this->money($companySettings, $equity) . '); difference '
            . $this->money($companySettings, $difference) . '.';
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        $this->companySettingsService ??= new \eel_accounts\Service\CompanySettingsService();

        return $this->companySettingsService->money($companySettings, $value);
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
