<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _tax_rates_vatCard extends CardBaseFramework
{
    private const PAGE_SIZE = 13;

    public function key(): string { return 'tax_rates_vat'; }
    public function title(): string { return 'VAT Rates'; }

    public function services(): array
    {
        return [[
            'key' => 'vat_rate_rules',
            'service' => \eel_accounts\Service\VatRateRuleService::class,
            'method' => 'fetchRules',
        ]];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function helper(array $context): string
    {
        return 'Headline standard, reduced and zero rates sourced from GOV.UK. These rates do not classify a particular supply or replace the detailed VAT notices.';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['vat.rate.rules', 'page.context'];
    }

    public function handle(RequestFramework $request, PageServiceFramework $services, array $pageContext, ActionResultFramework $actionResult): array
    {
        return $this->applyPaginationContext($request, parent::handle($request, $services, $pageContext, $actionResult));
    }

    public function tables(array $context): array
    {
        return [$this->table($this->rules($context))];
    }

    public function render(array $context): string
    {
        $rules = $this->rules($context);
        $pagination = HelperFramework::paginateArray($rules, $this->paginationPage($context), self::PAGE_SIZE);
        $hiddenFields = [
            'page' => (string)($context['page']['page_id'] ?? 'tax_artifacts'),
            '_pagination' => '1',
            '_invalidate_fact' => 'vat.rate.rules',
            'cards[]' => [$this->key()],
        ];
        $table = $this->table($rules)
            ->visibleRows((array)$pagination['items'])
            ->toolbarActions($this->refreshAction($rules === []))
            ->pagination($pagination, 'VAT rate rules', $this->paginationPageField(), $hiddenFields);

        return '<div class="settings-stack">'
            . $this->sourceSummary($rules)
            . $table->render($context, ['cards[]' => (array)($context['page']['page_cards'] ?? [])])
            . '</div>';
    }

    private function table(array $rules): TableFramework
    {
        return TableFramework::make($this->key(), $rules)
            ->filename('hmrc-vat-rates')
            ->exportLimit(5000)
            ->empty('No HMRC VAT rates are stored. Use Import Live HMRC VAT Rates to populate this table.')
            ->classes(wrapperClass: 'table-scroll tax-rates-vat-table')
            ->column('period', 'Period', html: fn(array $row): string => HelperFramework::escape($this->period($row)), export: fn(array $row): string => $this->period($row))
            ->column('rate_type', 'Rate Type', html: static fn(array $row): string => HelperFramework::escape(ucwords(str_replace('_', ' ', (string)($row['rate_type'] ?? '')))))
            ->column('scope', 'Scope', html: static fn(array $row): string => HelperFramework::escape(strtoupper((string)($row['scope'] ?? 'uk'))))
            ->column(
                'rate_percentage',
                'VAT Rate',
                html: static fn(array $row): string => HelperFramework::escape(rtrim(rtrim(number_format((float)($row['rate_percentage'] ?? 0), 3, '.', ''), '0'), '.') . '%'),
                export: static fn(array $row): string => number_format((float)($row['rate_percentage'] ?? 0), 3, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            );
    }

    private function rules(array $context): array
    {
        $rules = $context['services']['vat_rate_rules']
            ?? $context[$this->key()]['rules']
            ?? [];

        return array_values(array_filter(
            (array)$rules,
            static fn(mixed $row): bool => is_array($row) && (int)($row['is_active'] ?? 1) === 1
        ));
    }

    private function refreshAction(bool $empty): string
    {
        $label = $empty ? 'Import Live HMRC VAT Rates' : 'Refresh HMRC VAT Rates';
        return '<form method="post" action="?page=tax_artifacts" data-ajax="true">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="TaxRatesVat">'
            . '<input type="hidden" name="intent" value="refresh_hmrc_vat_rates">'
            . '<button class="button ' . ($empty ? 'danger' : 'primary') . '" type="submit">' . HelperFramework::escape($label) . '</button>'
            . '</form>';
    }

    private function sourceSummary(array $rules): string
    {
        if ($rules === []) {
            return '<div class="helper">The table starts empty and is populated only by a successful GOV.UK refresh.</div>';
        }
        $row = $rules[0];
        $url = trim((string)($row['source_url'] ?? \eel_accounts\Service\VatRateRuleService::NOTICE_URL));
        return '<div class="helper">Source updated: ' . HelperFramework::escape((string)($row['source_updated_at'] ?? 'Unknown'))
            . '. Checked: ' . HelperFramework::escape((string)($row['source_checked_at'] ?? 'Unknown'))
            . '. <a class="button button-inline" href="' . HelperFramework::escape($url) . '" target="_blank" rel="noopener noreferrer">HMRC - VAT Rates</a></div>';
    }

    private function period(array $row): string
    {
        $start = trim((string)($row['effective_from'] ?? ''));
        $end = trim((string)($row['effective_to'] ?? ''));
        return $end === '' ? $start . ' onwards' : $start . ' to ' . $end;
    }
}
