<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _reserve_reviewCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'reserve_review';
    }

    public function title(): string
    {
        return 'Distributable Profit Review';
    }

    public function services(): array
    {
        return [$this->dividendContextService()];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context', 'dividend.capacity', 'dividend.declare', 'dividend.warnings'];
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companySettings = (array)($company['settings'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $dividends = $this->dividendsContext($context);
        $review = (array)($dividends['reserve_review'] ?? []);
        $profitLossApproval = (array)($dividends['profit_loss_approval'] ?? []);
        $profitLossApproved = !empty($profitLossApproval['current']);
        $isLocked = array_key_exists('is_locked', $dividends)
            ? (bool)$dividends['is_locked']
            : (new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId);
        $isReadOnly = $isLocked || $profitLossApproved;

        if (empty($review['available'])) {
            return '<div class="settings-stack">' . $this->renderErrors((array)($review['errors'] ?? ['Reserve review is not available.'])) . '</div>';
        }

        $summary = (array)($review['summary'] ?? []);
        $snapshot = (array)($review['snapshot'] ?? []);
        $status = (string)($review['status'] ?? 'missing');
        $statusClass = $status === 'current' ? 'success' : ($status === 'stale' ? 'warning' : 'danger');
        $statusLabel = (string)($review['status_label'] ?? 'Reserve review missing');
        $reviewedAt = trim((string)($snapshot['reviewed_at'] ?? ''));
        $asAtDate = (string)($review['as_at_date'] ?? '');
        $unknownAmount = round((float)($summary['unknown_amount'] ?? 0), 2);

        $rowsHtml = '';
        foreach ((array)($review['rows'] ?? []) as $row) {
            $nominalId = (int)($row['nominal_account_id'] ?? 0);
            if ($nominalId <= 0) {
                continue;
            }
            $treatment = (string)($row['treatment'] ?? 'unknown');
            $badge = $this->rowBadge($row);
            $guidance = $this->rowGuidance($row);

            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['nominal_code'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['nominal_name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $row['profit_effect'] ?? 0)) . '</td>
                <td>
                    <span class="badge ' . HelperFramework::escape($badge['class']) . '">' . HelperFramework::escape($badge['label']) . '</span>
                    <select class="select' . ($isReadOnly ? ' control-disabled' : '') . '" name="treatment[' . $nominalId . ']"' . ($isReadOnly ? ' disabled aria-disabled="true"' : '') . ' title="' . HelperFramework::escape($profitLossApproved ? 'Revoke Profit & Loss approval before changing reserve classifications.' : ($isLocked ? 'This accounting period is locked.' : '')) . '">
                        ' . $this->treatmentOptions((array)($review['treatments'] ?? []), $treatment) . '
                    </select>
                    <div class="helper">' . HelperFramework::escape($this->treatmentExplanation($treatment)) . '</div>
                    <div class="helper">' . HelperFramework::escape($guidance) . '</div>
                </td>
            </tr>';
        }
        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="4">No posted profit and loss movements exist for this period.</td></tr>';
        }

        return '<div class="settings-stack">
            <div class="status-head">
                <span class="badge ' . HelperFramework::escape($statusClass) . '">' . HelperFramework::escape($statusLabel) . '</span>
                <span class="helper">As at ' . HelperFramework::escape($asAtDate !== '' ? $asAtDate : '-') . '</span>
                ' . ($reviewedAt !== '' ? '<span class="helper">Last reviewed ' . HelperFramework::escape(HelperFramework::displayDate($reviewedAt)) . '</span>' : '') . '
            </div>
            <section class="panel-soft settings-stack">
                <div class="summary-label">What this review is for</div>
                <div class="helper">This review classifies current-period profit and loss movements so the company\'s reserves are presented correctly.</div>
                <div class="helper">Most ordinary sales and expenses are classified automatically.</div>
                <div class="helper">Only unusual or unknown items normally need attention.</div>
                <div class="helper">If unsure, leave as Unknown and ask your accountant.</div>
            </section>
            ' . $this->treatmentGuide((array)($review['treatments'] ?? [])) . '
            <div class="summary-grid four">
                ' . $this->summaryCard('Brought forward reserves', $this->money($companySettings, $summary['brought_forward_distributable_reserves'] ?? 0)) . '
                ' . $this->summaryCard('Distributable current profit', $this->money($companySettings, $summary['distributable_current_profit'] ?? 0)) . '
                ' . $this->summaryCard('Dividends declared', $this->money($companySettings, $summary['dividends_declared'] ?? 0)) . '
                ' . $this->summaryCard('Closing distributable reserves', $this->money($companySettings, $summary['closing_distributable_reserves'] ?? 0)) . '
            </div>
            <div class="summary-grid four">
                ' . $this->summaryCard('Ledger profit / loss', $this->money($companySettings, $summary['ledger_profit_loss'] ?? 0)) . '
                ' . $this->summaryCard('Reviewed realised profit', $this->money($companySettings, $summary['realised_profit_amount'] ?? 0)) . '
                ' . $this->summaryCard('Reviewed reductions', $this->money($companySettings, $this->reviewedReductions($summary))) . '
                ' . $this->summaryCard('Unknown', $this->money($companySettings, $summary['unknown_amount'] ?? 0)) . '
            </div>
            ' . ($unknownAmount > 0.0 ? '<section class="panel-soft warn settings-stack"><span class="badge warning">Needs review</span><div class="helper">Classify all Unknown amounts before the reserve review can be marked current.</div></section>' : '') . '
            ' . ($isLocked ? '<div class="helper"><span class="badge warning">Period locked</span> Distributable profit classifications are read only.</div>' : '') . '
            ' . ($profitLossApproved ? '<div class="helper"><span class="badge success">Profit &amp; Loss approved</span> Reserve classifications are included in that approval and are read only until the approval is revoked.</div>' : '') . '
            <section class="panel-soft settings-stack">
            <form method="post" action="?page=profit_loss" data-ajax="true" class="settings-stack">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Dividend">
                <input type="hidden" name="intent" value="save_dividend_reserve_review">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="as_at_date" value="' . HelperFramework::escape($asAtDate) . '">
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Code</th><th>Nominal</th><th>Amount</th><th>Review</th></tr></thead>
                        <tbody>' . $rowsHtml . '</tbody>
                    </table>
                </div>
                <div class="helper">Changes are recorded automatically. Unknown amounts and unreviewed snapshots are excluded from reviewed reserve totals.</div>
            </form>
            </section>
        </div>';
    }

    private function treatmentOptions(array $treatments, string $selected): string
    {
        $html = '';
        foreach ($treatments as $treatment) {
            $value = (string)$treatment;
            $html .= '<option value="' . HelperFramework::escape($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . HelperFramework::escape($this->treatmentLabel($value)) . '</option>';
        }

        return $html;
    }

    private function treatmentGuide(array $treatments): string
    {
        $items = '';
        foreach ($treatments as $treatment) {
            $value = (string)$treatment;
            $items .= '<div class="summary-card">
                <div class="summary-label">' . HelperFramework::escape($this->treatmentLabel($value)) . '</div>
                <div class="helper">' . HelperFramework::escape($this->treatmentExplanation($value)) . '</div>
            </div>';
        }

        return '<section class="panel-soft settings-stack">
            <div class="summary-label">How to read the options</div>
            <div class="summary-grid four">' . $items . '</div>
        </section>';
    }

    private function treatmentLabel(string $treatment): string
    {
        return match ($treatment) {
            'tax_charge' => 'Corporation Tax Charge',
            default => HelperFramework::labelFromKey($treatment, '_'),
        };
    }

    private function treatmentExplanation(string $treatment): string
    {
        return match ($treatment) {
            'realised_profit' => 'Normal earned income that increases realised reserves.',
            'realised_loss' => 'Normal business cost that reduces realised reserves.',
            'tax_charge' => 'Tax cost that reduces reserves.',
            'unrealised_gain' => 'Paper gain kept separate from realised reserves.',
            'unrealised_loss' => 'Paper loss that reduces the reviewed reserve position.',
            'non_distributable' => 'Profit excluded from realised reserves.',
            'capital' => 'Capital, share, or balance-sheet item rather than normal trading profit.',
            'dividend_distribution' => 'Dividend already paid or declared, reduces reserves.',
            default => 'Not safe to rely on until reviewed.',
        };
    }

    private function rowBadge(array $row): array
    {
        $treatment = (string)($row['treatment'] ?? 'unknown');
        if ($treatment === 'unknown') {
            return ['label' => 'Ask accountant', 'class' => 'danger'];
        }
        if (in_array($treatment, ['unrealised_gain', 'unrealised_loss', 'capital', 'non_distributable'], true)
            || $treatment !== (string)($row['default_treatment'] ?? $treatment)) {
            return ['label' => 'Needs review', 'class' => 'warning'];
        }

        return ['label' => 'Auto-classified', 'class' => 'success'];
    }

    private function rowGuidance(array $row): string
    {
        $treatment = (string)($row['treatment'] ?? 'unknown');
        if ($treatment === 'unknown') {
            return 'Leave as Unknown if you are unsure; this amount is excluded from reviewed reserves until classified.';
        }
        if (in_array($treatment, ['unrealised_gain', 'unrealised_loss', 'capital', 'non_distributable'], true)) {
            return 'Check this carefully because it changes how the movement is presented in reserves.';
        }
        if ($treatment !== (string)($row['default_treatment'] ?? $treatment)) {
            return 'This differs from the automatic classification, so check that the reserve treatment is correct.';
        }

        return 'The system has classified this from the nominal account type; you can change it if it is not right.';
    }

    private function summaryCard(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function reviewedReductions(array $summary): float
    {
        return round(
            (float)($summary['realised_loss_amount'] ?? 0)
            + (float)($summary['unrealised_loss_amount'] ?? 0)
            + (float)($summary['tax_charge_amount'] ?? 0)
            + (float)($summary['dividend_distribution_amount'] ?? 0)
            + (float)($summary['unknown_amount'] ?? 0),
            2
        );
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

    private function dividendContextService(): array
    {
        return [
            'key' => 'dividendContext',
            'service' => \eel_accounts\Service\DividendViewDataService::class,
            'method' => 'fetchCapacityContext',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ];
    }

    private function dividendsContext(array $context): array
    {
        $serviceContext = $context['services']['dividendContext'] ?? null;
        if (is_array($serviceContext)) {
            return $serviceContext;
        }

        return (array)($context['dividends'] ?? []);
    }
}
