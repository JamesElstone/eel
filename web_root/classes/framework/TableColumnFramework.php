<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class TableColumnFramework
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly mixed $html = null,
        private readonly mixed $export = null,
        private readonly string $headerClass = '',
        private readonly string $cellClass = '',
        private readonly bool $exportable = true,
        private readonly string $exportType = 'string',
        private readonly mixed $sort = null,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function headerClass(): string
    {
        return $this->headerClass;
    }

    public function cellClass(): string
    {
        return $this->cellClass;
    }

    public function isExportable(): bool
    {
        return $this->exportable;
    }

    public function isSortable(): bool
    {
        return $this->sort !== false && ($this->exportable || $this->sort !== null);
    }

    public function htmlValue(array $row): string
    {
        if (is_callable($this->html)) {
            return (string)($this->html)($row, $this);
        }

        return HelperFramework::escape($this->scalarValue($row));
    }

    public function exportValue(array $row): string
    {
        if (!$this->exportable) {
            return '';
        }

        if (is_callable($this->export)) {
            return $this->normaliseExportValue(($this->export)($row, $this));
        }

        if (is_callable($this->html) && $this->export === true) {
            return $this->normaliseExportValue(strip_tags((string)($this->html)($row, $this)));
        }

        return $this->scalarValue($row);
    }

    public function exportType(): string
    {
        return in_array($this->exportType, ['number', 'date', 'datetime', 'bool'], true)
            ? $this->exportType
            : 'string';
    }

    public function sortValue(array $row): mixed
    {
        if (is_callable($this->sort)) {
            return ($this->sort)($row, $this);
        }

        if ($this->sort === true) {
            return $this->scalarValue($row);
        }

        return $this->exportValue($row);
    }

    private function scalarValue(array $row): string
    {
        $value = $row[$this->key] ?? '';

        return $this->normaliseExportValue($value);
    }

    private function normaliseExportValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_scalar($value)) {
            return trim(preg_replace('/\s+/', ' ', (string)$value) ?? (string)$value);
        }

        return trim(preg_replace('/\s+/', ' ', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '') ?? '');
    }
}
