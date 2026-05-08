<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dump_classesCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'dump_classes';
    }

    public function services(): array
    {
        return [];
    }

    public function helper(array $context): string{
        return 'This is a debugging card and shows the class usage for this application and is long...';        
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '[' . $serviceKey . '] ' . (string)($error['type'] ?? 'error') . ': ' . (string)($error['message'] ?? '');
    }

    public function render(array $context): string
    {
        $declaredClasses = $this->groupDeclaredClasses();
        $reliedUponInternalClasses = $this->findReliedUponInternalClasses($declaredClasses['dynamic']);
        return '
            <div class="stack">
                <div class="panel-soft">
                    <div class="stack">
                        <div>
                            <p class="helper">Class Reflection Looking Glass: ' . get_class($this) . '</p>
                        </div>
                        ' . HelperFramework::arrayToTable(HelperFramework::classLookingGlass($this)) . '
                    </div>
                </div>
                <div class="panel-soft">
                    <div class="stack">
                        <p class="helper context-dump-helper">Application Dynamically Loaded:</p>
                        ' . $this->renderClassList($declaredClasses['dynamic']) . '

                        ' . $this->renderClassList(
                            $declaredClasses['internal'],
                            $reliedUponInternalClasses,
                            'PHP Core classes used:',
                            'PHP Core classes not used but available:'
                        ) . '
                    </div>
                </div>
            </div>
        ';
    }

    private function groupDeclaredClasses(): array
    {
        $grouped = [
            'dynamic' => [],
            'internal' => [],
        ];

        foreach (get_declared_classes() as $className) {
            $reflection = new ReflectionClass($className);

            if ($reflection->isInternal()) {
                $grouped['internal'][] = $className;
                continue;
            }

            $grouped['dynamic'][] = $className;
        }

        sort($grouped['dynamic']);
        sort($grouped['internal']);

        return $grouped;
    }

    private function renderClassList(
        array $classes,
        array $highlightedClasses = [],
        ?string $highlightedLabel = null,
        ?string $normalLabel = null
    ): string
    {

        sort($classes, SORT_NATURAL | SORT_FLAG_CASE);

        if ($classes === []) {
            return '<p class="helper class-list-empty">None loaded!</p>';
        }

        $highlightLookup = array_fill_keys($highlightedClasses, true);
        $highlightedItems = [];
        $normalItems = [];

        foreach ($classes as $className) {
            $isHighlighted = isset($highlightLookup[$className]);

            $item = '<li><span' . ($isHighlighted ? ' class="class-list-highlight"' : '') . '>'
                . HelperFramework::escape($className)
                . '</span></li>';

            if ($isHighlighted) {
                $highlightedItems[] = $item;
            } else {
                $normalItems[] = $item;
            }
        }

        $html = '';

        if ($highlightedItems !== []) {
            $html .= '<div class="stack">';
            if ($highlightedLabel !== null && $highlightedLabel !== '') {
                $html .= '<p class="helper">' . HelperFramework::escape($highlightedLabel) . '</p>';
            }
            $html .= '<ul class="class-list class-list-highlighted">' . implode('', $highlightedItems) . '</ul>';
            $html .= '</div>';
        }

        if ($normalItems !== []) {
            $html .= '<div class="stack">';
            if ($normalLabel !== null && $normalLabel !== '') {
                $html .= '<p class="helper">' . HelperFramework::escape($normalLabel) . '</p>';
            }
            $html .= '<ul class="class-list">' . implode('', $normalItems) . '</ul>';
            $html .= '</div>';
        }

        return $html;
    }

    private function findReliedUponInternalClasses(array $dynamicClasses): array
    {
        $reliedUpon = [];

        foreach ($dynamicClasses as $className) {
            $reflection = new ReflectionClass($className);

            $this->collectInternalType($reflection->getParentClass(), $reliedUpon);

            foreach ($reflection->getInterfaceNames() as $interfaceName) {
                $this->collectInternalType($interfaceName, $reliedUpon);
            }

            foreach ($reflection->getTraitNames() as $traitName) {
                $this->collectInternalType($traitName, $reliedUpon);
            }

            foreach ($reflection->getProperties() as $property) {
                $this->collectReflectionType($property->getType(), $reliedUpon);
            }

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $className) {
                    continue;
                }

                $this->collectReflectionType($method->getReturnType(), $reliedUpon);

                foreach ($method->getParameters() as $parameter) {
                    $this->collectReflectionType($parameter->getType(), $reliedUpon);
                }
            }
        }

        $classNames = array_keys($reliedUpon);
        sort($classNames);

        return $classNames;
    }

    private function collectReflectionType(ReflectionType|null $type, array &$reliedUpon): void
    {
        if ($type === null) {
            return;
        }

        if ($type instanceof ReflectionNamedType) {
            $this->collectInternalType($type->getName(), $reliedUpon);
            return;
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $nestedType) {
                $this->collectReflectionType($nestedType, $reliedUpon);
            }
        }
    }

    private function collectInternalType(ReflectionClass|string|false|null $type, array &$reliedUpon): void
    {
        if ($type === false || $type === null) {
            return;
        }

        $reflection = $type instanceof ReflectionClass ? $type : null;

        if ($reflection === null) {
            if (!class_exists($type) && !interface_exists($type) && !trait_exists($type)) {
                return;
            }

            $reflection = new ReflectionClass($type);
        }

        if (!$reflection->isInternal()) {
            return;
        }

        $reliedUpon[$reflection->getName()] = true;
    }
}