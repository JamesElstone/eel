<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _nominals_accountsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'nominals_accounts';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'nominal_account_catalog',
                'service' => NominalAccountRepository::class,
                'method' => 'fetchNominalAccountCatalog',
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function tables(array $context): array
    {
        return [$this->table($context)];
    }

    public function render(array $context): string
    {
        return $this->table($context)->render($context, [
            'cards[]' => (array)($context['page']['page_cards'] ?? []),
        ]);
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('nominal-accounts')
            ->exportLimit(1000)
            ->empty('No nominal accounts were found.')
            ->textColumn('code', 'Code')
            ->textColumn('name', 'Name')
            ->textColumn('account_type', 'Type')
            ->textColumn('subtype_name', 'Subtype')
            ->textColumn('tax_treatment_label', 'Tax Treatment')
            ->column(
                'sort_order',
                'Sort',
                html: static fn(array $row): string => HelperFramework::escape((string)(int)($row['sort_order'] ?? 0)),
                export: static fn(array $row): int => (int)($row['sort_order'] ?? 0),
                exportType: 'number'
            )
            ->textColumn('status_label', 'Status')
            ->column(
                'actions',
                '',
                html: fn(array $row): string => $this->actionsHtml($row, (string)($context['page']['page_id'] ?? 'nominals')),
                exportable: false
            );
    }

    private function rows(array $context): array
    {
        $rows = [];

        foreach ((array)($context['services']['nominal_account_catalog'] ?? []) as $nominal) {
            if (!is_array($nominal)) {
                continue;
            }

            $nominal['tax_treatment_label'] = AccountingFormattingService::nominalTaxTreatmentLabel(
                (string)($nominal['tax_treatment'] ?? 'allowable')
            );
            $nominal['status_label'] = (int)($nominal['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive';

            $rows[] = $nominal;
        }

        usort($rows, static function (array $left, array $right): int {
            $codeComparison = strnatcasecmp((string)($left['code'] ?? ''), (string)($right['code'] ?? ''));

            if ($codeComparison !== 0) {
                return $codeComparison;
            }

            $nameComparison = strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));

            if ($nameComparison !== 0) {
                return $nameComparison;
            }

            return (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0);
        });

        return $rows;
    }

    private function actionsHtml(array $nominal, string $pageId): string
    {
        return '<form method="post" data-ajax="true">
            <input type="hidden" name="card_action" value="Nominals">
            <input type="hidden" name="intent" value="edit_nominal_account">
            <input type="hidden" name="page" value="' . HelperFramework::escape($pageId) . '">
            <input type="hidden" name="show_card" value="nominals_add_account">
            <input type="hidden" name="edit_nominal_id" value="' . (int)($nominal['id'] ?? 0) . '">
            <button class="button" type="submit">Edit</button>
        </form>';
    }
}
