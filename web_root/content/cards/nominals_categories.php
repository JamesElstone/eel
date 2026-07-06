<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _nominals_categoriesCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'nominals_categories';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'nominal_subtypes',
                'service' => \eel_accounts\Repository\NominalSubtypeRepository::class,
                'method' => 'fetchNominalSubtypes',
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
            ->filename('nominal-categories')
            ->exportLimit(1000)
            ->empty('No nominal categories were found.')
            ->textColumn('code', 'Code')
            ->textColumn('name', 'Name')
            ->textColumn('parent_account_type', 'Parent Type')
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

        foreach ((array)($context['services']['nominal_subtypes'] ?? []) as $subtype) {
            if (!is_array($subtype)) {
                continue;
            }

            $subtype['status_label'] = (int)($subtype['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive';
            $rows[] = $subtype;
        }

        return $rows;
    }

    private function actionsHtml(array $subtype, string $pageId): string
    {
        return '<form method="post" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="card_action" value="Nominals">
            <input type="hidden" name="intent" value="edit_nominal_subtype">
            <input type="hidden" name="page" value="' . HelperFramework::escape($pageId) . '">
            <input type="hidden" name="show_card" value="nominals_add_category">
            <input type="hidden" name="edit_subtype_id" value="' . (int)($subtype['id'] ?? 0) . '">
            <button class="button" type="submit">Edit</button>
        </form>';
    }
}
