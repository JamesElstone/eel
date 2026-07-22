<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_loan_confirmationCard extends CardBaseFramework
{
    private const CONFIRMATION_TEXT = 'I confirm the directors, attributed entries, per-director balances, tax flags and calculated control-account reclassification shown above are correct for this accounting period.';

    public function key(): string
    {
        return 'year_end_loan_confirmation';
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
        return [
            [
                'key' => 'directorLoanReview',
                'service' => \eel_accounts\Service\DirectorLoanReconciliationService::class,
                'method' => 'fetchYearEndConfirmationContext',
                'params' => ['companyId' => ':company.id', 'accountingPeriodId' => ':company.accounting_period_id'],
            ],
            [
                'key' => 'ct600a',
                'service' => \eel_accounts\Service\Ct600aService::class,
                'method' => 'fetchForAccountingPeriod',
                'params' => ['companyId' => ':company.id', 'accountingPeriodId' => ':company.accounting_period_id'],
            ],
        ];
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
        $legacyRepairRequired = abs((float)($review['legacy_unresolved_reclassification_amount'] ?? 0)) >= 0.005;

        $warnings = '';
        foreach ((array)($review['warnings'] ?? []) as $warning) {
            if (preg_match('/^\d+ Director Loan entries are not attributed to a valid same-company director\.$/i', trim((string)$warning)) === 1) {
                continue;
            }
            $warnings .= '<div class="panel-soft warn helper">' . HelperFramework::escape((string)$warning) . '</div>';
        }
        if ($legacyRepairRequired) {
            $warnings .= $locked
                ? '<div class="panel-soft warn helper">This period will automatically post an auditable legacy-offset reversal when it is unlocked. The original journal will remain unchanged.</div>'
                : $this->legacyRepairAction($companyId, $accountingPeriodId);
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
        } elseif ($legacyRepairRequired) {
            $confirmation = '<section class="panel-soft warn settings-stack">
                <div class="eyebrow">Year End Confirmation</div>
                <div class="summary-value">Repair the legacy Director Loan offset before confirming these facts.</div>
                <div class="helper">The repair reverses the combined unattributed historical offset without changing its source journals. Confirm the refreshed facts after it completes.</div>
            </section>';
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
                ' . $this->stat('Total Participator Loan Asset (Gross)', $this->money($settings, $review['asset_receivable'] ?? 0)) . '
                ' . $this->stat('Total Participator Loan Liability (Gross)', $this->money($settings, $review['liability_payable'] ?? 0)) . '
                ' . $this->stat('Loan Balancing Adjustment at Year End', $this->money($settings, $review['desired_reclassification_amount'] ?? 0)) . '
                ' . $this->stat('Existing Journal entries for year end balancing', $this->money($settings, $review['posted_reclassification_amount'] ?? 0)) . '
                ' . $this->stat('Journal entries to be made at Year End closure', $this->money($settings, $review['pending_adjustment_amount'] ?? 0)) . '
                ' . $this->stat('Loan Assets Outstanding subject to be taxed', $this->money($settings, $review['potential_s455_exposure'] ?? 0)) . '
                ' . $this->stat('Balance after Year End has closed', $this->money($settings, $review['net_position'] ?? 0)) . '
            </div>
            ' . $warnings . '
            <section class="settings-stack">
                ' . $this->positionsTable(
                    (array)($review['per_director'] ?? []),
                    (array)($review['tax_review'] ?? []),
                    $settings
                ) . '
                ' . $this->proposedLines(
                    (array)($review['proposed_lines'] ?? []),
                    $settings,
                    [(array)($review['asset_nominal'] ?? []), (array)($review['liability_nominal'] ?? [])]
                ) . '
            </section>
            ' . $this->ct600aDeclarations($context, $companyId, $accountingPeriodId) . '
            ' . $confirmation . '
        </section>';
    }

    private function ct600aDeclarations(array $context, int $companyId, int $accountingPeriodId): string
    {
        $ct600a = (array)($context['services']['ct600a'] ?? []);
        if (empty($ct600a['available'])) {
            return '';
        }
        $questions = (array)($ct600a['questions'] ?? []);
        $review = (array)($ct600a['review'] ?? []);
        $fields = '';
        foreach ($questions as $key => $question) {
            $value = (string)($review['answers'][$key] ?? 'yes');
            if (!in_array($value, ['yes', 'no'], true)) {
                $value = 'yes';
            }
            $fields .= '<fieldset class="panel-soft"><legend>' . HelperFramework::escape((string)$question) . '</legend><div class="actions-row">'
                . $this->ct600aRadio((string)$key, 'no', 'No', $value)
                . $this->ct600aRadio((string)$key, 'yes', 'Yes', $value) . '</div></fieldset>';
        }

        return '<section class="settings-stack"><form class="settings-stack" method="post" action="?page=loans" data-ajax="true">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="Ct600a"><input type="hidden" name="intent" value="save_ct600a_review">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
            . $fields
            . '<div class="helper">Selections save automatically. The Year End Confirmation below is the single acknowledgement for this loan and CT600A position.</div></form></section>';
    }

    private function ct600aRadio(string $name, string $value, string $label, string $selected): string
    {
        $id = 'ct600a_' . $name . '_' . $value;
        return '<label for="' . $id . '"><input id="' . $id . '" type="radio" name="' . HelperFramework::escape($name)
            . '" value="' . $value . '" data-submit-on-change="true"' . ($selected === $value ? ' checked' : '') . ' required> ' . $label . '</label>';
    }

    private function positionsTable(array $positions, array $taxReview, array $settings): string
    {
        $visiblePositions = array_values(array_filter(
            $positions,
            fn(mixed $position): bool => !$this->isZeroUnattributedPosition((array)$position)
        ));
        $flagsByDirector = [];
        foreach ((array)($taxReview['director_flags'] ?? []) as $flag) {
            $flag = (array)$flag;
            $flagsByDirector[(string)($flag['director_name'] ?? '')] = $flag;
        }
        if ($visiblePositions === [] && $flagsByDirector === []) {
            return '<div class="helper">No per-director balances.</div>';
        }

        $positionsByDirector = [];
        foreach ($visiblePositions as $position) {
            $position = (array)$position;
            $positionsByDirector[(string)($position['director_name'] ?? 'Unattributed')] = $position;
        }

        $rows = '';
        foreach (array_unique(array_merge(array_keys($positionsByDirector), array_keys($flagsByDirector))) as $directorName) {
            $position = (array)($positionsByDirector[$directorName] ?? []);
            $flag = (array)($flagsByDirector[$directorName] ?? []);
            $rows .= '<tr>
                <td>' . HelperFramework::escape($directorName !== '' ? $directorName : 'Unattributed') . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $position['gross_asset'] ?? 0)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $position['gross_liability'] ?? 0)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $position['desired_reclassification'] ?? 0)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $position['net_closing_position'] ?? 0)) . '</td>
                <td>' . ($flag === [] ? '' : (!empty($flag['review_required']) ? '<span class="badge warning">Review required</span>' : '<span class="badge success">No exposure flagged</span>')) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $flag['potential_s455_exposure'] ?? ($position['potential_s455_exposure'] ?? 0))) . '</td>
            </tr>';
        }
        return '<div class="panel-soft table-scroll"><table>
            <thead><tr><th>Participator</th><th>Gross asset</th><th>Gross liability</th><th>Reclassification</th><th>Net closing</th><th>Tax flag</th><th>Gross asset principal</th></tr></thead>
            <tbody>' . $rows . '</tbody>
        </table></div>';
    }

    private function legacyRepairAction(int $companyId, int $accountingPeriodId): string
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return '';
        }

        return '<section class="panel-soft warn settings-stack">
            <div class="eyebrow">Legacy offset repair</div>
            <div class="helper">Post an auditable net reversal for the legacy offset. The historical source journals will not be changed.</div>
            <form method="post" data-ajax="true" class="actions-row">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="repair_legacy_director_loan_offset">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <button class="button primary" type="submit">Repair legacy offset</button>
            </form>
        </section>';
    }

    private function isZeroUnattributedPosition(array $position): bool
    {
        if (trim((string)($position['director_name'] ?? 'Unattributed')) !== 'Unattributed') {
            return false;
        }

        foreach ([
            'gross_asset',
            'gross_liability',
            'desired_reclassification',
            'net_closing_position',
            'potential_s455_exposure',
        ] as $key) {
            if ((float)($position[$key] ?? 0) !== 0.0) {
                return false;
            }
        }

        return true;
    }

    private function proposedLines(array $lines, array $settings, array $nominals): string
    {
        if ($lines === []) {
            return '<div class="helper">No additional reclassification journal is currently required.</div>';
        }
        $nominalLabels = [];
        foreach ($nominals as $nominal) {
            $nominalId = (int)($nominal['id'] ?? 0);
            if ($nominalId > 0) {
                $nominalLabels[$nominalId] = trim((string)($nominal['code'] ?? '') . ' ' . (string)($nominal['name'] ?? ''));
            }
        }
        $rows = '';
        foreach ($lines as $line) {
            $nominalId = (int)($line['nominal_account_id'] ?? 0);
            $nominal = $nominalLabels[$nominalId] ?? ('Nominal #' . $nominalId);
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($line['line_description'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($nominal) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $line['debit'] ?? 0)) . '</td>
                <td class="numeric">' . HelperFramework::escape($this->money($settings, $line['credit'] ?? 0)) . '</td>
            </tr>';
        }
        return '<div class="panel-soft table-scroll"><table>
            <thead><tr><th>Attributed line</th><th>Nominal</th><th>Debit</th><th>Credit</th></tr></thead>
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
