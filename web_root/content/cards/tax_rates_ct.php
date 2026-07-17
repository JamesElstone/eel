<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _tax_rates_ctCard extends CardBaseFramework
{
    private const PAGE_SIZE = 5;
    private const FILTER_FIELD = 'tax_rates_ct_status';

    public function key(): string
    {
        return 'tax_rates_ct';
    }

    public function title(): string
    {
        return 'Corporation Tax, Allowance and FRS 105 Thresholds';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['tax.rates', 'page.context'];
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);
        $pageContext[$this->key()]['status_filter'] = $this->normaliseStatusFilter((string)$request->input(
            self::FILTER_FIELD,
            (string)(($pageContext[$this->key()] ?? [])['status_filter'] ?? 'active')
        ));

        return $pageContext;
    }

    public function tables(array $context): array
    {
        $statusFilter = $this->selectedStatusFilter($context);

        return [$this->table($this->filteredRules($context, $statusFilter), $statusFilter)];
    }

    public function render(array $context): string
    {
        $statusFilter = $this->selectedStatusFilter($context);
        $rules = $this->filteredRules($context, $statusFilter);
        $pagination = HelperFramework::paginateArray($rules, $this->paginationPage($context), self::PAGE_SIZE);
        $table = $this->table($rules, $statusFilter)
            ->visibleRows((array)$pagination['items'])
            ->toolbarActions($this->refreshRatesAction($statusFilter, count($rules) === 0))
            ->pagination(
                $pagination,
                'sourced tax and allowance rules',
                $this->paginationPageField(),
                [
                    'page' => (string)($context['page']['page_id'] ?? 'tax_rates'),
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => [$this->key()],
                    self::FILTER_FIELD => $statusFilter,
                ]
            )
            ->filterSelect(
                self::FILTER_FIELD,
                'Show',
                $this->statusFilterOptions(),
                $statusFilter,
                [
                    'page' => (string)($context['page']['page_id'] ?? 'tax_rates'),
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => [$this->key()],
                ]
            );

        return '<div class="settings-stack">' . $table->render($context, [
            'cards[]' => (array)($context['page']['page_cards'] ?? []),
            self::FILTER_FIELD => $statusFilter,
        ]) . '</div>';
    }

    private function table(array $rules, string $statusFilter): TableFramework
    {
        return TableFramework::make($this->key(), $rules)
            ->filename('tax-and-allowance-rates')
            ->empty($statusFilter === 'active'
                ? 'No active sourced tax or allowance rules are stored yet.'
                : 'No sourced tax or allowance rules are stored yet.')
            ->column(
                'domain_label',
                'Domain'
            )
            ->column(
                'regime_label',
                'Regime'
            )
            ->column(
                'rule_label',
                'Rule'
            )
            ->column(
                'period',
                'Period',
                html: fn(array $row): string => HelperFramework::escape($this->periodLabel($row)),
                export: fn(array $row): string => $this->periodLabel($row)
            )
            ->column(
                'value_summary',
                'Value'
            )
            ->column(
                'status',
                'Status',
                html: fn(array $row): string => $this->statusHtml((int)($row['is_active'] ?? 0) === 1),
                export: fn(array $row): string => $this->statusLabel((int)($row['is_active'] ?? 0) === 1)
            )
            ->column('rule_version', 'Version')
            ->column('source_updated_at', 'Source updated')
            ->column('source_checked_at', 'Checked');
    }

    private function filteredRules(array $context, string $statusFilter): array
    {
        $rules = array_values(array_filter(
            (array)($context['tax_rates_ct']['rules'] ?? []),
            static fn(mixed $rule): bool => is_array($rule)
        ));

        if ($this->normaliseStatusFilter($statusFilter) === 'all') {
            return $rules;
        }

        return array_values(array_filter(
            $rules,
            static fn(array $rule): bool => (int)($rule['is_active'] ?? 0) === 1
        ));
    }

    private function selectedStatusFilter(array $context): string
    {
        return $this->normaliseStatusFilter((string)(($context[$this->key()] ?? [])['status_filter'] ?? 'active'));
    }

    private function normaliseStatusFilter(string $status): string
    {
        $status = strtolower(trim($status));

        return array_key_exists($status, $this->statusFilterOptions()) ? $status : 'active';
    }

    private function statusFilterOptions(): array
    {
        return [
            'active' => 'Active',
            'all' => 'All',
        ];
    }

    private function refreshRatesAction(string $statusFilter, bool $isEmpty): string
    {
        $buttonClass = $isEmpty ? 'button danger' : 'button primary';
        $buttonLabel = $isEmpty ? 'Import Live His Majesty\'s Revenue and Customs (HMRC) Rates and FRS 105 thresholds' : 'Refresh HMRC Rates and FRS 105 thresholds';

        return '<form method="post" action="?page=tax_rates" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="card_action" value="TaxRates">
            <input type="hidden" name="intent" value="refresh_hmrc_rates">
            <input type="hidden" name="' . HelperFramework::escape(self::FILTER_FIELD) . '" value="' . HelperFramework::escape($this->normaliseStatusFilter($statusFilter)) . '">
            <button class="' . HelperFramework::escape($buttonClass) . '" type="submit">' . HelperFramework::escape($buttonLabel) . '</button>
        </form>';
    }

    private function statusHtml(bool $isActive): string
    {
        return '<span class="badge ' . HelperFramework::escape($isActive ? 'success' : 'info') . '">'
            . HelperFramework::escape($this->statusLabel($isActive))
            . '</span>';
    }

    private function statusLabel(bool $isActive): string
    {
        return $isActive ? 'Active' : 'Superseded';
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
    }

    private function periodLabel(array $rule): string
    {
        $start = trim((string)($rule['period_start'] ?? ''));
        $end = trim((string)($rule['period_end'] ?? ''));
        if ($start === '' && $end === '') {
            return '-';
        }
        if ($end === '') {
            return $start . ' onwards';
        }

        return $start . ' to ' . $end;
    }

}
