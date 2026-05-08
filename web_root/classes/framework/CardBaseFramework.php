<?php
/**
 * EEL Accounts
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
        string $buttonClass = 'button primary'
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

        return '<div class="status-head">
            <div class="helper">' . HelperFramework::escape(HelperFramework::paginationItemsLabel($pagination, $itemLabel)) . '</div>
            <div class="actions-row">
                ' . HelperFramework::paginationFormButton(
                    'Prev',
                    max(1, (int)$pagination['page'] - 1),
                    (bool)$pagination['has_previous_page'],
                    $this->paginationPageField($scope),
                    $hiddenFields,
                    '',
                    $method,
                    $formAttributes,
                    $buttonClass
                ) . '
                ' . HelperFramework::paginationFormButton(
                    'Next',
                    (int)$pagination['page'] + 1,
                    (bool)$pagination['has_next_page'],
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
