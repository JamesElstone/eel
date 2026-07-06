<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

use RuntimeException;

final class DatabaseDumpComparisonService
{
    public function compareFiles(string $leftPath, string $rightPath, array $ignoredColumnsByTable = []): array
    {
        $leftSql = file_get_contents($leftPath);
        $rightSql = file_get_contents($rightPath);

        if (!is_string($leftSql)) {
            throw new RuntimeException('Unable to read left SQL dump: ' . $leftPath);
        }

        if (!is_string($rightSql)) {
            throw new RuntimeException('Unable to read right SQL dump: ' . $rightPath);
        }

        return $this->compareSql($leftSql, $rightSql, $ignoredColumnsByTable);
    }

    public function compareSql(string $leftSql, string $rightSql, array $ignoredColumnsByTable = []): array
    {
        $left = $this->parseDump($leftSql);
        $right = $this->parseDump($rightSql);
        $tables = array_values(array_unique(array_merge($left['tables'], $right['tables'])));
        sort($tables, SORT_STRING);

        $columnMismatches = [];
        $schemaMismatches = [];
        $rowMismatches = [];
        $ignoredRowMismatches = [];

        foreach ($tables as $table) {
            if (($left['columns'][$table] ?? []) !== ($right['columns'][$table] ?? [])) {
                $columnMismatches[] = $table;
            }

            if (($left['schemas'][$table] ?? '') !== ($right['schemas'][$table] ?? '')) {
                $schemaMismatches[] = $table;
            }

            $leftRows = $left['rows'][$table] ?? [];
            $rightRows = $right['rows'][$table] ?? [];
            if ($leftRows === $rightRows) {
                continue;
            }

            $ignoredColumns = array_values(array_filter(array_map('strval', (array)($ignoredColumnsByTable[$table] ?? []))));
            if ($ignoredColumns !== [] && $this->rowHashesForTable($left['row_data'][$table] ?? [], $ignoredColumns) === $this->rowHashesForTable($right['row_data'][$table] ?? [], $ignoredColumns)) {
                $ignoredRowMismatches[$table] = [
                    'left_rows' => count($leftRows),
                    'right_rows' => count($rightRows),
                    'ignored_columns' => $ignoredColumns,
                ];
                continue;
            }

            $rowMismatches[$table] = [
                'left_rows' => count($leftRows),
                'right_rows' => count($rightRows),
                'left_only' => count(array_diff($leftRows, $rightRows)),
                'right_only' => count(array_diff($rightRows, $leftRows)),
            ];
        }

        return [
            'matches' => $columnMismatches === [] && $schemaMismatches === [] && $rowMismatches === [],
            'tables_left' => count($left['tables']),
            'tables_right' => count($right['tables']),
            'data_rows_left' => array_sum(array_map('count', $left['rows'])),
            'data_rows_right' => array_sum(array_map('count', $right['rows'])),
            'missing_from_left' => array_values(array_diff($right['tables'], $left['tables'])),
            'missing_from_right' => array_values(array_diff($left['tables'], $right['tables'])),
            'column_mismatches' => $columnMismatches,
            'schema_mismatches' => $schemaMismatches,
            'row_mismatches' => $rowMismatches,
            'ignored_row_mismatches' => $ignoredRowMismatches,
        ];
    }

    private function parseDump(string $sql): array
    {
        $tables = [];
        $schemas = [];
        $columns = [];
        $rows = [];
        $rowData = [];

        foreach ($this->splitStatements($sql) as $statement) {
            if (preg_match('/CREATE TABLE `([^`]+)`\s*\((.*)\)\s*ENGINE=/is', $statement, $matches) === 1) {
                $table = $matches[1];
                $tables[$table] = true;
                $schemas[$table] = $this->normaliseSchema($statement);
                $columns[$table] = $this->columnsFromCreateTableBody($matches[2]);
                continue;
            }

            if (preg_match('/INSERT INTO `([^`]+)`\s*(?:\((.*?)\))?\s*VALUES\s*(.*);?$/is', $statement, $matches) !== 1) {
                continue;
            }

            $table = $matches[1];
            $insertColumns = $this->insertColumns($matches[2] ?? '', $columns[$table] ?? []);

            foreach ($this->parseRows($matches[3]) as $values) {
                $row = [];
                foreach ($values as $index => $value) {
                    $row[$insertColumns[$index] ?? ('#' . $index)] = $value;
                }

                ksort($row);
                $rowData[$table][] = $row;
                $rows[$table][] = $this->rowHash($row);
            }
        }

        foreach ($rows as &$hashes) {
            sort($hashes, SORT_STRING);
        }
        unset($hashes);

        ksort($tables);
        ksort($schemas);
        ksort($columns);
        ksort($rows);
        ksort($rowData);

        return [
            'tables' => array_keys($tables),
            'schemas' => $schemas,
            'columns' => $columns,
            'rows' => $rows,
            'row_data' => $rowData,
        ];
    }

    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $escaped = false;
        $length = strlen($sql);

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $buffer .= $char;

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === $quote) {
                    if ($quote === "'" && $index + 1 < $length && $sql[$index + 1] === "'") {
                        $buffer .= $sql[++$index];
                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    private function columnsFromCreateTableBody(string $body): array
    {
        $columns = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (preg_match('/^\s*`([^`]+)`\s+/', $line, $matches) === 1) {
                $columns[] = $matches[1];
            }
        }

        return $columns;
    }

    private function insertColumns(string $columnSql, array $tableColumns): array
    {
        if (trim($columnSql) === '') {
            return $tableColumns;
        }

        preg_match_all('/`([^`]+)`/', $columnSql, $matches);

        return $matches[1];
    }

    private function parseRows(string $valuesSql): array
    {
        $rows = [];
        $row = [];
        $token = '';
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($valuesSql);

        for ($index = 0; $index < $length; $index++) {
            $char = $valuesSql[$index];

            if ($quote !== null) {
                $token .= $char;

                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === $quote) {
                    if ($quote === "'" && $index + 1 < $length && $valuesSql[$index + 1] === "'") {
                        $token .= $valuesSql[++$index];
                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $token .= $char;
                continue;
            }

            if ($char === '(') {
                if ($depth > 0) {
                    $token .= $char;
                }
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    $row[] = $this->parseValue($token);
                    $rows[] = $row;
                    $row = [];
                    $token = '';
                    continue;
                }

                $token .= $char;
                continue;
            }

            if ($char === ',' && $depth === 1) {
                $row[] = $this->parseValue($token);
                $token = '';
                continue;
            }

            if ($depth > 0) {
                $token .= $char;
            }
        }

        return $rows;
    }

    private function parseValue(string $token): ?string
    {
        $token = trim($token);
        if (strcasecmp($token, 'NULL') === 0) {
            return null;
        }

        if (strlen($token) >= 2 && $token[0] === "'" && substr($token, -1) === "'") {
            return $this->unescapeSqlString(substr($token, 1, -1));
        }

        return $token;
    }

    private function unescapeSqlString(string $value): string
    {
        $output = '';
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $char = $value[$index];

            if ($char === "'" && $index + 1 < $length && $value[$index + 1] === "'") {
                $output .= "'";
                $index++;
                continue;
            }

            if ($char !== '\\' || $index + 1 >= $length) {
                $output .= $char;
                continue;
            }

            $next = $value[++$index];
            $output .= match ($next) {
                '0' => "\0",
                'b' => "\x08",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'Z' => "\x1a",
                default => $next,
            };
        }

        return $output;
    }

    private function normaliseSchema(string $schema): string
    {
        $createPosition = stripos($schema, 'CREATE TABLE');
        if ($createPosition !== false) {
            $schema = substr($schema, $createPosition);
        }

        $normalised = preg_replace('/AUTO_INCREMENT=\d+\s+/i', '', trim($schema)) ?? '';

        return preg_replace('/\s+/', ' ', $normalised) ?? '';
    }

    private function rowHashesForTable(array $rows, array $ignoredColumns): array
    {
        $ignored = array_fill_keys($ignoredColumns, true);
        $hashes = [];

        foreach ($rows as $row) {
            foreach ($ignored as $column => $_) {
                unset($row[$column]);
            }
            ksort($row);
            $hashes[] = $this->rowHash($row);
        }

        sort($hashes, SORT_STRING);

        return $hashes;
    }

    private function rowHash(array $row): string
    {
        return hash('sha256', (string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
