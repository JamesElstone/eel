<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class PdoStatementDB extends PDOStatement
{
    /** @var list<string> */
    private array $namedOrder;
    private bool $rewriteNamedParams;
    private string $sql;
    private string $logFile;

    protected function __construct(
        array $namedOrder = [],
        bool $rewriteNamedParams = false,
        string $sql = '',
        string $logFile = ''
    ) {
        $this->namedOrder = array_values($namedOrder);
        $this->rewriteNamedParams = $rewriteNamedParams;
        $this->sql = $sql;
        $this->logFile = $logFile;
    }

    private function rewriteExecuteParams(array $params): array {
        if ($params === [] || $this->namedOrder === [] || $this->isListArray($params)) {
            return $params;
        }

        $ordered = [];
        foreach ($this->namedOrder as $placeholder) {
            if (!array_key_exists($placeholder, $params)) {
                throw new InvalidArgumentException('Missing SQL parameter: ' . $placeholder);
            }

            $ordered[] = $params[$placeholder];
        }

        return $ordered;
    }

    private function isListArray(array $value): bool {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        $expectedKey = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expectedKey) {
                return false;
            }

            $expectedKey++;
        }

        return true;
    }

    public function execute(?array $params = null): bool {
        if ($params !== null && $this->rewriteNamedParams) {
            $params = $this->rewriteExecuteParams($params);
        }

        try {
            return parent::execute($params);
        } finally {
            if ($this->logFile !== '') {
                PdoDB::logSql($this->sql, $params);
            }
        }
    }
}
