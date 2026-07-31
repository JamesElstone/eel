<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_tax_readinessCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'year_end_tax_readiness';
    }

    public function title(): string
    {
        return 'Year End Corporation Tax Review';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'yearEndChecklist',
                'service' => \eel_accounts\Service\YearEndChecklistService::class,
                'method' => 'fetchChecklist',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
            [
                'key' => 'corporation_tax_filing_scope',
                'service' => \eel_accounts\Service\CorporationTaxFilingScopeService::class,
                'method' => 'fetch',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
            [
                'key' => 'sectionReview',
                'service' => \eel_accounts\Service\YearEndSectionApprovalService::class,
                'method' => 'fetchReview',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'checkCode' => 'tax_readiness_acknowledgement',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['year.end.state', 'year.end.checklist', 'ixbrl.readiness', 'ixbrl.disclosures', 'ixbrl.facts.preview', 'ixbrl.generation'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $checklist = (array)($context['services']['yearEndChecklist'] ?? (($context['year_end'] ?? [])['checklist'] ?? []));
        $taxReadiness = (array)($context['services']['yearEndTaxReadiness'] ?? $checklist['tax_readiness'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companySettings = (array)($company['settings'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $filingScope = (array)($context['services']['corporation_tax_filing_scope'] ?? []);
        $developerCleanup = $this->developerHistoryCleanupHtml($companyId, $accountingPeriodId);

        if (empty($taxReadiness['available'])) {
            return '<section class="settings-stack" id="tax-readiness">' . $developerCleanup
                . '<div class="helper">' . \eel_accounts\Support\Utf8::html((string)($taxReadiness['errors'][0] ?? 'Tax readiness is not available.')) . '</div></section>';
        }

        $provision = (array)($taxReadiness['provision'] ?? []);
        $taxBasisReady = (string)($taxReadiness['freeze_status'] ?? '') === 'ready_for_approval';
        $sectionReview = (array)($context['services']['sectionReview'] ?? []);
        $scopeGateBlocked = !empty($sectionReview['scope_gate']) && empty($sectionReview['scope_ready']);
        $acknowledgementForm = $this->acknowledgementHtml(
            $sectionReview,
            $companyId,
            $accountingPeriodId,
            $this->money($companySettings, $taxReadiness['estimated_corporation_tax'] ?? 0),
            $taxBasisReady,
            $scopeGateBlocked
        );

        return '<section class="settings-stack" id="tax-readiness">
            ' . $developerCleanup . '
            ' . $this->overallTaxPositionHtml($companySettings, $taxReadiness, $provision) . '
            ' . $this->ctPeriodSectionsHtml($companySettings, $taxReadiness, $companyId, $accountingPeriodId) . '
            ' . $this->provisionHtml($companySettings, $provision) . '
            ' . $this->corporationTaxScope(
                $filingScope,
                $companyId,
                $accountingPeriodId,
                !empty($sectionReview['acknowledgement_current'])
            ) . '
            ' . $this->reviewApprovalHtml($acknowledgementForm) . '
        </section>';
    }

    private function developerHistoryCleanupHtml(int $companyId, int $accountingPeriodId): string
    {
        if (!(bool)AppConfigurationStore::get('developer_options', false)) {
            return '';
        }

        return '<div class="actions-row actions-row-right"><form method="post" action="?page=corporation_tax" data-ajax="true">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="YearEnd">'
            . '<input type="hidden" name="intent" value="cleanup_unsubmitted_tax_history">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '">'
            . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
            . '<button class="button danger" type="submit" title="Developer only" data-chicken-check="true" data-chicken-title="Remove unsubmitted Corporation Tax history" data-chicken-message="Remove obsolete unsubmitted Corporation Tax history for this accounting period?<br><br>The newest approval and newest audit snapshot for each CT period are retained. Transmitted filing records and their evidence remain protected. Older untransmitted approvals, submission drafts, evidence bundles, audit snapshots, and audit-area details are permanently removed. Generated files on disk are not deleted." data-chicken-confirm-text="Remove history" data-chicken-button-class="button danger">Remove Unsubmitted Tax History</button>'
            . '</form></div>';
    }

    private function corporationTaxScope(array $scope, int $companyId, int $accountingPeriodId, bool $disabled): string
    {
        if (empty($scope['available'])) {
            return '<section class="panel-soft"><h3 class="card-title">Corporation Tax Filing Scope Check</h3><div class="standout helper">'
                . \eel_accounts\Support\Utf8::html((string)(($scope['errors'] ?? [])[0] ?? 'The Corporation Tax scope review is unavailable.')) . '</div></section>';
        }
        $answers = (array)($scope['answers'] ?? []);
        $rows = '';
        foreach ((array)($scope['definitions'] ?? []) as $key => $definition) {
            $answer = (string)($answers[$key] ?? '');
            if (!in_array($answer, ['yes', 'no'], true)) {
                $answer = '';
            }
            $rows .= '<tr><td>' . \eel_accounts\Support\Utf8::html((string)$definition['page']) . '</td>'
                . '<td>' . \eel_accounts\Support\Utf8::html((string)$definition['label']) . '</td>'
                . '<td>' . \eel_accounts\Support\Utf8::html((string)$definition['question']) . '</td>'
                . '<td class="year-end-tax-scope-guidance"><a class="button button-inline" target="_blank" rel="noopener noreferrer" href="' . \eel_accounts\Support\Utf8::html((string)$definition['url']) . '">HMRC guidance</a></td>'
                . '<td><form method="post" action="?page=corporation_tax" data-ajax="true">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="card_action" value="Ixbrl"><input type="hidden" name="intent" value="save_ct_filing_scope_answer">'
                . '<input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
                . '<input type="hidden" name="scope_field" value="' . \eel_accounts\Support\Utf8::html((string)$key) . '">'
                . '<div class="actions-row actions-row-nowrap year-end-tax-scope-answer">' . $this->scopeRadio((string)$key, 'no', 'No', $answer, $disabled)
                . $this->scopeRadio((string)$key, 'yes', 'Yes', $answer, $disabled) . '</div></form></td></tr>';
        }
        return '<section class="panel-soft settings-stack" data-year-end-tax-scope-table="true"><h3 class="card-title">Corporation Tax Filing Scope Check</h3>'
            . ($disabled ? '<div class="helper">Revoke the Year End Confirmation before changing a filing-scope decision.</div>' : '')
            . '<div class="table-scroll"><table class="year-end-tax-scope-table"><thead><tr><th>Supplement ID</th><th>Supplement Name</th><th>Question</th><th>HMRC Guidance</th><th>Answer</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div></section>';
    }

    private function scopeRadio(string $field, string $value, string $label, string $selected, bool $disabled): string
    {
        $id = 'ct_scope_' . $field . '_' . $value;
        return '<label for="' . $id . '"><input id="' . $id . '" type="radio" name="scope_answer" value="' . $value
            . '" required data-submit-on-change="true"' . ($disabled ? ' disabled' : '') . ($selected === $value ? ' checked' : '') . '> ' . $label . '</label>';
    }

    private function check(array $checks, string $checkCode): array
    {
        foreach ($checks as $check) {
            if (is_array($check) && (string)($check['check_code'] ?? '') === $checkCode) {
                return $check;
            }
        }
        return [];
    }

    private function acknowledgementHtml(array $review, int $companyId, int $accountingPeriodId, string $totalCorporationTaxDue, bool $taxBasisReady, bool $scopeGateBlocked): string
    {
        $acknowledgement = (array)($review['acknowledgement'] ?? []);
        return \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'Total Corporation Tax Due to HMRC, including the CT600A position and associated-company count for every CT period',
            'confirmationText' => 'I confirm the Total Corporation Tax Due to HMRC of ' . $totalCorporationTaxDue . ' shown above is the amount the company will pay to HMRC for this accounting period.',
            'companyId' => $companyId,
            'accountingPeriodId' => $accountingPeriodId,
            'acknowledged' => !empty($review['acknowledgement_current']),
            'acknowledgementState' => (string)($review['acknowledgement_state'] ?? 'absent'),
            'acknowledgedAt' => (string)($acknowledgement['acknowledged_at'] ?? ''),
            'acknowledgedBy' => (string)($acknowledgement['acknowledged_by'] ?? ''),
            'note' => (string)($acknowledgement['note'] ?? ''),
            'intent' => 'approve_section_review',
            'revokeIntent' => 'revoke_section_review',
            'approveFields' => ['check_code' => 'tax_readiness_acknowledgement'],
            'revokeFields' => ['check_code' => 'tax_readiness_acknowledgement'],
            'noteName' => 'approval_note',
            'questions' => (array)($review['questions'] ?? []),
            'answers' => (array)($review['answers'] ?? []),
            'renderQuestions' => false,
            'clientScopeGate' => !empty($review['scope_gate']),
            'disabled' => !$taxBasisReady || (empty($review['can_approve']) && !$scopeGateBlocked),
            'disabledReason' => !$taxBasisReady
                ? 'Year End Confirmation is disabled until all tax basis checks have passed.'
                : (string)(($review['approval_errors'] ?? [])[0] ?? ''),
        ]);
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function overallTaxPositionHtml(array $companySettings, array $taxReadiness, array $provision): string
    {
        $periodCount = (int)($taxReadiness['ct_period_count'] ?? count($this->periods($taxReadiness)));
        $blockerCount = (int)($taxReadiness['blocking_diagnostic_count'] ?? count((array)($taxReadiness['blocking_diagnostics'] ?? [])));
        $provisionStatus = (string)($provision['status'] ?? 'not_posted');
        $freezeReady = (string)($taxReadiness['freeze_status'] ?? '') === 'ready_for_approval';
        $hasUnsupportedFeatures = $blockerCount > 0;
        $unsupportedFeaturesClass = $hasUnsupportedFeatures ? ' danger' : '';
        $unsupportedFeaturesPill = $hasUnsupportedFeatures ? $this->badge('danger', 'Review required') : '';

        return '<section class="panel-soft stack">
            <h3 class="card-title">Overall Tax Position</h3>
            ' . $this->summaryGrid([
                ['CT periods', (string)$periodCount],
                ['Accounting-period turnover', $this->money($companySettings, $taxReadiness['actual_trading_turnover'] ?? 0)],
                ['Combined CT600 box 145', $this->money($companySettings, $taxReadiness['ct600_box_145_turnover'] ?? 0)],
                ['Taxable profit', $this->money($companySettings, $taxReadiness['taxable_profit'] ?? 0)],
                ['CT600 profit-tax liability', $this->money($companySettings, $taxReadiness['ordinary_corporation_tax'] ?? 0)],
                ['CT600A loan-tax liability', $this->money($companySettings, $taxReadiness['ct600a_tax'] ?? 0)],
                ['Total Corporation Tax Due to HMRC', $this->money($companySettings, $taxReadiness['estimated_corporation_tax'] ?? 0)],
                ['Losses carried forward (c/f)', $this->money($companySettings, $taxReadiness['losses_carried_forward'] ?? 0)],
                ['Provision status', $this->provisionLabel($provisionStatus)],
                ['Posted CT charge', $this->money($companySettings, $provision['posted_corporation_tax_charge'] ?? 0)],
                ['Close adjustment', $this->money($companySettings, $provision['unposted_tax_charge_adjustment'] ?? $provision['unposted_corporation_tax_adjustment'] ?? 0)],
                ['Tax basis', $this->badge($freezeReady ? 'success' : 'danger', $freezeReady ? 'Ready to freeze' : 'Action required'), true],
            ]) . '
        </section>
        <section class="panel-soft stack' . $unsupportedFeaturesClass . '">
            <div class="summary-card-header"><h3 class="card-title">Corporation Tax Items Requiring Review</h3>' . $unsupportedFeaturesPill . '</div>'
            . $this->diagnosticsHtml(
                (array)($taxReadiness['blocking_diagnostics'] ?? []),
                'Corporation Tax Items Requiring Review',
                $blockerCount === 0 ? 'No amount-affecting Corporation Tax issues remain.' : '',
                false,
                true
            ) . '
        </section>';
    }

    private function provisionHtml(array $companySettings, array $provision): string
    {
        if (empty($provision['available'])) {
            return '<section class="panel-soft stack">
                <h3 class="card-title">CT Provision At Close</h3>
                <div class="helper">' . \eel_accounts\Support\Utf8::html((string)($provision['errors'][0] ?? 'Corporation Tax provision status is not available.')) . '</div>
            </section>';
        }

        $status = (string)($provision['status'] ?? 'not_posted');
        $unposted = round((float)($provision['unposted_corporation_tax_adjustment'] ?? 0), 2);
        $statusHelp = in_array($status, ['posted', 'not_required'], true)
            ? 'The ledger provision is current for the latest CT estimate.'
            : 'The final Year End close will post or refresh the CT provision before retained earnings are closed.';

        return '<section class="panel-soft stack">
            <h3 class="card-title">CT Provision At Close</h3>
            ' . $this->summaryGrid([
                ['Total Corporation Tax Due to HMRC', $this->money($companySettings, $provision['estimated_corporation_tax'] ?? 0)],
                ['L2P relief receivable', $this->money($companySettings, $provision['l2p_relief_receivable'] ?? 0)],
                ['Net tax charge in the accounts', $this->money($companySettings, $provision['estimated_tax_charge'] ?? $provision['estimated_corporation_tax'] ?? 0)],
                ['Posted to 8500/2200', $this->money($companySettings, $provision['posted_corporation_tax_charge'] ?? 0)],
                ['Close adjustment', $this->money($companySettings, $provision['unposted_tax_charge_adjustment'] ?? $unposted)],
                ['Status', $this->badge($this->provisionBadgeClass($status), $this->provisionLabel($status)), true],
            ]) . '
            <div class="helper">' . \eel_accounts\Support\Utf8::html($statusHelp) . '</div>
        </section>';
    }

    private function provisionBadgeClass(string $status): string
    {
        return match ($status) {
            'posted', 'not_required' => 'success',
            'out_of_date' => 'warning',
            default => 'danger',
        };
    }

    private function provisionLabel(string $status): string
    {
        return match ($status) {
            'posted' => 'Provision posted',
            'not_required' => 'No provision needed',
            'out_of_date' => 'Provision stale',
            'not_posted' => 'Provision missing',
            default => HelperFramework::labelFromKey($status, '_'),
        };
    }

    private function ctPeriodSectionsHtml(array $companySettings, array $taxReadiness, int $companyId, int $accountingPeriodId): string
    {
        $periods = $this->periods($taxReadiness);
        if ($periods === []) {
            return '<section class="panel-soft stack">
                <h3 class="card-title">CT Periods In This Accounting Period</h3>
                <div class="helper">No CT period summaries are available for this accounting period.</div>
            </section>';
        }

        $html = '<section class="stack">
            <h3 class="card-title">CT Periods In This Accounting Period</h3>';
        foreach ($periods as $period) {
            $html .= $this->ctPeriodHtml($companySettings, $period);
        }

        return $html . '</section>';
    }

    private function ctPeriodHtml(array $companySettings, array $period): string
    {
        $diagnostics = array_values(array_filter(
            (array)($period['hard_gate_diagnostics'] ?? []),
            static fn(mixed $diagnostic): bool => is_array($diagnostic) && !empty($diagnostic['amount_affecting'])
        ));
        $diagnosticCount = count($diagnostics);
        $basisStatus = $diagnosticCount === 0
            ? $this->badge('success', 'Ready')
            : $this->badge('danger', $diagnosticCount . ' action' . ($diagnosticCount === 1 ? '' : 's') . ' required');

        return '<section class="panel-soft stack">
            <h3 class="card-title">' . \eel_accounts\Support\Utf8::html($this->periodTitle($period)) . '</h3>
            ' . $this->summaryGrid([
                ['Actual trading turnover', $this->money($companySettings, $period['actual_trading_turnover'] ?? 0)],
                ['CT600 box 145', $this->money($companySettings, $period['ct600_box_145_turnover'] ?? 0)],
                ['Taxable profit', $this->money($companySettings, $period['taxable_profit'] ?? 0)],
                ['CT600 profit-tax liability', $this->money($companySettings, $period['ordinary_corporation_tax'] ?? 0)],
                ['Net S455 tax', $this->money($companySettings, $period['s455_tax'] ?? 0)],
                ['CT600A net tax payable [A80]', $this->money($companySettings, $period['ct600a_tax'] ?? 0)],
                ['Total Corporation Tax Due to HMRC', $this->money($companySettings, $period['estimated_corporation_tax'] ?? 0)],
                ['Effective rate', $this->percent($period['estimated_rate'] ?? null)],
                ['Tax basis status', $basisStatus, true],
            ]) . $this->turnoverRoundingHtml($companySettings, $period) . '
            <h3 class="card-title">Taxable Profit Bridge</h3>
            ' . $this->table(['Step', 'Amount'], $this->bridgeRows($companySettings, $period), 'No taxable profit bridge is available for this CT period.') . '
            <h3 class="card-title">Loss Movement</h3>
            ' . $this->summaryGrid([
                ['Brought forward', $this->money($companySettings, $period['losses_brought_forward'] ?? $period['loss_brought_forward'] ?? 0)],
                ['Created in period', $this->money($companySettings, $period['loss_created_in_period'] ?? $period['loss_created'] ?? 0)],
                ['Used', $this->money($companySettings, $period['losses_used'] ?? $period['loss_utilised'] ?? 0)],
                ['Carried forward', $this->money($companySettings, $period['losses_carried_forward'] ?? $period['loss_carried_forward'] ?? 0)],
            ]) . '
            <h3 class="card-title">Rate Bands</h3>
            ' . $this->rateBandsHtml($companySettings, $period) . '
            ' . $this->diagnosticsHtml($diagnostics, 'Adjustments required for this CT period', 'No amount-affecting issues remain for this CT period.') . '
        </section>';
    }

    private function turnoverRoundingHtml(array $companySettings, array $period): string
    {
        if (empty($period['handles_ct600_turnover_rounding_residual'])) {
            return '';
        }
        $adjustment = (float)($period['ct600_turnover_rounding_adjustment'] ?? 0);
        if (abs($adjustment) < 0.005) {
            return '';
        }

        return '<div class="helper">This is the shortest CT period, so it absorbs the '
            . \eel_accounts\Support\Utf8::html($this->money($companySettings, $adjustment))
            . ' whole-pound rounding residual. The CT-period box 145 values therefore equal accounting-period turnover.</div>';
    }

    private function bridgeRows(array $companySettings, array $period): array
    {
        $rows = [
            ['Accounting profit or loss', $this->money($companySettings, $period['accounting_profit'] ?? 0)],
            ['Add back disallowable expenses', $this->money($companySettings, $period['disallowable_add_backs'] ?? 0)],
            ['Add back depreciation', $this->money($companySettings, $period['depreciation_add_back'] ?? 0)],
            ['Add back capital expenditure', $this->money($companySettings, $period['capital_add_backs'] ?? 0)],
        ];
        $roundingAdjustment = round((float)(
            $period['accounting_allocation_basis']['apportionment_rounding_adjustment'] ?? 0
        ), 2);
        if (abs($roundingAdjustment) >= 0.005) {
            $rows[] = [
                'Apportionment rounding adjustment',
                $this->money($companySettings, $roundingAdjustment),
            ];
        }

        return array_merge($rows, [
            ['Deduct capital allowances', $this->money($companySettings, 0 - (float)($period['capital_allowances'] ?? 0))],
            ['Taxable result before losses', $this->money($companySettings, $period['taxable_before_losses'] ?? 0)],
            ['Less losses used', $this->money($companySettings, 0 - (float)($period['losses_used'] ?? $period['loss_utilised'] ?? 0))],
            ['Taxable profit after losses', $this->money($companySettings, $period['taxable_profit'] ?? 0)],
            ['Net Corporation Tax liability [CT600 box 475]', $this->money($companySettings, $period['ordinary_corporation_tax'] ?? 0)],
        ]);
    }

    private function rateBandsHtml(array $companySettings, array $period): string
    {
        $rows = [];
        foreach ((array)($period['ct_rate_bands'] ?? []) as $band) {
            if (!is_array($band)) {
                continue;
            }

            $rows[] = [
                (string)($band['financial_year'] ?? ''),
                $this->money($companySettings, $band['taxable_profit'] ?? 0),
                $this->percent($band['main_rate'] ?? null),
                $this->percent($band['small_profits_rate'] ?? null),
                $this->money($companySettings, $band['marginal_relief'] ?? 0),
                $this->money($companySettings, $band['liability'] ?? 0),
                HelperFramework::labelFromKey((string)($band['basis'] ?? ''), '_'),
            ];
        }

        return $this->table(
            ['Financial Year (FY)', 'Taxable profit', 'Main rate', 'Small profits', 'Marginal relief', 'Liability', 'Basis'],
            $rows,
            'No rate bands apply because taxable profit is nil.'
        );
    }

    private function diagnosticsHtml(
        array $diagnostics,
        string $title,
        string $emptyMessage,
        bool $includeTitle = true,
        bool $includeLoanConfirmationLink = false
    ): string
    {
        $diagnostics = array_values(array_filter(
            $diagnostics,
            static fn(mixed $diagnostic): bool => is_array($diagnostic)
                && trim((string)($diagnostic['message'] ?? '')) !== ''
        ));

        if ($diagnostics === []) {
            return '<div class="helper">' . $this->badge('success', 'Ready') . ' ' . \eel_accounts\Support\Utf8::html($emptyMessage) . '</div>';
        }

        $html = '<section class="stack">' . ($includeTitle ? '<h3 class="card-title">' . \eel_accounts\Support\Utf8::html($title) . '</h3>' : '');
        foreach ($diagnostics as $diagnostic) {
            $message = trim((string)$diagnostic['message']);
            $html .= '<div class="helper">' . \eel_accounts\Support\Utf8::html($message) . '</div>';
            if ($includeLoanConfirmationLink && $this->requiresSection464Review($diagnostic)) {
                $html .= '<div class="actions-row"><a class="button button-inline" href="?page=loans&amp;show_card=year_end_loan_confirmation">'
                    . 'Open Director Loan Year End Confirmation</a></div>';
            }
        }

        return $html . '</section>';
    }

    private function requiresSection464Review(array $diagnostic): bool
    {
        $text = strtolower(
            (string)($diagnostic['code'] ?? '') . ' ' . (string)($diagnostic['message'] ?? '')
        );

        return str_contains($text, '464a')
            || str_contains($text, '464c')
            || str_contains($text, 's464');
    }

    private function reviewApprovalHtml(string $acknowledgementForm): string
    {
        return '<section class="panel-soft stack">
            <h3 class="card-title">Tax Basis Review And Approval</h3>
            ' . $acknowledgementForm . '
        </section>';
    }

    private function summaryGrid(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            $html .= $this->summaryCard(
                (string)($item[0] ?? ''),
                (string)($item[1] ?? ''),
                (bool)($item[2] ?? false)
            );
        }

        return '<div class="summary-grid four">' . $html . '</div>';
    }

    private function summaryCard(string $label, string $value, bool $trustedValue = false): string
    {
        return '<div class="summary-card"><div class="summary-label">'
            . \eel_accounts\Support\Utf8::html($label)
            . '</div><div class="summary-value">'
            . ($trustedValue ? $value : \eel_accounts\Support\Utf8::html($value))
            . '</div></div>';
    }

    private function table(array $headers, array $rows, string $emptyMessage): string
    {
        if ($rows === []) {
            return '<div class="helper">' . \eel_accounts\Support\Utf8::html($emptyMessage) . '</div>';
        }

        $head = '';
        foreach ($headers as $header) {
            $head .= '<th>' . \eel_accounts\Support\Utf8::html((string)$header) . '</th>';
        }

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ((array)$row as $cell) {
                $body .= '<td>' . \eel_accounts\Support\Utf8::html((string)$cell) . '</td>';
            }
            $body .= '</tr>';
        }

        return '<div class="table-scroll"><table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
    }

    private function badge(string $class, string $label): string
    {
        return '<span class="badge ' . \eel_accounts\Support\Utf8::html($class) . '">' . \eel_accounts\Support\Utf8::html($label) . '</span>';
    }

    private function percent(mixed $value): string
    {
        if ($value === null || trim((string)$value) === '') {
            return '-';
        }

        return number_format(((float)$value) * 100, 2) . '%';
    }

    private function periods(array $taxReadiness): array
    {
        return array_values(array_filter(
            (array)($taxReadiness['periods'] ?? []),
            static fn(mixed $period): bool => is_array($period)
        ));
    }

    private function periodTitle(array $period): string
    {
        $sequenceNo = (int)($period['ct_period_display_sequence_no'] ?? ($period['ct_period_sequence_no'] ?? 0));
        $prefix = $sequenceNo > 0 ? 'CT Period ' . $sequenceNo : 'CT Period';
        $heading = $this->periodHeading($period);

        return $heading !== 'CT period' ? $prefix . ': ' . $heading : $prefix;
    }

    private function periodHeading(array $period): string
    {
        $label = trim((string)($period['period_label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        $start = trim((string)($period['period_start'] ?? ''));
        $end = trim((string)($period['period_end'] ?? ''));
        return trim($start . ' to ' . $end) !== 'to' ? trim($start . ' to ' . $end) : 'CT period';
    }
}
