<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class TableFramework
{
    /** @var list<TableColumnFramework> */
    private array $columns = [];
    private array $visibleRows;
    private string $emptyMessage = 'No rows were found.';
    private string $tableClass = '';
    private string $wrapperClass = 'table-scroll';
    private string $filename = 'table-export';
    private ?array $pagination = null;
    private string $paginationItemLabel = 'Rows';
    private string $paginationPageField = 'page';
    private array $paginationHiddenFields = [];
    private bool $exportsEnabled = true;
    private array $exportFormats = [
        'csv' => 'CSV',
        'xlsx' => 'XLSX',
    ];
    private int $exportLimit = 5000;
    private array $filters = [];
    private string $toolbarActionsHtml = '';
    private bool $visibleRowsConfigured = false;
    private string $sortKey = '';
    private string $sortDirection = '';
    private array $sortHiddenFields = [];

    private function __construct(private readonly string $key, private readonly array $rows)
    {
        $this->visibleRows = $rows;
    }

    public static function make(string $key, array $rows): self
    {
        return new self(HelperFramework::normaliseCardKey($key), array_values($rows));
    }

    public function key(): string
    {
        return $this->key;
    }

    public function filename(string $filename): self
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($filename)) ?? '';
        $this->filename = trim($filename, '.-') !== '' ? trim($filename, '.-') : $this->filename;

        return $this;
    }

    public function empty(string $message): self
    {
        $this->emptyMessage = $message;

        return $this;
    }

    public function classes(string $tableClass = '', string $wrapperClass = 'table-scroll'): self
    {
        $this->tableClass = trim($tableClass);
        $this->wrapperClass = trim($wrapperClass);

        return $this;
    }

    public function column(
        string $key,
        string $label,
        ?callable $html = null,
        callable|bool|null $export = null,
        string $headerClass = '',
        string $cellClass = '',
        bool $exportable = true,
        string $exportType = 'string',
        callable|bool|null $sort = null
    ): self {
        $this->columns[] = new TableColumnFramework(
            $key,
            $label,
            $html,
            $export,
            $headerClass,
            $cellClass,
            $exportable,
            $exportType,
            $sort
        );

        return $this;
    }

    public function textColumn(
        string $key,
        string $label,
        string $fallback = '',
        string $headerClass = '',
        string $cellClass = '',
        bool $exportable = true,
        string $exportType = 'string',
        callable|bool|null $sort = null
    ): self {
        return $this->column(
            $key,
            $label,
            html: static fn(array $row): string => HelperFramework::escape(self::stringValue($row[$key] ?? null, $fallback)),
            export: static fn(array $row): string => self::stringValue($row[$key] ?? null, $fallback),
            headerClass: $headerClass,
            cellClass: $cellClass,
            exportable: $exportable,
            exportType: $exportType,
            sort: $sort
        );
    }

    public function badgeColumn(
        string $key,
        string $label,
        string $badgeClass = 'info',
        string $fallback = '',
        string $labelSeparator = '_',
        ?callable $labelFormatter = null,
        ?callable $badgeClassFormatter = null,
        string $headerClass = '',
        string $cellClass = '',
        bool $exportable = true,
        callable|bool|null $sort = null
    ): self {
        $formatLabel = static function (array $row) use ($key, $fallback, $labelSeparator, $labelFormatter): string {
            $value = self::stringValue($row[$key] ?? null, $fallback);

            return $labelFormatter !== null
                ? (string)$labelFormatter($value, $row)
                : HelperFramework::labelFromKey($value, $labelSeparator, $fallback);
        };

        return $this->column(
            $key,
            $label,
            html: static function (array $row) use ($badgeClass, $badgeClassFormatter, $formatLabel): string {
                $label = $formatLabel($row);
                $class = $badgeClassFormatter !== null ? (string)$badgeClassFormatter($row) : $badgeClass;

                return '<span class="badge ' . HelperFramework::escape($class) . '">' . HelperFramework::escape($label) . '</span>';
            },
            export: static fn(array $row): string => $formatLabel($row),
            headerClass: $headerClass,
            cellClass: $cellClass,
            exportable: $exportable,
            sort: $sort
        );
    }

    public function textWithJsonSummaryColumn(
        string $key,
        string $label,
        string $jsonKey,
        string $fallback = '',
        string $separator = ' | ',
        string $headerClass = '',
        string $cellClass = '',
        bool $exportable = true,
        callable|bool|null $sort = null
    ): self {
        return $this->column(
            $key,
            $label,
            html: static function (array $row) use ($key, $jsonKey, $fallback): string {
                $primary = self::stringValue($row[$key] ?? null, $fallback);
                $summary = HelperFramework::jsonSummary(self::stringValue($row[$jsonKey] ?? null));

                return HelperFramework::escape($primary)
                    . ($summary !== '' ? '<div class="helper">' . HelperFramework::escape($summary) . '</div>' : '');
            },
            export: static function (array $row) use ($key, $jsonKey, $fallback, $separator): string {
                return self::joinTextParts([
                    self::stringValue($row[$key] ?? null, $fallback),
                    HelperFramework::jsonSummary(self::stringValue($row[$jsonKey] ?? null)),
                ], $separator);
            },
            headerClass: $headerClass,
            cellClass: $cellClass,
            exportable: $exportable,
            sort: $sort
        );
    }

    public function primarySecondaryColumn(
        string $primaryKey,
        string $label,
        string $secondaryKey,
        string $primaryFallback = '',
        string $secondaryFallback = '',
        int $secondaryPreviewLength = 96,
        string $separator = ' | ',
        string $secondaryClass = 'helper',
        string $secondaryPreviewClass = '',
        string $headerClass = '',
        string $cellClass = '',
        bool $exportable = true,
        callable|bool|null $sort = null
    ): self {
        return $this->column(
            $primaryKey,
            $label,
            html: static function (array $row) use (
                $primaryKey,
                $secondaryKey,
                $primaryFallback,
                $secondaryFallback,
                $secondaryPreviewLength,
                $secondaryClass,
                $secondaryPreviewClass
            ): string {
                $primary = self::stringValue($row[$primaryKey] ?? null, $primaryFallback);
                $secondary = self::stringValue($row[$secondaryKey] ?? null, $secondaryFallback);
                $classes = trim($secondaryClass . ' ' . $secondaryPreviewClass);
                $secondaryHtml = $secondary !== ''
                    ? '<div class="' . HelperFramework::escape($classes) . '" title="' . HelperFramework::escape($secondary) . '">'
                        . HelperFramework::escape(HelperFramework::compactText($secondary, $secondaryPreviewLength))
                        . '</div>'
                    : '';

                return HelperFramework::escape($primary) . $secondaryHtml;
            },
            export: static function (array $row) use ($primaryKey, $secondaryKey, $primaryFallback, $secondaryFallback, $separator): string {
                return self::joinTextParts([
                    self::stringValue($row[$primaryKey] ?? null, $primaryFallback),
                    self::stringValue($row[$secondaryKey] ?? null, $secondaryFallback),
                ], $separator);
            },
            headerClass: $headerClass,
            cellClass: $cellClass,
            exportable: $exportable,
            sort: $sort
        );
    }

    public function visibleRows(array $rows): self
    {
        $this->visibleRows = array_values($rows);
        $this->visibleRowsConfigured = true;

        return $this;
    }

    public function sorting(string $sortKey = '', string $direction = '', array $hiddenFields = []): self
    {
        $sortKey = trim($sortKey);
        try {
            $this->sortKey = $sortKey !== '' ? HelperFramework::normaliseCardKey($sortKey) : '';
        } catch (InvalidArgumentException) {
            $this->sortKey = '';
        }
        $this->sortDirection = $this->normaliseSortDirection($direction);
        $this->sortHiddenFields = $hiddenFields;

        if ($this->sortDirection === '') {
            $this->sortKey = '';
        }

        return $this;
    }

    public function sortFieldName(): string
    {
        return $this->key . '_sort';
    }

    public function sortDirectionFieldName(): string
    {
        return $this->key . '_sort_direction';
    }

    public function activeSortKey(): string
    {
        return $this->activeSortColumn() instanceof TableColumnFramework ? $this->sortKey : '';
    }

    public function activeSortDirection(): string
    {
        return $this->activeSortColumn() instanceof TableColumnFramework ? $this->sortDirection : '';
    }

    public function sortHiddenFields(): array
    {
        $sortKey = $this->activeSortKey();
        $direction = $this->activeSortDirection();

        return $sortKey !== '' && $direction !== ''
            ? [
                $this->sortFieldName() => $sortKey,
                $this->sortDirectionFieldName() => $direction,
            ]
            : [];
    }

    public function sortedRows(): array
    {
        $column = $this->activeSortColumn();
        if (!$column instanceof TableColumnFramework) {
            return array_values($this->rows);
        }

        $direction = $this->activeSortDirection();
        $indexed = [];
        foreach (array_values($this->rows) as $index => $row) {
            $row = is_array($row) ? $row : ['value' => $row];
            $indexed[] = [
                'index' => $index,
                'row' => $row,
                'value' => $column->sortValue($row),
            ];
        }

        usort($indexed, function (array $left, array $right) use ($column, $direction): int {
            $leftEmpty = $this->isEmptySortValue($left['value']);
            $rightEmpty = $this->isEmptySortValue($right['value']);
            if ($leftEmpty || $rightEmpty) {
                return $leftEmpty === $rightEmpty ? ((int)$left['index'] <=> (int)$right['index']) : ($leftEmpty ? 1 : -1);
            }

            $comparison = $this->compareSortValues($left['value'], $right['value'], $column->exportType());
            if ($comparison !== 0 && $direction === 'desc') {
                $comparison *= -1;
            }

            return $comparison !== 0 ? $comparison : ((int)$left['index'] <=> (int)$right['index']);
        });

        return array_map(static fn(array $item): array => $item['row'], $indexed);
    }

    public function pagination(
        array $pagination,
        string $itemLabel,
        string $pageField,
        array $hiddenFields = []
    ): self {
        $this->pagination = $pagination;
        $this->paginationItemLabel = $itemLabel;
        $this->paginationPageField = $pageField;
        $this->paginationHiddenFields = $hiddenFields;

        return $this;
    }

    public function filterSelect(
        string $name,
        string $label,
        array $options,
        string $selectedValue = '',
        array $hiddenFields = []
    ): self {
        $name = trim($name);
        if ($name === '') {
            return $this;
        }

        $this->filters[] = [
            'name' => $name,
            'label' => $label,
            'options' => $options,
            'selected' => $selectedValue,
            'hidden_fields' => $hiddenFields,
        ];

        return $this;
    }

    public function exports(bool $enabled = true): self
    {
        $this->exportsEnabled = $enabled;

        return $this;
    }

    public function toolbarActions(string $html): self
    {
        $this->toolbarActionsHtml = trim($html);

        return $this;
    }

    public function exportFormats(array $formats): self
    {
        $resolved = [];
        foreach ($formats as $format => $label) {
            if (is_int($format)) {
                $format = (string)$label;
                $label = strtoupper($format);
            }

            $format = strtolower(trim((string)$format));
            if (!in_array($format, ['csv', 'xlsx'], true)) {
                continue;
            }

            $label = trim((string)$label);
            $resolved[$format] = $label !== '' ? $label : strtoupper($format);
        }

        $this->exportFormats = $resolved !== [] ? $resolved : $this->exportFormats;

        return $this;
    }

    public function exportLimit(int $limit): self
    {
        $this->exportLimit = max(1, $limit);

        return $this;
    }

    public function render(array $context, array $exportHiddenFields = []): string
    {
        return $this->renderToolbar($context, $exportHiddenFields)
            . $this->renderTable()
            . $this->renderFooter();
    }

    public function renderTable(): string
    {
        $columns = $this->resolvedColumns();
        $classAttribute = $this->tableClass !== ''
            ? ' class="' . HelperFramework::escape($this->tableClass) . '"'
            : '';
        $headerHtml = '';

        foreach ($columns as $column) {
            $headerHtml .= $this->renderHeaderCell($column);
        }

        $bodyHtml = '';
        foreach ($this->displayRows() as $row) {
            $row = is_array($row) ? $row : ['value' => $row];
            $bodyHtml .= '<tr>';

            foreach ($columns as $column) {
                $cellClass = $column->cellClass();
                $bodyHtml .= '<td' . ($cellClass !== '' ? ' class="' . HelperFramework::escape($cellClass) . '"' : '') . '>'
                    . $column->htmlValue($row)
                    . '</td>';
            }

            $bodyHtml .= '</tr>';
        }

        if ($bodyHtml === '') {
            $bodyHtml = '<tr><td colspan="' . max(1, count($columns)) . '">' . HelperFramework::escape($this->emptyMessage) . '</td></tr>';
        }

        $table = '<table' . $classAttribute . '><thead><tr>' . $headerHtml . '</tr></thead><tbody>' . $bodyHtml . '</tbody></table>';

        if ($this->wrapperClass === '') {
            return $table;
        }

        return '<div class="' . HelperFramework::escape($this->wrapperClass) . '">' . $table . '</div>';
    }

    private function renderHeaderCell(TableColumnFramework $column): string
    {
        $classes = array_values(array_filter([
            $column->headerClass(),
            $column->isSortable() ? 'table-sortable-heading' : '',
            $this->activeSortKey() === $column->key() ? 'table-sort-active' : '',
        ]));
        $classAttribute = $classes !== [] ? ' class="' . HelperFramework::escape(implode(' ', $classes)) . '"' : '';
        $ariaSort = '';

        if ($this->activeSortKey() === $column->key()) {
            $ariaSort = ' aria-sort="' . ($this->activeSortDirection() === 'desc' ? 'descending' : 'ascending') . '"';
        }

        if (!$column->isSortable()) {
            return '<th' . $classAttribute . $ariaSort . '>' . HelperFramework::escape($column->label()) . '</th>';
        }

        return '<th' . $classAttribute . $ariaSort . '>'
            . '<form method="post" data-ajax="true" class="table-sort-form">'
            . $this->hiddenInputs($this->sortButtonFields($column))
            . '<button class="table-sort-button" type="submit">'
            . '<span class="table-sort-label">' . HelperFramework::escape($column->label()) . '</span>'
            . '<span class="table-sort-indicator" aria-hidden="true">' . HelperFramework::escape($this->sortIndicator($column)) . '</span>'
            . '</button>'
            . '</form>'
            . '</th>';
    }

    public function renderToolbar(array $context, array $exportHiddenFields = []): string
    {
        if (!$this->exportsEnabled && $this->filters === [] && $this->toolbarActionsHtml === '') {
            return '';
        }

        return '<div class="card-toolbar">
            <div class="actions-row">'
                . $this->renderFilters()
            . '</div>
            <div class="actions-row">'
                . $this->toolbarActionsHtml
                . $this->renderCondensedViewButton()
                . $this->renderExportButtons($context, $exportHiddenFields)
            . '</div>
        </div>';
    }

    public function renderFooter(): string
    {
        if ($this->pagination === null) {
            return '';
        }

        return '<div class="card-toolbar table-footer">
            <div class="helper">' . HelperFramework::escape(HelperFramework::paginationItemsLabel($this->pagination, $this->paginationItemLabel)) . '</div>
            <div class="actions-row">'
                . $this->renderPaginationButton('< Prev', -1)
                . $this->renderPaginationButton('Next >', 1)
            . '</div>
        </div>';
    }

    public function exportCsv(): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        $columns = $this->exportColumns();
        fputcsv($handle, array_map(static fn(TableColumnFramework $column): string => $column->label(), $columns), ',', '"', '');

        foreach ($this->exportRows() as $row) {
            $row = is_array($row) ? $row : ['value' => $row];
            fputcsv($handle, array_map(static fn(TableColumnFramework $column): string => $column->exportValue($row), $columns), ',', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    public function exportXlsx(): string
    {
        $columns = $this->exportColumns();
        $sheetRows = [];
        $headerCells = [];

        foreach (array_values($columns) as $index => $column) {
            $headerCells[] = $this->xlsxCell($index + 1, 1, $column->label());
        }

        $sheetRows[] = '<row r="1">' . implode('', $headerCells) . '</row>';

        foreach (array_values($this->exportRows()) as $rowIndex => $row) {
            $row = is_array($row) ? $row : ['value' => $row];
            $cells = [];
            $sheetRow = $rowIndex + 2;

            foreach (array_values($columns) as $columnIndex => $column) {
                $cells[] = $this->xlsxCell($columnIndex + 1, $sheetRow, $column->exportValue($row), $column->exportType());
            }

            $sheetRows[] = '<row r="' . $sheetRow . '">' . implode('', $cells) . '</row>';
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
            . '</worksheet>';

        return $this->zipFiles([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . $this->xml($this->worksheetName()) . '" sheetId="1" r:id="rId1"/></sheets>'
                . '</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '</Relationships>',
            'xl/worksheets/sheet1.xml' => $sheetXml,
        ]);
    }

    public function downloadResponse(string $format): ResponseFramework
    {
        $format = strtolower(trim($format));

        if ($format === 'xlsx') {
            return ResponseFramework::download(
                $this->exportXlsx(),
                $this->downloadFilename('xlsx'),
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            );
        }

        return ResponseFramework::download(
            $this->exportCsv(),
            $this->downloadFilename('csv'),
            'text/csv; charset=utf-8'
        );
    }

    private function renderExportButtons(array $context, array $hiddenFields): string
    {
        $html = '';

        foreach ($this->exportFormats as $format => $label) {
            $html .= $this->renderExportButton($context, (string)$format, (string)$label, $hiddenFields);
        }

        return $html;
    }

    private function renderCondensedViewButton(): string
    {
        if (!$this->exportsEnabled) {
            return '';
        }

        return '<button class="button table-condensed-toggle" type="button" data-table-key="'
            . HelperFramework::escape($this->key)
            . '" aria-pressed="false">Condensed View</button>';
    }

    private function renderExportButton(array $context, string $format, string $label, array $hiddenFields): string
    {
        if (!$this->exportsEnabled) {
            return '';
        }

        $fields = array_merge(
            [
                'page' => (string)($context['page']['page_id'] ?? ''),
                '_table_export_prepare' => $format,
                'table_key' => $this->key,
            ],
            $hiddenFields,
            $this->sortHiddenFields()
        );

        return '<form method="post" data-ajax="true">' . $this->hiddenInputs($fields) . '<button class="button primary" type="submit">'
            . HelperFramework::escape($label)
            . '</button></form>';
    }

    private function renderFilters(): string
    {
        $html = '';

        foreach ($this->filters as $filter) {
            $optionsHtml = '';
            foreach ((array)$filter['options'] as $value => $label) {
                $value = (string)$value;
                $optionsHtml .= '<option value="' . HelperFramework::escape($value) . '"'
                    . ($value === (string)$filter['selected'] ? ' selected' : '')
                    . '>' . HelperFramework::escape((string)$label) . '</option>';
            }

            $fieldId = 'table-filter-' . $this->key . '-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$filter['name']);
            $html .= '<form method="post" data-ajax="true" class="toolbar">'
                . $this->hiddenInputs(array_merge((array)$filter['hidden_fields'], $this->sortHiddenFields()))
                . '<div class="form-row table-filter-row">'
                . '<label for="' . HelperFramework::escape($fieldId) . '">' . HelperFramework::escape((string)$filter['label']) . '</label>'
                . '<select class="selector-input" id="' . HelperFramework::escape($fieldId) . '" name="' . HelperFramework::escape((string)$filter['name']) . '">'
                . $optionsHtml
                . '</select>'
                . '</div>'
                . '</form>';
        }

        return $html;
    }

    private function renderPaginationButton(string $label, int $direction): string
    {
        if ($this->pagination === null) {
            return '';
        }

        $enabled = $direction < 0
            ? (bool)($this->pagination['has_previous_page'] ?? false)
            : (bool)($this->pagination['has_next_page'] ?? false);
        $page = max(1, (int)($this->pagination['page'] ?? 1) + $direction);

        return HelperFramework::paginationFormButton(
            $label,
            $page,
            $enabled,
            $this->paginationPageField,
            array_merge($this->paginationHiddenFields, $this->sortHiddenFields()),
            '',
            'post',
            ['data-ajax' => 'true'],
            'button primary'
        );
    }

    private function displayRows(): array
    {
        return $this->visibleRowsConfigured ? $this->visibleRows : $this->sortedRows();
    }

    private function sortButtonFields(TableColumnFramework $column): array
    {
        $fields = array_merge(['_pagination' => '1'], $this->sortHiddenFields);
        $fields[$this->sortFieldName()] = $column->key();
        $fields[$this->sortDirectionFieldName()] = $this->nextSortDirection($column);

        if ($this->pagination !== null) {
            $fields[$this->paginationPageField] = '1';
        }

        return $fields;
    }

    private function sortIndicator(TableColumnFramework $column): string
    {
        if ($this->activeSortKey() !== $column->key()) {
            return '';
        }

        return $this->activeSortDirection() === 'desc' ? 'v' : '^';
    }

    private function nextSortDirection(TableColumnFramework $column): string
    {
        return $this->activeSortKey() === $column->key() && $this->activeSortDirection() === 'asc'
            ? 'desc'
            : 'asc';
    }

    private function activeSortColumn(): ?TableColumnFramework
    {
        if ($this->sortKey === '' || $this->sortDirection === '') {
            return null;
        }

        foreach ($this->resolvedColumns() as $column) {
            if ($column->key() === $this->sortKey && $column->isSortable()) {
                return $column;
            }
        }

        return null;
    }

    private function normaliseSortDirection(string $direction): string
    {
        $direction = strtolower(trim($direction));

        return in_array($direction, ['asc', 'desc'], true) ? $direction : '';
    }

    private function compareSortValues(mixed $left, mixed $right, string $type): int
    {
        return match ($type) {
            'number' => $this->compareNumberSortValues($left, $right),
            'bool' => $this->compareBoolSortValues($left, $right),
            default => strnatcasecmp($this->sortString($left), $this->sortString($right)),
        };
    }

    private function isEmptySortValue(mixed $value): bool
    {
        return $value === null || (is_scalar($value) && trim((string)$value) === '');
    }

    private function compareNumberSortValues(mixed $left, mixed $right): int
    {
        $leftNumber = $this->numberSortValue($left);
        $rightNumber = $this->numberSortValue($right);

        if ($leftNumber === null || $rightNumber === null) {
            return $leftNumber === $rightNumber ? 0 : ($leftNumber === null ? 1 : -1);
        }

        return $leftNumber <=> $rightNumber;
    }

    private function compareBoolSortValues(mixed $left, mixed $right): int
    {
        return $this->boolSortValue($left) <=> $this->boolSortValue($right);
    }

    private function numberSortValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = is_scalar($value) ? str_replace(',', '', trim((string)$value)) : '';

        return is_numeric($value) ? (float)$value : null;
    }

    private function boolSortValue(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'enabled', 'active'], true) ? 1 : 0;
    }

    private function sortString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_scalar($value)) {
            return trim(preg_replace('/\s+/', ' ', (string)$value) ?? (string)$value);
        }

        return trim(preg_replace('/\s+/', ' ', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '') ?? '');
    }

    /**
     * @return list<TableColumnFramework>
     */
    private function resolvedColumns(): array
    {
        if ($this->columns !== []) {
            return $this->columns;
        }

        $firstRow = $this->rows[0] ?? [];
        if (!is_array($firstRow)) {
            return [new TableColumnFramework('value', 'Value')];
        }

        $columns = [];
        foreach (array_keys($firstRow) as $key) {
            $key = (string)$key;
            $columns[] = new TableColumnFramework($key, HelperFramework::labelFromKey($key, '_', $key));
        }

        return $columns !== [] ? $columns : [new TableColumnFramework('value', 'Value')];
    }

    /**
     * @return list<TableColumnFramework>
     */
    private function exportColumns(): array
    {
        return array_values(array_filter(
            $this->resolvedColumns(),
            static fn(TableColumnFramework $column): bool => $column->isExportable()
        ));
    }

    private function exportRows(): array
    {
        return array_slice(array_values($this->sortedRows()), 0, $this->exportLimit);
    }

    private function downloadFilename(string $extension): string
    {
        return $this->filename . '_' . gmdate('Y-m-d-H-i-s') . '.' . $extension;
    }

    private function hiddenInputs(array $fields): string
    {
        $html = '';
        foreach ($fields as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $html .= $this->hiddenInput((string)$name, $item);
                }
                continue;
            }

            $html .= $this->hiddenInput((string)$name, $value);
        }

        return $html;
    }

    private function hiddenInput(string $name, mixed $value): string
    {
        if (is_array($value) || is_object($value) || $value === null) {
            return '';
        }

        return '<input type="hidden" name="' . HelperFramework::escape($name) . '" value="' . HelperFramework::escape((string)$value) . '">';
    }

    private static function stringValue(mixed $value, string $fallback = ''): string
    {
        if (!is_scalar($value) && $value !== null) {
            return $fallback;
        }

        $value = trim((string)$value);

        return $value !== '' ? $value : $fallback;
    }

    private static function joinTextParts(array $parts, string $separator): string
    {
        $parts = array_values(array_filter(
            array_map(static fn(mixed $value): string => trim((string)$value), $parts),
            static fn(string $value): bool => $value !== ''
        ));

        return implode($separator, $parts);
    }

    private function worksheetName(): string
    {
        $name = preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $this->filename) ?? 'Sheet1';
        $name = trim($name);

        return mb_substr($name !== '' ? $name : 'Sheet1', 0, 31);
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xlsxCell(int $column, int $row, string $value, string $type = 'string'): string
    {
        $reference = $this->spreadsheetColumnName($column) . (string)$row;

        if ($type === 'number' && is_numeric(str_replace(',', '', $value))) {
            return '<c r="' . $reference . '"><v>' . $this->xml(str_replace(',', '', $value)) . '</v></c>';
        }

        if ($type === 'bool') {
            $normalised = in_array(strtolower($value), ['1', 'true', 'yes'], true) ? '1' : '0';
            return '<c r="' . $reference . '" t="b"><v>' . $normalised . '</v></c>';
        }

        return '<c r="' . $reference . '" t="inlineStr"><is><t>' . $this->xml($value) . '</t></is></c>';
    }

    private function spreadsheetColumnName(int $column): string
    {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65 + ($column % 26)) . $name;
            $column = intdiv($column, 26);
        }

        return $name;
    }

    private function zipFiles(array $files): string
    {
        $local = '';
        $central = '';
        $offset = 0;
        [$dosTime, $dosDate] = $this->dosDateTime();

        foreach ($files as $name => $contents) {
            $name = str_replace('\\', '/', (string)$name);
            $contents = (string)$contents;
            $compressed = gzdeflate($contents, 9);
            $crc = crc32($contents);
            $compressedLength = strlen($compressed);
            $contentsLength = strlen($contents);
            $nameLength = strlen($name);

            $localHeader = pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0,
                8,
                $dosTime,
                $dosDate,
                $crc,
                $compressedLength,
                $contentsLength,
                $nameLength,
                0
            ) . $name;

            $local .= $localHeader . $compressed;

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                8,
                $dosTime,
                $dosDate,
                $crc,
                $compressedLength,
                $contentsLength,
                $nameLength,
                0,
                0,
                0,
                0,
                0,
                $offset
            ) . $name;

            $offset += strlen($localHeader) + $compressedLength;
        }

        return $local . $central . pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            count($files),
            count($files),
            strlen($central),
            strlen($local),
            0
        );
    }

    private function dosDateTime(): array
    {
        $now = getdate();
        $time = (($now['hours'] ?? 0) << 11) | (($now['minutes'] ?? 0) << 5) | (int)(($now['seconds'] ?? 0) / 2);
        $date = ((max(1980, $now['year'] ?? 1980) - 1980) << 9) | (($now['mon'] ?? 1) << 5) | ($now['mday'] ?? 1);

        return [$time, $date];
    }
}
