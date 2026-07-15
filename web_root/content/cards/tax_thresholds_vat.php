<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _tax_thresholds_vatCard extends CardBaseFramework
{
    private const PAGE_SIZE = 13;

    public function key(): string { return 'tax_thresholds_vat'; }
    public function title(): string { return 'VAT Registration Thresholds'; }

    public function services(): array
    {
        return [[
            'key' => 'vat_threshold_rules',
            'service' => \eel_accounts\Service\VatThresholdRuleService::class,
            'method' => 'fetchRules',
        ]];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function helper(array $context): string
    {
        return 'Historic and current limits sourced from HMRC. Only UK taxable-supplies registration thresholds feed the VAT Threshold Monitor.';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['vat.threshold.rules', 'page.context'];
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
            'page' => (string)($context['page']['page_id'] ?? 'tax_rates'),
            '_pagination' => '1',
            '_invalidate_fact' => 'vat.threshold.rules',
            'cards[]' => [$this->key()],
        ];
        $table = $this->table($rules)
            ->visibleRows((array)$pagination['items'])
            ->toolbarActions($this->refreshAction($rules === []))
            ->pagination($pagination, 'VAT threshold rules', $this->paginationPageField(), $hiddenFields);

        return '<div class="settings-stack">'
            . $this->sourceSummary($rules)
            . $this->auditWarnings($rules)
            . $table->render($context, ['cards[]' => (array)($context['page']['page_cards'] ?? [])])
            . '</div>';
    }

    private function table(array $rules): TableFramework
    {
        return TableFramework::make($this->key(), $rules)
            ->filename('hmrc-vat-thresholds')
            ->exportLimit(5000)
            ->empty('No HMRC VAT thresholds are stored. Use Import Live HMRC VAT Thresholds to populate this table.')
            ->classes(wrapperClass: 'table-scroll tax-thresholds-vat-table')
            ->column('period', 'Period', html: fn(array $row): string => HelperFramework::escape($this->period($row)), export: fn(array $row): string => $this->period($row))
            ->column('threshold_type', 'Type', html: fn(array $row): string => HelperFramework::escape($this->typeLabel($row)), export: fn(array $row): string => $this->typeLabel($row))
            ->column(
                'registration_threshold',
                'Registration Threshold / Annual Limit',
                html: fn(array $row): string => HelperFramework::escape($this->money($row['registration_threshold'] ?? null)),
                export: static fn(array $row): string => ($row['registration_threshold'] ?? null) === null ? '' : number_format((float)$row['registration_threshold'], 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'deregistration_threshold',
                'Deregistration Threshold',
                html: fn(array $row): string => HelperFramework::escape($this->money($row['deregistration_threshold'] ?? null)),
                export: static fn(array $row): string => ($row['deregistration_threshold'] ?? null) === null ? '' : number_format((float)$row['deregistration_threshold'], 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            );
    }

    private function rules(array $context): array
    {
        $rules = $context['services']['vat_threshold_rules']
            ?? $context[$this->key()]['rules']
            ?? [];

        return array_values(array_filter(
            (array)$rules,
            static fn(mixed $row): bool => is_array($row) && (int)($row['is_active'] ?? 1) === 1
        ));
    }

    private function refreshAction(bool $empty): string
    {
        $label = $empty ? 'Import Live HMRC VAT Thresholds' : 'Refresh HMRC VAT Thresholds';
        return '<form method="post" action="?page=tax_rates" data-ajax="true">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="TaxThresholdsVat">'
            . '<input type="hidden" name="intent" value="refresh_hmrc_vat_thresholds">'
            . '<button class="button ' . ($empty ? 'danger' : 'primary') . '" type="submit">' . HelperFramework::escape($label) . '</button>'
            . '</form>';
    }

    private function sourceSummary(array $rules): string
    {
        if ($rules === []) {
            return '<div class="helper">The table starts empty and the VAT Threshold Monitor remains unavailable until a successful GOV.UK refresh.</div>';
        }
        $row = $rules[0];
        $url = trim((string)($row['source_url'] ?? \eel_accounts\Service\VatThresholdRuleService::NOTICE_URL));
        return '<div class="helper">Source updated: ' . HelperFramework::escape((string)($row['source_updated_at'] ?? 'Unknown'))
            . '. Checked: ' . HelperFramework::escape((string)($row['source_checked_at'] ?? 'Unknown'))
            . '. <a class="button button-inline" href="' . HelperFramework::escape($url) . '" target="_blank" rel="noopener noreferrer">HMRC - VAT Thresholds</a></div>';
    }

    private function auditWarnings(array $rules): string
    {
        $notes = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['audit_notes'] ?? '')),
            $rules
        ))));
        if ($notes === []) {
            return '';
        }
        return '<div class="helper"><strong>Source audit notes</strong><ul><li>'
            . implode('</li><li>', array_map(static fn(string $note): string => HelperFramework::escape($note), $notes))
            . '</li></ul></div>';
    }

    private function period(array $row): string
    {
        $start = trim((string)($row['effective_from'] ?? ''));
        $end = trim((string)($row['effective_to'] ?? ''));
        return $end === '' ? $start . ' onwards' : $start . ' to ' . $end;
    }

    private function typeLabel(array $row): string
    {
        $type = ucwords(str_replace('_', ' ', (string)($row['threshold_type'] ?? '')));
        $jurisdiction = (string)($row['jurisdiction'] ?? 'united_kingdom');
        if ($jurisdiction === 'northern_ireland') {
            return $type . ' (Northern Ireland)';
        }
        if ($jurisdiction === 'great_britain') {
            return $type . ' (Great Britain)';
        }
        return $type . ' (UK)';
    }

    private function money(mixed $amount): string
    {
        return $amount === null || $amount === '' ? 'Not stated' : '£ ' . number_format((float)$amount, 2);
    }
}
