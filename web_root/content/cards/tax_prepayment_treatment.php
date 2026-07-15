<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _tax_prepayment_treatmentCard extends CardBaseFramework
{
    private const PAGE_SIZE = 5;

    public function key(): string
    {
        return 'tax_prepayment_treatment';
    }

    public function title(): string
    {
        return 'Prepayment Accounting Treatment';
    }

    public function helper(array $context): string
    {
        return 'This is an accounting-period view, even when a Corporation Tax period is selected above. Prepayment journals change the ordinary P&L and balance sheet; this card does not create a separate tax adjustment.';
    }

    public function services(): array
    {
        return [[
            'key' => 'prepayment_period_context',
            'service' => \eel_accounts\Service\PrepaymentScheduleService::class,
            'method' => 'fetchPeriodContext',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ]];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['prepayments.state', 'year.end.state', 'tax.workings'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        return $this->applyPaginationContext($request, parent::handle($request, $services, $pageContext, $actionResult));
    }

    public function tables(array $context): array
    {
        return [$this->table($this->tableRows($context))];
    }

    public function render(array $context): string
    {
        $periodContext = (array)($context['services']['prepayment_period_context'] ?? []);
        if (empty($periodContext['available'])) {
            return $this->guidanceLinks()
                . $this->messages((array)($periodContext['errors'] ?? ['Prepayment schedules are not available.']));
        }

        $settings = (array)($context['company']['settings'] ?? ($context['page']['settings'] ?? []));
        $schedules = (array)($periodContext['schedules'] ?? []);
        if ($schedules === []) {
            return $this->guidanceLinks()
                . $this->accountingDisclosure()
                . '<div class="helper">No prepayment schedule overlaps the selected accounting period.</div>';
        }

        $warnings = (array)($periodContext['errors'] ?? []);
        foreach ($schedules as $schedule) {
            if (!is_array($schedule)) {
                continue;
            }
            $sourceLabel = $this->sourceLabel($schedule);
            $unallocatedPence = (int)($schedule['unallocated_pence'] ?? 0);
            if ($unallocatedPence > 0) {
                $warnings[] = $sourceLabel . ' has ' . $this->moneyPence($settings, $unallocatedPence)
                    . ' of future service not yet assigned because the later accounting period has not been created.';
            }
            foreach ((array)($schedule['source_errors'] ?? []) as $error) {
                $warnings[] = $sourceLabel . ': ' . (string)$error;
            }
        }

        $rows = $this->tableRows($context);
        $pagination = HelperFramework::paginateArray($rows, $this->paginationPage($context), self::PAGE_SIZE);
        $hiddenFields = [
            'page' => (string)($context['page']['page_id'] ?? 'tax'),
            '_pagination' => '1',
            '_invalidate_fact' => 'prepayments.state',
            'cards[]' => [$this->key()],
        ];
        $table = $this->table($rows)
            ->visibleRows((array)$pagination['items'])
            ->pagination($pagination, 'Prepayment schedules', $this->paginationPageField(), $hiddenFields);

        return $this->guidanceLinks()
            . $this->accountingDisclosure()
            . '<div class="summary-grid">'
            . $this->summary('Accounting Period Expense', $this->moneyPence($settings, (int)($periodContext['total_expense_pence'] ?? 0)))
            . $this->summary('Closing Prepayments asset', $this->moneyPence($settings, (int)($periodContext['total_closing_deferred_pence'] ?? 0)))
            . '</div>'
            . $table->render($context, ['cards[]' => (array)($context['page']['page_cards'] ?? [])])
            . $this->messages(array_values(array_unique(array_filter(array_map('strval', $warnings)))));
    }

    /** @return list<array<string, mixed>> */
    private function tableRows(array $context): array
    {
        $settings = (array)($context['company']['settings'] ?? ($context['page']['settings'] ?? []));
        $rows = [];
        foreach ((array)($context['services']['prepayment_period_context']['schedules'] ?? []) as $schedule) {
            if (!is_array($schedule)) {
                continue;
            }
            $allocation = (array)($schedule['selected_allocation'] ?? []);
            $amountPence = (int)($schedule['source_amount_pence'] ?? 0);
            $expensePence = (int)($allocation['expense_pence'] ?? 0);
            $closingPence = (int)($allocation['closing_deferred_pence'] ?? 0);
            $recognisedThroughPence = (int)($allocation['recognised_through_pence'] ?? ($amountPence - $closingPence));
            $recognisedBeforePence = $recognisedThroughPence - $expensePence;
            $overlapDays = (int)($allocation['overlap_days'] ?? 0);
            $totalDays = (int)($schedule['total_days'] ?? 0);
            $journalState = (string)($allocation['journal_state'] ?? $schedule['journal_state'] ?? 'not_posted');
            $sourceLabel = $this->sourceLabel($schedule);
            $sourceDetail = trim((string)($schedule['source_date'] ?? '') . ' · '
                . trim((string)($schedule['expense_nominal_code'] ?? '') . ' ' . (string)($schedule['expense_nominal_name'] ?? '')), ' ·');
            $servicePeriod = (string)($schedule['service_start_date'] ?? '') . ' to ' . (string)($schedule['service_end_date'] ?? '');
            $calculation = $this->moneyPence($settings, $recognisedThroughPence) . ' cumulative - '
                . $this->moneyPence($settings, $recognisedBeforePence) . ' before = ' . $this->moneyPence($settings, $expensePence);
            $calculationDetail = $overlapDays . ' of ' . $totalDays . ' inclusive days; cumulative half-up rounding. '
                . (string)($allocation['overlap_start'] ?? '') . ' to ' . (string)($allocation['overlap_end'] ?? '');
            $postingDetail = trim(HelperFramework::labelFromKey((string)($allocation['posting_role'] ?? ''), '_')
                . ' target ' . $this->moneyPence($settings, (int)($allocation['posting_target_pence'] ?? 0)));

            $rows[] = [
                'source' => $sourceLabel,
                'source_html' => '<strong>' . HelperFramework::escape($sourceLabel) . '</strong>'
                    . ($sourceDetail !== '' ? '<br><span class="helper">' . HelperFramework::escape($sourceDetail) . '</span>' : ''),
                'purchase_pence' => $amountPence,
                'purchase_html' => HelperFramework::escape($this->moneyPence($settings, $amountPence)),
                'service_period' => $servicePeriod,
                'service_period_html' => HelperFramework::escape($servicePeriod) . '<br><span class="helper">' . $totalDays . ' inclusive days</span>',
                'calculation' => $calculation . ' ' . $calculationDetail,
                'calculation_html' => HelperFramework::escape($calculation) . '<br><span class="helper">' . HelperFramework::escape($calculationDetail) . '</span>',
                'expense_pence' => $expensePence,
                'expense_html' => HelperFramework::escape($this->moneyPence($settings, $expensePence)),
                'closing_pence' => $closingPence,
                'closing_html' => HelperFramework::escape($this->moneyPence($settings, $closingPence)),
                'journal_state' => $journalState,
                'journal_state_html' => $this->journalStateHtml($journalState, $postingDetail),
                'journal_state_export' => $journalState === 'preview_only'
                    ? $postingDetail
                    : trim(HelperFramework::labelFromKey($journalState, '_') . ' - ' . $postingDetail, ' -'),
            ];
        }
        return $rows;
    }

    private function table(array $rows): TableFramework
    {
        return TableFramework::make($this->key(), $rows)
            ->filename('prepayment-accounting-treatment')
            ->exportLimit(5000)
            ->empty('No prepayment schedule overlaps the selected accounting period.')
            ->classes(wrapperClass: 'table-scroll panel-soft')
            ->column('source', 'Source', html: static fn(array $row): string => (string)($row['source_html'] ?? ''), export: true)
            ->column('purchase_pence', 'Purchase', html: static fn(array $row): string => (string)($row['purchase_html'] ?? ''), export: static fn(array $row): string => number_format(((int)($row['purchase_pence'] ?? 0)) / 100, 2, '.', ''), headerClass: 'numeric', cellClass: 'numeric', exportType: 'number')
            ->column('service_period', 'Service period', html: static fn(array $row): string => (string)($row['service_period_html'] ?? ''), export: true)
            ->column('calculation', 'Inclusive-day calculation', html: static fn(array $row): string => (string)($row['calculation_html'] ?? ''), export: true)
            ->column('expense_pence', 'AP expense', html: static fn(array $row): string => (string)($row['expense_html'] ?? ''), export: static fn(array $row): string => number_format(((int)($row['expense_pence'] ?? 0)) / 100, 2, '.', ''), headerClass: 'numeric', cellClass: 'numeric', exportType: 'number')
            ->column('closing_pence', 'Closing deferred', html: static fn(array $row): string => (string)($row['closing_html'] ?? ''), export: static fn(array $row): string => number_format(((int)($row['closing_pence'] ?? 0)) / 100, 2, '.', ''), headerClass: 'numeric', cellClass: 'numeric', exportType: 'number')
            ->column('journal_state', 'Journal state', html: static fn(array $row): string => (string)($row['journal_state_html'] ?? ''), export: static fn(array $row): string => (string)($row['journal_state_export'] ?? ''));
    }

    private function sourceLabel(array $schedule): string
    {
        $sourceLabel = trim((string)($schedule['source_description'] ?? ''));
        return $sourceLabel !== ''
            ? $sourceLabel
            : HelperFramework::labelFromKey((string)($schedule['source_type'] ?? 'source'), '_') . ' #' . (int)($schedule['source_id'] ?? 0);
    }

    private function journalStateHtml(string $journalState, string $postingDetail): string
    {
        $detail = '<span class="helper">' . HelperFramework::escape($postingDetail) . '</span>';
        if ($journalState === 'preview_only') {
            return $detail;
        }
        $class = $journalState === 'posted' ? 'success' : ($journalState === 'correction_required' ? 'warning' : 'info');
        return '<span class="badge ' . $class . '">' . HelperFramework::escape(HelperFramework::labelFromKey($journalState, '_'))
            . '</span><br>' . $detail;
    }

    private function accountingDisclosure(): string
    {
        return '<div class="helper">Amounts use cumulative half-up rounding in integer pennies and inclusive calendar days, so all accounting-period allocations reconcile exactly to the purchase. The final Year End close posts one initial deferral and direct releases in later periods. No monthly release journals are created in this phase, so a later period\'s allocation appears in its first service month while the accounting-period total remains exact.</div>';
    }

    private function guidanceLinks(): string
    {
        return '<div class="actions-row">'
            . \eel_accounts\Renderer\TaxCardRenderer::guidanceLink('bim42201', 'HMRC - BIM42201')
            . \eel_accounts\Renderer\TaxCardRenderer::guidanceLink('bim70066', 'HMRC - BIM70066')
            . \eel_accounts\Renderer\TaxCardRenderer::guidanceLink('frs105', 'FRC - FRS 105')
            . '</div>';
    }

    private function summary(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label)
            . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function messages(array $messages): string
    {
        if ($messages === []) {
            return '';
        }

        return '<div class="helper"><ul><li>'
            . implode('</li><li>', array_map(static fn(mixed $message): string => HelperFramework::escape((string)$message), $messages))
            . '</li></ul></div>';
    }

    private function moneyPence(array $settings, int $pence): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($settings, $pence / 100);
    }
}
