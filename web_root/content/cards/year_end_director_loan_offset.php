<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_director_loan_offsetCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'year_end_director_loan_offset';
    }

    public function title(): string
    {
        return 'Director Loan Offset';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'directorLoanOffset',
                'service' => \eel_accounts\Service\DirectorLoanReconciliationService::class,
                'method' => 'fetchContext',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
            [
                'key' => 'directorLoanTaxReview',
                'service' => \eel_accounts\Service\DirectorLoanService::class,
                'method' => 'fetchTaxReview',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['year.end.state', 'year.end.checklist'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $offset = (array)($context['services']['directorLoanOffset'] ?? []);
        $taxReview = (array)($context['services']['directorLoanTaxReview'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriod = (array)($offset['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? ($company['accounting_period_id'] ?? 0));

        if (empty($offset['available'])) {
            return '<section class="settings-stack" id="director-loan-offset">' . $this->renderErrors((array)($offset['errors'] ?? ['Director loan offset review is not available.'])) . '</section>';
        }

        $assetNominal = (array)($offset['asset_nominal'] ?? []);
        $liabilityNominal = (array)($offset['liability_nominal'] ?? []);
        $companySettings = (array)($company['settings'] ?? []);
        $warningsHtml = '';
        foreach ((array)($offset['warnings'] ?? []) as $warning) {
            $warningsHtml .= '<div class="helper">' . HelperFramework::escape((string)$warning) . '</div>';
        }

        $status = (string)($offset['offset_status'] ?? '');
        $acknowledged = !empty($offset['closing_balance_acknowledged']);
        $acknowledgementForm = !empty($offset['can_post'])
            ? \eel_accounts\Renderer\YearEndApprovalRenderer::render([
                'subject' => 'director loan offset',
                'companyId' => $companyId,
                'accountingPeriodId' => $accountingPeriodId,
                'acknowledged' => $acknowledged,
                'acknowledgedAt' => (string)($offset['closing_balance_acknowledged_at'] ?? ''),
                'acknowledgedBy' => (string)($offset['closing_balance_acknowledged_by'] ?? ''),
                'note' => (string)($offset['director_loan_closing_approval_note'] ?? ''),
                'intent' => 'save_director_loan_offset_acknowledgement',
                'revokeIntent' => 'save_director_loan_offset_acknowledgement',
                'checkboxName' => 'director_loan_offset_acknowledgement',
                'approveFields' => ['director_loan_offset_acknowledgement' => '1'],
                'revokeFields' => ['director_loan_offset_acknowledgement' => '0'],
            ])
            : '';

        return '<section class="settings-stack" id="director-loan-offset">
            <div class="status-head">
                <span class="badge ' . HelperFramework::escape($this->badgeClass($this->offsetBadgeStatus($status))) . '">' . HelperFramework::escape((string)($offset['offset_status_label'] ?? HelperFramework::labelFromKey($status, '_'))) . '</span>
            </div>
            <div class="month-grid">
                ' . $this->summaryCard(FormattingFramework::nominalLabel($assetNominal), $this->money($companySettings, $offset['asset_receivable'] ?? 0)) . '
                ' . $this->summaryCard(FormattingFramework::nominalLabel($liabilityNominal), $this->money($companySettings, $offset['liability_payable'] ?? 0)) . '
                ' . $this->summaryCard('Proposed offset', $this->money($companySettings, $offset['offset_amount'] ?? 0)) . '
                ' . $this->summaryCard('Net position', $this->money($companySettings, $offset['net_position'] ?? 0)) . '
                ' . $this->summaryCard('Net Flow', (string)($offset['net_position_label'] ?? '')) . '
                ' . $this->summaryCard('Existing posted offset', $this->money($companySettings, $offset['posted_offset_amount'] ?? 0)) . '
            </div>
            <div class="table-scroll panel-soft">
                <table>
                    <thead><tr><th>Journal line</th><th>Debit</th><th>Credit</th></tr></thead>
                    <tbody>
                        <tr><td>' . HelperFramework::escape(FormattingFramework::nominalLabel($liabilityNominal)) . '</td><td>' . HelperFramework::escape($this->money($companySettings, $offset['offset_amount'] ?? 0)) . '</td><td>' . HelperFramework::escape($this->money($companySettings, 0)) . '</td></tr>
                        <tr><td>' . HelperFramework::escape(FormattingFramework::nominalLabel($assetNominal)) . '</td><td>' . HelperFramework::escape($this->money($companySettings, 0)) . '</td><td>' . HelperFramework::escape($this->money($companySettings, $offset['offset_amount'] ?? 0)) . '</td></tr>
                    </tbody>
                </table>
            </div>
            ' . $warningsHtml . '
            ' . $this->taxReviewHtml($taxReview, $companySettings) . '
            ' . (empty($offset['can_post']) ? '<div class="helper">' . HelperFramework::escape((string)($offset['post_blocked_reason'] ?? '')) . '</div>' : '') . '
            ' . $acknowledgementForm . '
        </section>';
    }

    private function summaryCard(string $label, string $value): string
    {
        return '<div class="panel-soft"><div class="eyebrow">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function taxReviewHtml(array $taxReview, array $companySettings): string
    {
        if (empty($taxReview['available'])) {
            $errors = (array)($taxReview['errors'] ?? []);
            return '<div class="panel-soft stack"><div class="eyebrow">Tax Review</div><div class="helper">' . HelperFramework::escape((string)($errors[0] ?? 'Director loan tax review is not available.')) . '</div></div>';
        }

        $status = (string)($taxReview['status'] ?? '');
        $itemsHtml = '';
        foreach ((array)($taxReview['review_items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemsHtml .= '<li><strong>' . HelperFramework::escape((string)($item['label'] ?? 'Review item')) . '</strong><br><span class="helper">' . HelperFramework::escape((string)($item['detail'] ?? '')) . '</span></li>';
        }

        if ($itemsHtml === '') {
            $itemsHtml = '<li><span class="helper">No director receivable tax review flags are currently raised for this period.</span></li>';
        }

        $repaymentDate = trim((string)($taxReview['repayment_review_date'] ?? ''));

        return '<div class="panel-soft stack">
            <div class="status-head">
                <span class="badge ' . HelperFramework::escape($this->badgeClass($status === 'review_required' ? 'warning' : 'pass')) . '">' . HelperFramework::escape((string)($taxReview['status_label'] ?? HelperFramework::labelFromKey($status, '_'))) . '</span>
            </div>
            <div class="month-grid">
                ' . $this->summaryCard('Potential s455 exposure basis', $this->money($companySettings, $taxReview['exposure_amount'] ?? 0)) . '
                ' . $this->summaryCard('Repayment review date', $repaymentDate !== '' ? HelperFramework::displayDate($repaymentDate) : 'Not applicable') . '
            </div>
            <ul class="settings-list">' . $itemsHtml . '</ul>
        </div>';
    }

    private function offsetBadgeStatus(string $status): string
    {
        return match ($status) {
            'current', 'not_required' => 'pass',
            'missing', 'stale' => 'warning',
            default => 'info',
        };
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'pass', 'ready', 'locked' => 'success',
            'fail', 'needs_attention' => 'danger',
            'warning', 'not_started' => 'warning',
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
