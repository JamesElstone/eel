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

        if (empty($taxReadiness['available'])) {
            return '<section class="settings-stack" id="tax-readiness"><div class="helper">' . HelperFramework::escape((string)($taxReadiness['errors'][0] ?? 'Tax readiness is not available.')) . '</div></section>';
        }

        $provision = (array)($taxReadiness['provision'] ?? []);
        $taxBasisReady = (string)($taxReadiness['freeze_status'] ?? '') === 'ready_for_approval';
        $check = $this->check((array)($checklist['checks_flat'] ?? []), 'tax_readiness_acknowledgement');
        $acknowledgement = (array)($check['review_acknowledgement'] ?? $check['previous_acknowledgement'] ?? []);
        $acknowledged = !empty($check['acknowledgement_current']);
        $acknowledgementForm = $this->acknowledgementHtml(
            $acknowledged,
            (string)($check['acknowledgement_state'] ?? 'absent'),
            (string)($acknowledgement['acknowledged_at'] ?? ''),
            (string)($acknowledgement['acknowledged_by'] ?? ''),
            (string)($acknowledgement['note'] ?? ''),
            $companyId,
            $accountingPeriodId,
            $this->money($companySettings, $taxReadiness['estimated_corporation_tax'] ?? 0),
            $taxBasisReady
        );

        return '<section class="settings-stack" id="tax-readiness">
            ' . $this->overallTaxPositionHtml($companySettings, $taxReadiness, $provision) . '
            ' . $this->corporationTaxScope($filingScope, $companyId, $accountingPeriodId) . '
            ' . $this->ctPeriodSectionsHtml($companySettings, $taxReadiness, $companyId, $accountingPeriodId) . '
            ' . $this->provisionHtml($companySettings, $provision) . '
            ' . $this->reviewApprovalHtml($acknowledgementForm) . '
        </section>';
    }

    private function corporationTaxScope(array $scope, int $companyId, int $accountingPeriodId): string
    {
        if (empty($scope['available'])) {
            return '<section class="panel-soft"><h3 class="card-title">Corporation Tax Filling Scope Check</h3><div class="standout helper">'
                . HelperFramework::escape((string)(($scope['errors'] ?? [])[0] ?? 'The Corporation Tax scope review is unavailable.')) . '</div></section>';
        }
        $answers = (array)($scope['answers'] ?? []);
        $rows = '';
        foreach ((array)($scope['definitions'] ?? []) as $key => $definition) {
            $answer = (string)($answers[$key] ?? 'yes');
            if (!in_array($answer, ['yes', 'no'], true)) {
                $answer = 'yes';
            }
            $rows .= '<tr><td>' . HelperFramework::escape((string)$definition['page']) . '</td>'
                . '<td>' . HelperFramework::escape((string)$definition['label']) . '</td>'
                . '<td>' . HelperFramework::escape((string)$definition['question']) . '</td>'
                . '<td class="year-end-tax-scope-guidance"><a class="button button-inline" target="_blank" rel="noopener noreferrer" href="' . HelperFramework::escape((string)$definition['url']) . '">HMRC guidance</a></td>'
                . '<td><form method="post" action="?page=corporation_tax" data-ajax="true">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="card_action" value="Ixbrl"><input type="hidden" name="intent" value="save_ct_filing_scope_answer">'
                . '<input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
                . '<input type="hidden" name="scope_field" value="' . HelperFramework::escape((string)$key) . '">'
                . '<div class="actions-row actions-row-nowrap year-end-tax-scope-answer">' . $this->scopeRadio((string)$key, 'no', 'No', $answer)
                . $this->scopeRadio((string)$key, 'yes', 'Yes', $answer) . '</div></form></td></tr>';
        }
        return '<section class="panel-soft settings-stack"><h3 class="card-title">Corporation Tax Filling Scope Check</h3>'
            . '<div class="table-scroll"><table class="year-end-tax-scope-table"><thead><tr><th>Supplement ID</th><th>Supplement Name</th><th>Question</th><th>HMRC Guidance</th><th>Answer</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div></section>';
    }

    private function scopeRadio(string $field, string $value, string $label, string $selected): string
    {
        $id = 'ct_scope_' . $field . '_' . $value;
        return '<label for="' . $id . '"><input id="' . $id . '" type="radio" name="scope_answer" value="' . $value
            . '" required data-submit-on-change="true"' . ($selected === $value ? ' checked' : '') . '> ' . $label . '</label>';
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

    private function acknowledgementHtml(bool $acknowledged, string $state, string $acknowledgedAt, string $acknowledgedBy, string $note, int $companyId, int $accountingPeriodId, string $totalCorporationTaxDue, bool $taxBasisReady): string
    {
        return \eel_accounts\Renderer\YearEndApprovalRenderer::render([
            'subject' => 'Total Corporation Tax Due to HMRC, including the CT600A position and associated-company count for every CT period',
            'confirmationText' => 'I confirm the Total Corporation Tax Due to HMRC of ' . $totalCorporationTaxDue . ' shown above is the amount the company will pay to HMRC for this accounting period.',
            'companyId' => $companyId,
            'accountingPeriodId' => $accountingPeriodId,
            'acknowledged' => $acknowledged,
            'acknowledgementState' => $state,
            'acknowledgedAt' => $acknowledgedAt,
            'acknowledgedBy' => $acknowledgedBy,
            'note' => $note,
            'intent' => 'save_tax_readiness_acknowledgement',
            'revokeIntent' => 'save_tax_readiness_acknowledgement',
            'checkboxName' => 'tax_readiness_acknowledgement',
            'approveFields' => ['tax_readiness_acknowledgement' => '1'],
            'revokeFields' => ['tax_readiness_acknowledgement' => '0'],
            'disabled' => !$taxBasisReady,
            'disabledReason' => $taxBasisReady ? '' : 'Year End Confirmation is disabled until all tax basis checks have passed.',
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
        $unsupportedFeaturesPill = $hasUnsupportedFeatures ? $this->badge('danger', 'Not supported') : '';

        return '<section class="panel-soft stack">
            <h3 class="card-title">Overall Tax Position</h3>
            ' . $this->summaryGrid([
                ['CT periods', (string)$periodCount],
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
            <div class="summary-card-header"><h3 class="card-title">Features not supported by EEL Accounts</h3>' . $unsupportedFeaturesPill . '</div>'
            . $this->diagnosticsHtml(
                (array)($taxReadiness['blocking_diagnostics'] ?? []),
                'Features not supported by EEL Accounts',
                $blockerCount === 0 ? 'No amount-affecting Corporation Tax issues remain.' : '',
                false
            ) . '
        </section>';
    }

    private function provisionHtml(array $companySettings, array $provision): string
    {
        if (empty($provision['available'])) {
            return '<section class="panel-soft stack">
                <h3 class="card-title">CT Provision At Close</h3>
                <div class="helper">' . HelperFramework::escape((string)($provision['errors'][0] ?? 'Corporation Tax provision status is not available.')) . '</div>
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
            <div class="helper">' . HelperFramework::escape($statusHelp) . '</div>
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
            <h3 class="card-title">' . HelperFramework::escape($this->periodTitle($period)) . '</h3>
            ' . $this->summaryGrid([
                ['Taxable profit', $this->money($companySettings, $period['taxable_profit'] ?? 0)],
                ['CT600 profit-tax liability', $this->money($companySettings, $period['ordinary_corporation_tax'] ?? 0)],
                ['Net S455 tax', $this->money($companySettings, $period['s455_tax'] ?? 0)],
                ['CT600A net tax payable [A80]', $this->money($companySettings, $period['ct600a_tax'] ?? 0)],
                ['Total Corporation Tax Due to HMRC', $this->money($companySettings, $period['estimated_corporation_tax'] ?? 0)],
                ['Effective rate', $this->percent($period['estimated_rate'] ?? null)],
                ['Tax basis status', $basisStatus, true],
            ]) . '
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

    private function bridgeRows(array $companySettings, array $period): array
    {
        return [
            ['Accounting profit or loss', $this->money($companySettings, $period['accounting_profit'] ?? 0)],
            ['Add back disallowable expenses', $this->money($companySettings, $period['disallowable_add_backs'] ?? 0)],
            ['Add back depreciation', $this->money($companySettings, $period['depreciation_add_back'] ?? 0)],
            ['Add back capital expenditure', $this->money($companySettings, $period['capital_add_backs'] ?? 0)],
            ['Deduct capital allowances', $this->money($companySettings, 0 - (float)($period['capital_allowances'] ?? 0))],
            ['Taxable result before losses', $this->money($companySettings, $period['taxable_before_losses'] ?? 0)],
            ['Less losses used', $this->money($companySettings, 0 - (float)($period['losses_used'] ?? $period['loss_utilised'] ?? 0))],
            ['Taxable profit after losses', $this->money($companySettings, $period['taxable_profit'] ?? 0)],
            ['Ordinary Corporation Tax [CT600 box 475]', $this->money($companySettings, $period['ordinary_corporation_tax'] ?? 0)],
        ];
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

    private function diagnosticsHtml(array $diagnostics, string $title, string $emptyMessage, bool $includeTitle = true): string
    {
        $messages = array_values(array_filter(array_map(
            static fn(mixed $diagnostic): string => is_array($diagnostic)
                ? trim((string)($diagnostic['message'] ?? ''))
                : '',
            $diagnostics
        ), static fn(string $message): bool => $message !== ''));

        if ($messages === []) {
            return '<div class="helper">' . $this->badge('success', 'Ready') . ' ' . HelperFramework::escape($emptyMessage) . '</div>';
        }

        $html = '<section class="stack">' . ($includeTitle ? '<h3 class="card-title">' . HelperFramework::escape($title) . '</h3>' : '');
        foreach ($messages as $message) {
            $html .= '<div class="helper">' . HelperFramework::escape($message) . '</div>';
        }

        return $html . '</section>';
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
            . HelperFramework::escape($label)
            . '</div><div class="summary-value">'
            . ($trustedValue ? $value : HelperFramework::escape($value))
            . '</div></div>';
    }

    private function table(array $headers, array $rows, string $emptyMessage): string
    {
        if ($rows === []) {
            return '<div class="helper">' . HelperFramework::escape($emptyMessage) . '</div>';
        }

        $head = '';
        foreach ($headers as $header) {
            $head .= '<th>' . HelperFramework::escape((string)$header) . '</th>';
        }

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ((array)$row as $cell) {
                $body .= '<td>' . HelperFramework::escape((string)$cell) . '</td>';
            }
            $body .= '</tr>';
        }

        return '<div class="table-scroll"><table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
    }

    private function badge(string $class, string $label): string
    {
        return '<span class="badge ' . HelperFramework::escape($class) . '">' . HelperFramework::escape($label) . '</span>';
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
