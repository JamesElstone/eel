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
    private const CONFIRMATION_TEXT = 'I confirm the directors, attributed entries, per-director balances, tax flags and calculated control-account reclassification shown above are correct for this accounting period.';

    public function key(): string
    {
        return 'year_end_director_loan_offset';
    }

    public function title(): string
    {
        return 'Director Loan Year End Review';
    }

    public function helper(array $context): string
    {
        return 'This is a factual accounting review. No legal agreement, set-off evidence, settlement declaration, supporting evidence or confirmation note is requested.';
    }

    public function services(): array
    {
        return [[
            'key' => 'directorLoanReview',
            'service' => \eel_accounts\Service\DirectorLoanReconciliationService::class,
            'method' => 'fetchYearEndConfirmationContext',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ]];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['year.end.state', 'year.end.checklist', 'director.loan.state'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $review = (array)($context['services']['directorLoanReview'] ?? []);
        if (empty($review['available'])) {
            return $this->errors((array)($review['errors'] ?? ['Director Loan Year End Review is unavailable.']));
        }

        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);
        $settings = (array)($context['company']['settings'] ?? []);
        $locked = (new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId);
        $hasActivity = !empty($review['has_activity']);
        $acknowledgement = (array)($review['acknowledgement'] ?? []);
        $acknowledgementCurrent = !empty($review['acknowledgement_current']);

        $warnings = '';
        foreach ((array)($review['warnings'] ?? []) as $warning) {
            if (preg_match('/^\d+ Director Loan entries are not attributed to a valid same-company director\.$/i', trim((string)$warning)) === 1) {
                continue;
            }
            $warnings .= '<div class="panel-soft warn helper">' . HelperFramework::escape((string)$warning) . '</div>';
        }

        $confirmation = '';
        if (!$hasActivity) {
            $confirmation = '<div class="panel-soft success helper">No Director Loan activity or balance exists for this period, so this check passes automatically.</div>';
        } elseif ($locked) {
            $confirmation = $acknowledgement !== []
                ? '<section class="panel-soft ' . ($acknowledgementCurrent ? 'success' : 'warn') . ' settings-stack">
                    <div class="eyebrow">Recorded Year End Confirmation</div>
                    <div class="summary-value">' . HelperFramework::escape($acknowledgementCurrent ? self::CONFIRMATION_TEXT : 'The recorded confirmation is no longer current for the facts shown above.') . '</div>
                    <div class="stat-foot">Approved'
                        . (trim((string)($review['acknowledged_at'] ?? '')) !== '' ? ' at ' . HelperFramework::escape((string)$review['acknowledged_at']) : '')
                        . (trim((string)($review['acknowledged_by'] ?? '')) !== '' ? ' by ' . HelperFramework::escape((string)$review['acknowledged_by']) : '')
                        . '.</div>
                </section>'
                : '<div class="panel-soft warn helper">No Director Loan Year End confirmation was recorded before this period was locked.</div>';
        } elseif (!empty($review['can_confirm'])) {
            $confirmation = \eel_accounts\Renderer\YearEndApprovalRenderer::render([
                'subject' => 'Director Loan Year End facts',
                'confirmationText' => self::CONFIRMATION_TEXT,
                'companyId' => $companyId,
                'accountingPeriodId' => $accountingPeriodId,
                'acknowledged' => $acknowledgementCurrent,
                'acknowledgementState' => (string)($review['acknowledgement_state'] ?? ''),
                'acknowledgedAt' => (string)($review['acknowledged_at'] ?? ''),
                'acknowledgedBy' => (string)($review['acknowledged_by'] ?? ''),
                'intent' => 'save_director_loan_year_end_review',
                'revokeIntent' => 'save_director_loan_year_end_review',
                'checkboxName' => 'director_loan_year_end_review',
                'approveFields' => ['director_loan_year_end_review' => '1'],
                'revokeFields' => ['director_loan_year_end_review' => '0'],
                'noteMode' => \eel_accounts\Renderer\YearEndApprovalRenderer::NOTE_HIDDEN,
            ]);
        } else {
            $confirmation = '<div class="panel-soft warn helper">Attribute every Director Loan entry on the Summary tab before confirming these facts.</div>';
        }

        return '<section class="settings-stack">
            <div class="month-grid">
                ' . $this->stat('Gross Director Loan Asset', $this->money($settings, $review['asset_receivable'] ?? 0)) . '
                ' . $this->stat('Gross Director Loan Liability', $this->money($settings, $review['liability_payable'] ?? 0)) . '
                ' . $this->stat('Calculated reclassification', $this->money($settings, $review['desired_reclassification_amount'] ?? 0)) . '
                ' . $this->stat('Already posted', $this->money($settings, $review['posted_reclassification_amount'] ?? 0)) . '
                ' . $this->stat('Pending at lock', $this->money($settings, $review['pending_adjustment_amount'] ?? 0)) . '
                ' . $this->stat('Gross loan asset (not s455)', $this->money($settings, $review['potential_s455_exposure'] ?? 0)) . '
            </div>
            ' . $warnings . '
            <section class="settings-stack">
                <div class="eyebrow">Per-director facts</div>
                ' . $this->positionsTable((array)($review['per_director'] ?? []), $settings) . '
                <div class="eyebrow">Tax flags</div>
                ' . $this->taxFlags((array)($review['tax_review'] ?? []), $settings) . '
                <div class="eyebrow">Calculated control-account reclassification</div>
                ' . $this->proposedLines((array)($review['proposed_lines'] ?? []), $settings) . '
            </section>
            ' . $confirmation . '
        </section>';
    }

    private function positionsTable(array $positions, array $settings): string
    {
        if ($positions === []) {
            return '<div class="helper">No per-director balances.</div>';
        }
        $rows = '';
        foreach ($positions as $position) {
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($position['director_name'] ?? 'Unattributed')) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $position['gross_asset'] ?? 0)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $position['gross_liability'] ?? 0)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $position['desired_reclassification'] ?? 0)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $position['net_closing_position'] ?? 0)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $position['potential_s455_exposure'] ?? 0)) . '</td>
            </tr>';
        }
        return '<div class="panel-soft table-scroll"><table>
            <thead><tr><th>Director</th><th>Gross asset</th><th>Gross liability</th><th>Reclassification</th><th>Net closing</th><th>Gross asset principal</th></tr></thead>
            <tbody>' . $rows . '</tbody>
        </table></div>';
    }

    private function taxFlags(array $taxReview, array $settings): string
    {
        $flags = (array)($taxReview['director_flags'] ?? []);
        if ($flags === []) {
            return '<div class="helper">No attributed director balances require a tax flag.</div>';
        }
        $rows = '';
        foreach ($flags as $flag) {
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($flag['director_name'] ?? '')) . '</td>
                <td>' . (!empty($flag['review_required']) ? '<span class="badge warning">Review required</span>' : '<span class="badge success">No exposure flagged</span>') . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $flag['potential_s455_exposure'] ?? 0)) . '</td>
            </tr>';
        }
        return '<div class="panel-soft table-scroll"><table>
            <thead><tr><th>Director</th><th>Tax flag</th><th>Gross asset principal</th></tr></thead>
            <tbody>' . $rows . '</tbody>
        </table></div>';
    }

    private function proposedLines(array $lines, array $settings): string
    {
        if ($lines === []) {
            return '<div class="helper">No additional reclassification journal is currently required.</div>';
        }
        $rows = '';
        foreach ($lines as $line) {
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($line['line_description'] ?? '')) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $line['debit'] ?? 0)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $line['credit'] ?? 0)) . '</td>
            </tr>';
        }
        return '<div class="panel-soft table-scroll"><table>
            <thead><tr><th>Attributed line</th><th>Debit</th><th>Credit</th></tr></thead>
            <tbody>' . $rows . '</tbody>
        </table></div>';
    }

    private function stat(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function money(array $settings, mixed $value): string
    {
        return (new \eel_accounts\Service\MoneyFormatService())->format($settings, $value);
    }

    private function errors(array $errors): string
    {
        return implode('', array_map(
            static fn(mixed $error): string => '<div class="helper">' . HelperFramework::escape((string)$error) . '</div>',
            $errors
        ));
    }
}
