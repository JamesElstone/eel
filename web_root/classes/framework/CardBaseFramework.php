<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

abstract class CardBaseFramework implements CardInterfaceFramework
{
    public function key(): string
    {
        return $this->derivedCardName();
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        return $this->applyPaginationContext($request, $pageContext);
    }

    public function title(): string
    {
        return ucwords(str_replace('_', ' ', $this->derivedCardName()));
    }

    public function contextTitle(array $context): string
    {
        return $this->title();
    }

    public function helper(array $context): string|array
    {
        return '';
    }

    public function services(): array
    {
        return [];
    }

    public function tables(array $context): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        $facts = [$this->defaultInvalidationFact()];

        foreach ($this->additionalInvalidationFacts() as $fact) {
            $fact = trim((string)$fact);

            if ($fact !== '') {
                $facts[] = $fact;
            }
        }

        return array_values(array_unique($facts));
    }

    public function refreshIntervalMs(array $context): ?int
    {
        return null;
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '[' . $serviceKey . '] ' . (string)($error['type'] ?? 'error') . ': ' . (string)($error['message'] ?? '');
    }

    protected function additionalInvalidationFacts(): array
    {
        return [];
    }

    protected function applyPaginationContext(RequestFramework $request, array $pageContext, ?string $scope = null): array
    {
        $pageContext['page'][$this->paginationPageField($scope)] = max(1, (int)$request->input($this->paginationPageField($scope), 1));

        return $pageContext;
    }

    protected function applyTableSortContext(RequestFramework $request, array $pageContext, string $tableKey): array
    {
        $tableKey = HelperFramework::normaliseCardKey($tableKey);
        $sortField = $this->tableSortFieldName($tableKey);
        $directionField = $this->tableSortDirectionFieldName($tableKey);
        $sortKey = trim((string)$request->input($sortField, (string)($pageContext[$this->key()][$sortField] ?? '')));
        $direction = strtolower(trim((string)$request->input($directionField, (string)($pageContext[$this->key()][$directionField] ?? ''))));

        try {
            $sortKey = $sortKey !== '' ? HelperFramework::normaliseCardKey($sortKey) : '';
        } catch (InvalidArgumentException) {
            $sortKey = '';
        }

        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = '';
        }

        if ($direction === '') {
            $sortKey = '';
        }

        $pageContext[$this->key()][$sortField] = $sortKey;
        $pageContext[$this->key()][$directionField] = $direction;

        return $pageContext;
    }

    protected function configureTableSorting(TableFramework $table, array $context, array $hiddenFields = []): TableFramework
    {
        return $table->sorting(
            $this->tableSortKey($context, $table->key()),
            $this->tableSortDirection($context, $table->key()),
            $hiddenFields
        );
    }

    protected function tableSortHiddenFields(array $context, string $tableKey): array
    {
        $sortKey = $this->tableSortKey($context, $tableKey);
        $direction = $this->tableSortDirection($context, $tableKey);

        return $sortKey !== '' && $direction !== ''
            ? [
                $this->tableSortFieldName($tableKey) => $sortKey,
                $this->tableSortDirectionFieldName($tableKey) => $direction,
            ]
            : [];
    }

    protected function tableSortKey(array $context, string $tableKey): string
    {
        return (string)(($context[$this->key()] ?? [])[$this->tableSortFieldName($tableKey)] ?? '');
    }

    protected function tableSortDirection(array $context, string $tableKey): string
    {
        return (string)(($context[$this->key()] ?? [])[$this->tableSortDirectionFieldName($tableKey)] ?? '');
    }

    protected function tableSortFieldName(string $tableKey): string
    {
        return HelperFramework::normaliseCardKey($tableKey) . '_sort';
    }

    protected function tableSortDirectionFieldName(string $tableKey): string
    {
        return HelperFramework::normaliseCardKey($tableKey) . '_sort_direction';
    }

    protected function paginationPage(array $context, ?string $scope = null): int
    {
        return max(1, (int)($context['page'][$this->paginationPageField($scope)] ?? 1));
    }

    protected function paginationControls(
        array $context,
        array $pagination,
        string $itemLabel,
        ?string $scope = null,
        array $hiddenFields = [],
        string $method = 'post',
        array $formAttributes = [],
        string $buttonClass = 'button primary',
        string $middleHtml = '',
        string $wrapperClass = ''
    ): string {
        $hiddenFields = array_merge(
            [
                'page' => (string)($context['page']['page_id'] ?? ''),
                '_pagination' => '1',
            ],
            $hiddenFields
        );
        $formAttributes = array_merge(['data-ajax' => 'true'], $formAttributes);
        $hiddenFields = array_filter(
            $hiddenFields,
            static fn(mixed $value): bool => !is_array($value) && !is_object($value) && $value !== null && $value !== ''
        );
        $currentPage = max(1, (int)($pagination['page'] ?? 1));
        $lastPage = max(1, (int)($pagination['total_pages'] ?? $pagination['page_count'] ?? $currentPage));
        $hasPreviousPage = (bool)($pagination['has_previous_page'] ?? $currentPage > 1);
        $hasNextPage = (bool)($pagination['has_next_page'] ?? $currentPage < $lastPage);
        $wrapperClass = trim($wrapperClass);
        $wrapperClasses = trim('status-head' . ($wrapperClass !== '' ? ' ' . $wrapperClass : ''));

        return '<div class="' . HelperFramework::escape($wrapperClasses) . '">
            <div class="helper">' . HelperFramework::escape(HelperFramework::paginationItemsLabel($pagination, $itemLabel)) . '</div>
            ' . $middleHtml . '
            <div class="actions-row">
                ' . HelperFramework::paginationFormButton(
                    'First',
                    1,
                    $hasPreviousPage,
                    $this->paginationPageField($scope),
                    $hiddenFields,
                    '',
                    $method,
                    $formAttributes,
                    $buttonClass
                ) . '
                ' . HelperFramework::paginationFormButton(
                    'Prev',
                    max(1, $currentPage - 1),
                    $hasPreviousPage,
                    $this->paginationPageField($scope),
                    $hiddenFields,
                    '',
                    $method,
                    $formAttributes,
                    $buttonClass
                ) . '
                ' . HelperFramework::paginationFormButton(
                    'Next',
                    $currentPage + 1,
                    $hasNextPage,
                    $this->paginationPageField($scope),
                    $hiddenFields,
                    '',
                    $method,
                    $formAttributes,
                    $buttonClass
                ) . '
                ' . HelperFramework::paginationFormButton(
                    'Last',
                    $lastPage,
                    $hasNextPage,
                    $this->paginationPageField($scope),
                    $hiddenFields,
                    '',
                    $method,
                    $formAttributes,
                    $buttonClass
                ) . '
            </div>
        </div>';
    }

    protected function paginationPageField(?string $scope = null): string
    {
        $scope = HelperFramework::normaliseCardKey((string)($scope ?? 'page'));

        return $this->key() . '_' . $scope;
    }

    private function defaultInvalidationFact(): string
    {
        $class = str_replace('_', '.', $this->derivedCardName());
        $class = strtolower(trim((string)$class, '. '));

        return $class !== '' ? $class : strtolower($this->key());
    }

    protected function derivedCardName(): string
    {
        $class = static::class;
        $class = preg_replace('/^_+/', '', $class) ?? $class;
        $class = preg_replace('/Card$/', '', $class) ?? $class;

        return strtolower(trim($class));
    }
}
