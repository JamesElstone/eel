<?php
declare(strict_types=1);

final class PdoStatementDB extends PDOStatement
{
    /** @var list<string> */
    private array $namedOrder;
    private bool $rewriteNamedParams;

    protected function __construct(array $namedOrder = [], bool $rewriteNamedParams = false) {
        $this->namedOrder = array_values($namedOrder);
        $this->rewriteNamedParams = $rewriteNamedParams;
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

        return parent::execute($params);
    }
}
