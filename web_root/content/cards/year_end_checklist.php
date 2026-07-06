<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_checklistCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'year_end_checklist';
    }

    public function title(): string
    {
        return 'Year End Checklist';
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
        $checklist = $this->checklist($context);
        if ($checklist === []) {
            return '<div class="helper">Year-end checklist is not available for the selected accounting period.</div>';
        }

        return $this->renderOverallStatus($checklist)
            . $this->renderBookkeepingSection($checklist)
            . $this->renderCheckSections($checklist);
    }

    private function renderOverallStatus(array $checklist): string
    {
        $status = (string)($checklist['overall_status'] ?? '');

        return '<section class="panel-soft settings-stack">
            <div class="status-head">
                <h3 class="card-title">Overall status</h3>
                <span class="badge ' . HelperFramework::escape($this->badgeClass($status)) . '">' . HelperFramework::escape($this->overallStatusLabel($status)) . '</span>
            </div>
        </section>';
    }

    private function renderBookkeepingSection(array $checklist): string
    {
        $checks = (array)(($checklist['sections'] ?? [])['bookkeeping_completeness'] ?? []);
        if ($checks === []) {
            return '';
        }

        $companyId = (int)($checklist['company_id'] ?? 0);
        $accountingPeriod = (array)($checklist['accounting_period'] ?? []);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? 0);
        $transactionsButton = \eel_accounts\Renderer\WorkflowHandoffRenderer::button(
            'transactions',
            'Open Related Workflow',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );
        $status = $this->sectionStatus($checks);
        $statusClass = $this->badgeClass($status);
        $monthTiles = (array)($checklist['month_tiles'] ?? []);
        $totalMonths = count($monthTiles);
        $okMonths = count(array_filter($monthTiles, static fn(array $tile): bool => in_array((string)($tile['status'] ?? ''), ['green', 'complete', 'pass', 'ok'], true)));
        $coverageValue = $totalMonths > 0 ? $okMonths . ' of ' . $totalMonths : '';

        return '<section class="panel-soft settings-stack">
            <h3 class="card-title">A. Bookkeeping completeness</h3>
            <div class="summary-grid">
                <div class="summary-card year-end-check-panel year-end-check-panel-' . HelperFramework::escape($statusClass) . '">
                    <div class="status-head">
                        <div class="summary-label">Transaction coverage</div>
                        <span class="year-end-check-status-label">' . HelperFramework::escape(HelperFramework::labelFromKey($status, '_')) . '</span>
                    </div>
                    ' . ($coverageValue !== '' ? '<div class="summary-value">' . HelperFramework::escape($coverageValue) . '</div>' : '') . '
                    <div class="helper">Review the transaction and monthly coverage detail from the Transactions page.</div>
                    <div class="year-end-related-workflow">' . $transactionsButton . '</div>
                </div>
            </div>
        </section>';
    }

    private function sectionStatus(array $checks): string
    {
        $hasWarning = false;
        foreach ($checks as $check) {
            $status = (string)(((array)$check)['status'] ?? '');
            if ($status === 'fail' || $status === 'needs_attention') {
                return 'needs_attention';
            }
            if ($status === 'warning' || $status === 'not_started') {
                $hasWarning = true;
            }
        }

        return $hasWarning ? 'warning' : 'pass';
    }

    private function renderCheckSections(array $checklist): string
    {
        $sections = (array)($checklist['sections'] ?? []);
        unset($sections['bookkeeping_completeness']);
        $accountingPeriod = (array)($checklist['accounting_period'] ?? []);
        $companyId = (int)($checklist['company_id'] ?? 0);
        $accountingPeriodId = (int)($accountingPeriod['id'] ?? 0);

        $html = '';
        foreach ($sections as $key => $checks) {
            $sectionKey = (string)$key;
            $html .= '<section class="panel-soft settings-stack"><h3 class="card-title">' . HelperFramework::escape($this->sectionTitle($sectionKey)) . '</h3><div class="summary-grid">';
            foreach ((array)$checks as $check) {
                $html .= $this->renderSummaryCheck((array)$check, $companyId, $accountingPeriodId);
            }
            $html .= '</div></section>';
        }

        return $html;
    }

    private function renderSummaryCheck(array $check, int $companyId, int $accountingPeriodId): string
    {
        $metricValue = trim((string)($check['metric_value'] ?? ''));
        $status = (string)($check['status'] ?? '');
        $statusClass = $this->badgeClass($status);
        $reviewActionHtml = $this->reviewActionHtml($check, $companyId, $accountingPeriodId);
        $formulaText = trim((string)($check['formula_text'] ?? ''));
        $workflowHtml = \eel_accounts\Renderer\WorkflowHandoffRenderer::fromWorkflow(
            $check,
            'Open Related Workflow',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );
        $actionsHtml = trim($workflowHtml . $reviewActionHtml) !== ''
            ? '<div class="year-end-related-workflow">' . $workflowHtml . $reviewActionHtml . '</div>'
            : '';

        return '<div class="summary-card year-end-check-panel year-end-check-panel-' . HelperFramework::escape($statusClass) . '">
            <div class="status-head">
                <div class="summary-label">' . HelperFramework::escape((string)($check['title'] ?? '')) . '</div>
                <span class="year-end-check-status-label">' . HelperFramework::escape(HelperFramework::labelFromKey($status, '_')) . '</span>
            </div>
            ' . ($metricValue !== '' ? '<div class="summary-value">' . HelperFramework::escape($metricValue) . '</div>' : '') . '
            <div class="helper">' . HelperFramework::escape((string)($check['detail_text'] ?? '')) . '</div>
            ' . ($formulaText !== '' ? '<div class="helper">' . HelperFramework::escape($formulaText) . '</div>' : '') . '
            ' . $actionsHtml . '
        </div>';
    }

    private function reviewActionHtml(array $check, int $companyId, int $accountingPeriodId): string
    {
        if (empty($check['review_clearable']) || $companyId <= 0 || $accountingPeriodId <= 0) {
            return '';
        }

        $checkCode = (string)($check['check_code'] ?? '');
        if ($checkCode === '') {
            return '';
        }
        if (in_array($checkCode, ['cut_off_journals_review', 'prepayment_approvals'], true)) {
            return '';
        }

        $acknowledgement = $check['review_acknowledgement'] ?? null;
        $isAcknowledged = is_array($acknowledgement);
        $status = (string)($check['status'] ?? '');
        if (!$isAcknowledged && $status !== 'warning') {
            return '';
        }

        $intent = $isAcknowledged ? 'reopen_review_check' : 'acknowledge_review_check';
        $label = $isAcknowledged ? 'Reopen review' : 'Mark reviewed';
        $buttonClass = $isAcknowledged ? 'button' : 'button primary';
        $confirmAttributes = $isAcknowledged
            ? ''
            : ' data-chicken-check="true" data-chicken-title="Mark review complete" data-chicken-message="This will mark this year-end warning as reviewed for the selected accounting period.<br><br>Continue?" data-chicken-confirm-text="Mark Reviewed" data-chicken-button-class="button primary"';

        return '<form method="post" action="?page=year_end" data-ajax="true">
                <input type="hidden" name="card_action" value="YearEnd">
                <input type="hidden" name="intent" value="' . HelperFramework::escape($intent) . '">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="check_code" value="' . HelperFramework::escape($checkCode) . '">
                <button class="' . HelperFramework::escape($buttonClass) . '" type="submit"' . $confirmAttributes . '>' . HelperFramework::escape($label) . '</button>
            </form>';
    }

    private function sectionTitle(string $key): string
    {
        return match ($key) {
            'categorisation_suspense' => 'B. Categorisation and suspense',
            'ledger_integrity' => 'C. Ledger integrity',
            'bank_source_completeness' => 'D. Bank and source completeness',
            'director_loan_expenses' => 'E. Director loan and expense claims',
            'year_end_accounts_review' => 'F. Year end accounts review',
            'corporation_tax_readiness' => 'G. Corporation tax readiness',
            'companies_house_comparison' => 'H. Companies House comparison',
            'final_review_lock' => 'I. Final review and lock',
            default => HelperFramework::labelFromKey($key, '_'),
        };
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'pass', 'ready', 'locked' => 'success',
            'ready_for_review' => 'success',
            'fail', 'needs_attention' => 'danger',
            'warning', 'not_started' => 'warning',
            default => 'info',
        };
    }

    private function overallStatusLabel(string $status): string
    {
        return match ($status) {
            'ready_for_review' => 'Ready to Close and Lock',
            'locked' => 'Locked',
            'in_progress' => 'Checks Need Review',
            'needs_attention' => 'Needs Attention',
            'not_started' => 'Not Started',
            default => HelperFramework::labelFromKey($status, '_'),
        };
    }

    private function checklist(array $context): array
    {
        return (array)(($context['year_end'] ?? [])['checklist'] ?? (($context['services'] ?? [])['yearEndChecklist'] ?? []));
    }
}
