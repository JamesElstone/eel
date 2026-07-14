<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _hmrc_fines_tableCard extends CardBaseFramework
{
    private const PAGE_SIZE = 15;

    public function key(): string { return 'hmrc_fines_table'; }

    public function title(): string { return 'HMRC Fines & Interest'; }

    public function helper(array $context): string
    {
        return 'HMRC penalties post to 6230 and HMRC interest posts to 6231, with the unpaid balance held in 2210 HMRC Penalties & Interest Payable. Later bank payments should clear 2210.';
    }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);
        $pageContext = $this->applyTableSortContext($request, $pageContext, $this->key());
        $pageContext[$this->key()]['period_scope'] = $this->normalisePeriodScope((string)$request->input(
            'hmrc_fines_period_scope',
            (string)(($pageContext[$this->key()] ?? [])['period_scope'] ?? 'all')
        ));

        return $pageContext;
    }

    public function render(array $context): string
    {
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);

        $form = '<section class="panel-soft">
            <h3 class="card-title">Record HMRC notice</h3>
            <form method="post" action="?page=hmrc_obligations" data-ajax="true" class="form-grid">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="HmrcObligation">
                <input type="hidden" name="intent" value="create_manual_obligation">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <section class="form-grid full" data-state-fields="hmrc_notice_type,hmrc_notice_date,hmrc_notice_due_date,hmrc_notice_amount_due,hmrc_notice_reference" data-state-target="save_hmrc_notice_button">
                    <div class="form-row"><label for="hmrc_notice_type">Type *</label><select class="select" id="hmrc_notice_type" name="obligation_type" required data-no-submit-on-change="true"><option value="">Select type...</option><option value="hmrc_penalty">HMRC penalty</option><option value="hmrc_interest">HMRC interest</option><option value="other">Other HMRC balance</option></select></div>
                    <div class="form-row"><label for="hmrc_notice_date">Notice date *</label><input class="input" id="hmrc_notice_date" type="date" name="notice_date" required></div>
                    <div class="form-row"><label for="hmrc_notice_due_date">Due date *</label><input class="input" id="hmrc_notice_due_date" type="date" name="due_date" required></div>
                    <div class="form-row"><label for="hmrc_notice_amount_due">Amount due *</label><input class="input" id="hmrc_notice_amount_due" type="number" step="0.01" min="0.01" name="amount_due" required></div>
                    <div class="form-row"><label for="hmrc_notice_reference">HMRC reference *</label><input class="input" id="hmrc_notice_reference" name="source_reference" required></div>
                    <div class="form-row"><label for="hmrc_notice_notes">Notes / evidence path</label><input class="input" id="hmrc_notice_notes" name="notes"></div>
                    <div class="actions-row"><button class="button primary" id="save_hmrc_notice_button" type="submit" disabled>Record Notice</button></div>
                </section>
            </form>
        </section>';

        $table = $this->configuredTable($context, $companySettings);

        return '<div class="settings-stack">' . $form . $table->render($context, [
            'cards[]' => (array)($context['page']['page_cards'] ?? []),
            'hmrc_fines_period_scope' => $this->selectedPeriodScope($context),
        ]) . '</div>';
    }

    public function tables(array $context): array
    {
        return [$this->configuredTable($context, (array)(($context['company'] ?? [])['settings'] ?? []))];
    }

    private function configuredTable(array $context, array $companySettings): TableFramework
    {
        $periodScope = $this->selectedPeriodScope($context);
        $hiddenFields = [
            'page' => (string)($context['page']['page_id'] ?? 'hmrc_obligations'),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
            'hmrc_fines_period_scope' => $periodScope,
        ];
        $table = $this->configureTableSorting($this->table($context, $companySettings), $context, $hiddenFields);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination($pagination, 'HMRC notices', $this->paginationPageField(), $hiddenFields)
            ->filterSelect(
                'hmrc_fines_period_scope',
                'Show',
                $this->periodScopeOptions(),
                $periodScope,
                [
                    'page' => (string)($context['page']['page_id'] ?? 'hmrc_obligations'),
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => [$this->key()],
                ]
            );
    }

    private function table(array $context, array $companySettings): TableFramework
    {
        return TableFramework::make($this->key(), $this->filteredRows($context))
            ->filename('hmrc-fines-and-interest')
            ->exportLimit(5000)
            ->empty('No HMRC fines or interest match the selected period filter.')
            ->column(
                'notice_date',
                'Notice date',
                html: fn(array $row): string => HelperFramework::escape($this->displayDate((string)($row['notice_date'] ?? ''), $companySettings)),
                export: static fn(array $row): string => (string)($row['notice_date'] ?? ''),
                exportType: 'date'
            )
            ->textColumn('accounting_period_label', 'Period')
            ->column(
                'obligation_type',
                'Type',
                html: static fn(array $row): string => HelperFramework::escape(HelperFramework::labelFromKey((string)($row['obligation_type'] ?? ''), '_')),
                export: static fn(array $row): string => HelperFramework::labelFromKey((string)($row['obligation_type'] ?? ''), '_')
            )
            ->textColumn('due_date', 'Due date', exportType: 'date')
            ->column(
                'amount_due',
                'Due',
                html: fn(array $row): string => HelperFramework::escape(($row['amount_due'] ?? null) === null ? 'Not set' : $this->money($companySettings, $row['amount_due'])),
                export: static fn(array $row): string => ($row['amount_due'] ?? null) === null ? '' : number_format((float)$row['amount_due'], 2, '.', ''),
                exportType: 'number'
            )
            ->column(
                'amount_paid',
                'Paid',
                html: fn(array $row): string => HelperFramework::escape($this->money($companySettings, $row['amount_paid'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['amount_paid'] ?? 0), 2, '.', ''),
                exportType: 'number'
            )
            ->column(
                'effective_status',
                'Status',
                html: fn(array $row): string => '<span class="badge ' . HelperFramework::escape($this->badgeClass((string)($row['effective_status'] ?? ''))) . '">'
                    . HelperFramework::escape(HelperFramework::labelFromKey((string)($row['effective_status'] ?? ''), '_')) . '</span>',
                export: static fn(array $row): string => HelperFramework::labelFromKey((string)($row['effective_status'] ?? ''), '_')
            )
            ->column(
                'related_journal_id',
                'Accrual',
                html: static fn(array $row): string => HelperFramework::escape((int)($row['related_journal_id'] ?? 0) > 0 ? 'Accrued' : 'No accrual'),
                export: static fn(array $row): string => (int)($row['related_journal_id'] ?? 0) > 0 ? 'Accrued' : 'No accrual'
            )
            ->textColumn('source_reference', 'Reference')
            ->column(
                'actions',
                'Actions',
                html: fn(array $row): string => $this->deleteActionHtml(
                    $row,
                    (int)($context['company']['id'] ?? 0),
                    $this->selectedPeriodScope($context)
                ),
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function deleteActionHtml(array $row, int $companyId, string $periodScope): string
    {
        $obligationId = (int)($row['id'] ?? 0);
        $accountingPeriodId = (int)($row['accounting_period_id'] ?? 0);
        if ($obligationId <= 0 || !(new \eel_accounts\Service\AccountingPeriodAccessService())
            ->isDataEntryPermitted($companyId, $accountingPeriodId)) {
            return '';
        }

        return '<form method="post" action="?page=hmrc_obligations" data-ajax="true" class="actions-row">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="card_action" value="HmrcObligation">
            <input type="hidden" name="intent" value="delete_manual_obligation">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="obligation_id" value="' . $obligationId . '">
            <input type="hidden" name="hmrc_fines_period_scope" value="' . HelperFramework::escape($periodScope) . '">
            <button class="button danger" type="submit" data-chicken-check="true" data-chicken-message="Delete this HMRC fine or interest record and its linked accrual journal?" data-chicken-confirm-text="Delete">Delete</button>
        </form>';
    }

    private function filteredRows(array $context): array
    {
        $currentAccountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);
        $rows = array_values(array_filter(
            (array)($context['hmrc_obligations']['all_obligations'] ?? []),
            static fn(mixed $item): bool => is_array($item)
                && in_array((string)($item['obligation_type'] ?? ''), ['hmrc_penalty', 'hmrc_interest'], true)
        ));

        if ($this->selectedPeriodScope($context) === 'current') {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $item): bool => (int)($item['accounting_period_id'] ?? 0) === $currentAccountingPeriodId
            ));
        }

        usort($rows, static function (array $left, array $right): int {
            $dateComparison = strcmp((string)($right['notice_date'] ?? ''), (string)($left['notice_date'] ?? ''));

            return $dateComparison !== 0
                ? $dateComparison
                : ((int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0));
        });

        return $rows;
    }

    private function selectedPeriodScope(array $context): string
    {
        return $this->normalisePeriodScope((string)(($context[$this->key()] ?? [])['period_scope'] ?? 'all'));
    }

    private function normalisePeriodScope(string $scope): string
    {
        return array_key_exists($scope, $this->periodScopeOptions()) ? $scope : 'all';
    }

    private function periodScopeOptions(): array
    {
        return [
            'all' => 'All',
            'current' => 'In current Accounting Period',
        ];
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'overdue' => 'danger',
            'paid', 'filed' => 'success',
            'ready', 'in_progress', 'part_paid' => 'info',
            'not_applicable' => 'muted',
            default => 'warning',
        };
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function displayDate(string $date, array $companySettings): string
    {
        $format = (string)($companySettings['date_format'] ?? 'd/m/Y');
        if (!in_array($format, ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y'], true)) {
            $format = 'd/m/Y';
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));

        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === trim($date)
            ? $parsed->format($format)
            : $date;
    }
}
