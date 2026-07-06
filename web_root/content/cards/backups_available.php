<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _backups_availableCard extends CardBaseFramework
{
    private const PAGE_SIZE = 8;
    private array $renderContext = [];

    public function key(): string
    {
        return 'backups_available';
    }

    public function title(): string
    {
        return 'Backups Available';
    }

    public function helper(array $context): string
    {
        return 'Review, export, or restore zipped SQL database backups from the sqldump folder.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'available_backups',
                'service' => \eel_accounts\Service\DatabaseBackupService::class,
                'method' => 'fetchAvailableBackups',
            ],
        ];
    }

    public function invalidationFacts(): array
    {
        return ['backup.database'];
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);

        return $this->applyTableSortContext($request, $pageContext, $this->key());
    }

    public function tables(array $context): array
    {
        return [$this->configuredTable($context)];
    }

    public function render(array $context): string
    {
        return $this->configuredTable($context)->render($context, [
            'cards[]' => (array)($context['page']['page_cards'] ?? []),
        ]);
    }

    private function configuredTable(array $context): TableFramework
    {
        $this->renderContext = $context;
        $hiddenFields = [
            'page' => (string)($context['page']['page_id'] ?? 'backup'),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
        ];

        $table = $this->configureTableSorting($this->table($context), $context, $hiddenFields);
        $rows = $table->sortedRows();
        $pagination = HelperFramework::paginateArray($rows, $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Database backups',
                $this->paginationPageField(),
                $hiddenFields
            );
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'page.reload');
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('database-backups')
            ->exportLimit(5000)
            ->empty('No zipped SQL backups have been created yet.')
            ->column(
                'created_at',
                'Created',
                html: static fn(array $row): string => HelperFramework::escape((string)($row['created_at'] ?? '')),
                export: static fn(array $row): string => (string)($row['created_at'] ?? ''),
                sort: static fn(array $row): string => (string)($row['created_at'] ?? '')
            )
            ->column(
                'filename',
                'Filename',
                html: static fn(array $row): string => '<strong>' . HelperFramework::escape((string)($row['filename'] ?? 'backup.sql.zip')) . '</strong>',
                export: static fn(array $row): string => (string)($row['filename'] ?? ''),
                sort: true
            )
            ->column(
                'size_bytes',
                'Size',
                html: fn(array $row): string => HelperFramework::escape($this->formatBytes((int)($row['size_bytes'] ?? 0))),
                export: static fn(array $row): string => (string)(int)($row['size_bytes'] ?? 0),
                cellClass: 'cell-fit',
                exportType: 'number',
                sort: static fn(array $row): int => (int)($row['size_bytes'] ?? 0)
            )
            ->column(
                'restore',
                'Restore',
                html: fn(array $row): string => $this->restoreForm($row),
                cellClass: 'cell-fit',
                exportable: false,
                sort: false
            );
    }

    private function rows(array $context): array
    {
        $rows = (array)($context['services']['available_backups'] ?? []);
        usort($rows, static function (array $left, array $right): int {
            $createdComparison = strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));

            return $createdComparison !== 0
                ? $createdComparison
                : strcmp((string)($right['filename'] ?? ''), (string)($left['filename'] ?? ''));
        });

        return array_values($rows);
    }

    private function restoreForm(array $row): string
    {
        $filename = (string)($row['filename'] ?? '');
        if ($filename === '') {
            return '';
        }

        return '<form class="inline-form" method="post" action="?page=backup" data-ajax="true">
            ' . $this->hiddenFields() . '
            <input type="hidden" name="card_action" value="Backup">
            <input type="hidden" name="intent" value="restore_database_backup">
            <input type="hidden" name="backup_filename" value="' . HelperFramework::escape($filename) . '">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($this->csrfToken()) . '">
            <input class="input" name="restore_confirmation" placeholder="RESTORE" autocomplete="off" required>
            <button class="button secondary" type="submit" data-processing-text="Restoring" data-processing-state="disabled">Restore</button>
        </form>';
    }

    private function hiddenFields(): string
    {
        $html = '';
        foreach ((array)($this->renderContext['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        return $html;
    }

    private function csrfToken(): string
    {
        return (string)($this->renderContext['page']['csrf_token'] ?? '');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / 1048576, 1) . ' MB';
    }
}
