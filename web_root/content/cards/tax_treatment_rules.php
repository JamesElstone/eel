<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _tax_treatment_rulesCard extends CardBaseFramework
{
    private const PAGE_SIZE = 10;
    private const FILTER_FIELD = 'tax_treatment_rules_status';

    public function key(): string
    {
        return 'tax_treatment_rules';
    }

    public function title(): string
    {
        return 'Corporation Tax Treatment Rules';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['tax.treatment.rules', 'page.context'];
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
            ->pagination(
                $pagination,
                'Corporation Tax treatment rules',
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
            ->filename('corporation-tax-treatment-rules')
            ->empty($statusFilter === 'active'
                ? 'No active Corporation Tax treatment rules are stored yet.'
                : 'No Corporation Tax treatment rules are stored yet.')
            ->column('priority', 'Priority', exportType: 'number')
            ->column('rule_code', 'Rule')
            ->column(
                'match',
                'Match',
                html: fn(array $row): string => HelperFramework::escape($this->matchLabel($row)),
                export: fn(array $row): string => $this->matchLabel($row)
            )
            ->column(
                'tax_treatment',
                'Treatment',
                html: fn(array $row): string => HelperFramework::escape(AccountingFormattingService::nominalTaxTreatmentLabel((string)($row['tax_treatment'] ?? 'allowable'))),
                export: fn(array $row): string => AccountingFormattingService::nominalTaxTreatmentLabel((string)($row['tax_treatment'] ?? 'allowable'))
            )
            ->column(
                'effective',
                'Effective',
                html: fn(array $row): string => HelperFramework::escape($this->effectiveLabel($row)),
                export: fn(array $row): string => $this->effectiveLabel($row)
            )
            ->column(
                'source_url',
                'Source',
                html: fn(array $row): string => $this->sourceLink($row),
                export: fn(array $row): string => (string)($row['source_url'] ?? '')
            )
            ->column('source_checked_at', 'Checked')
            ->column(
                'review_status',
                'Review',
                html: fn(array $row): string => $this->reviewStatusSelectHtml($row, $statusFilter),
                export: fn(array $row): string => (string)($row['review_status'] ?? 'seeded')
            )
            ->column(
                'status',
                'Status',
                html: fn(array $row): string => $this->statusHtml((int)($row['is_active'] ?? 0) === 1),
                export: fn(array $row): string => $this->statusLabel((int)($row['is_active'] ?? 0) === 1)
            )
            ->column(
                'actions',
                '',
                html: fn(array $row): string => $this->actionsHtml($row),
                exportable: false
            );
    }

    private function filteredRules(array $context, string $statusFilter): array
    {
        $rules = array_values(array_filter(
            (array)($context['tax_treatment_rules']['rules'] ?? []),
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

    private function actionsHtml(array $rule): string
    {
        $ruleId = (int)($rule['id'] ?? 0);
        $isActive = (int)($rule['is_active'] ?? 0) === 1;
        $buttonText = $isActive ? 'Disable' : 'Enable';
        $chickenAttributes = $isActive
            ? ' data-chicken-check="true" data-chicken-message="Disable this Corporation Tax treatment rule?<br><br>Future CT estimates will fall back to the next matching rule or nominal default." data-chicken-confirm-text="Disable"'
            : '';

        return '<form method="post" action="?page=tax_rates" data-ajax="true">
            <input type="hidden" name="card_action" value="TaxRates">
            <input type="hidden" name="intent" value="toggle_tax_treatment_rule">
            <input type="hidden" name="rule_id" value="' . $ruleId . '">
            <input type="hidden" name="target_is_active" value="' . ($isActive ? '0' : '1') . '">
            <button class="button" type="submit"' . $chickenAttributes . '>' . HelperFramework::escape($buttonText) . '</button>
        </form>';
    }

    private function matchLabel(array $rule): string
    {
        $parts = [];
        $nominalCode = trim((string)($rule['nominal_code'] ?? ''));
        if ($nominalCode !== '') {
            $parts[] = 'Nominal ' . $nominalCode;
        }

        $nominalAccountId = (int)($rule['nominal_account_id'] ?? 0);
        if ($nominalCode === '' && $nominalAccountId > 0) {
            $parts[] = 'Nominal #' . $nominalAccountId;
        }

        $accountType = trim((string)($rule['account_type'] ?? ''));
        if ($accountType !== '') {
            $parts[] = 'Type ' . $accountType;
        }

        $nameContains = trim((string)($rule['name_contains'] ?? ''));
        if ($nameContains !== '') {
            $parts[] = 'Name contains "' . $nameContains . '"';
        }

        return $parts !== [] ? implode(' | ', $parts) : 'All nominals';
    }

    private function effectiveLabel(array $rule): string
    {
        $from = trim((string)($rule['effective_from'] ?? ''));
        $to = trim((string)($rule['effective_to'] ?? ''));

        if ($from === '' && $to === '') {
            return 'Any period';
        }

        return ($from !== '' ? $from : 'Start') . ' to ' . ($to !== '' ? $to : 'Open');
    }

    private function sourceLink(array $rule): string
    {
        $url = trim((string)($rule['source_url'] ?? ''));
        if ($url === '') {
            return '';
        }

        return '<a class="text-link" href="' . HelperFramework::escape($url) . '" target="_blank" rel="noopener noreferrer">'
            . HelperFramework::escape($this->sourceLabel($url))
            . '</a>';
    }

    private function sourceLabel(string $url): string
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $slug = trim(basename($path));

        return $slug !== '' ? $slug : $url;
    }

    private function reviewStatusSelectHtml(array $rule, string $statusFilter): string
    {
        $ruleId = (int)($rule['id'] ?? 0);
        $currentStatus = $this->normaliseReviewStatus((string)($rule['review_status'] ?? 'seeded'));
        $options = '';

        foreach ($this->reviewStatusOptions() as $value => $label) {
            $options .= '<option value="' . HelperFramework::escape($value) . '"'
                . ($currentStatus === $value ? ' selected' : '')
                . '>' . HelperFramework::escape($label) . '</option>';
        }

        return '<form method="post" action="?page=tax_rates" data-ajax="true">
            <input type="hidden" name="card_action" value="TaxRates">
            <input type="hidden" name="intent" value="update_tax_treatment_rule_review_status">
            <input type="hidden" name="rule_id" value="' . $ruleId . '">
            <input type="hidden" name="' . self::FILTER_FIELD . '" value="' . HelperFramework::escape($statusFilter) . '">
            <select class="select" name="review_status" aria-label="Review status">' . $options . '</select>
        </form>';
    }

    private function statusHtml(bool $isActive): string
    {
        return '<span class="badge ' . HelperFramework::escape($isActive ? 'success' : 'warning') . '">'
            . HelperFramework::escape($this->statusLabel($isActive))
            . '</span>';
    }

    private function statusLabel(bool $isActive): string
    {
        return $isActive ? 'Active' : 'Disabled';
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

    private function normaliseReviewStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return array_key_exists($status, $this->reviewStatusOptions()) ? $status : 'seeded';
    }

    private function reviewStatusOptions(): array
    {
        return [
            'seeded' => 'Seeded',
            'needs_review' => 'Needs Review',
            'reviewed' => 'Reviewed',
        ];
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
    }
}
