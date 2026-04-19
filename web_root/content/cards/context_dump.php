<?php
declare(strict_types=1);

final class _context_dumpCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'context_dump';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['test.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '[' . $serviceKey . '] ' . (string)($error['type'] ?? 'error') . ': ' . (string)($error['message'] ?? '');
    }

    public function render(array $context): string
    {
        $errorSummary = '';
        $declaredClasses = $this->groupDeclaredClasses();
        $reliedUponInternalClasses = $this->findReliedUponInternalClasses($declaredClasses['dynamic']);

        foreach ((array)($context['service_errors'] ?? []) as $serviceKey => $error) {
            if (!is_array($error) || !isset($error['rendered'])) {
                continue;
            }

            $errorSummary .= '<div class="helper">' . HelperFramework::escape((string)$error['rendered']) . '</div>';
        }

        return '<div class="card">
            <div class="card-header card-header-has-eyebrow">
                <div>
                    <h2 class="card-title">Full context dump</h2>
                </div>
                <p class="eyebrow card-header-corner-eyebrow">Card: ' . HelperFramework::escape($this->key()) . '</p>
            </div>
            <div class="card-body stack">
                <p class="helper">This is the full card-local context array, including wrapped page context, resolved services, and rendered error states.</p>
                ' . $errorSummary . '
                <pre class="panel-soft preformatted-panel">' . HelperFramework::escape($this->dumpContext($context)) . '</pre>
                <div class="panel-soft context-dump-classes-panel">
                    <div class="stack">
                        <div>
                            <p class="helper context-dump-helper">Loaded dynamically</p>
                            ' . $this->renderClassList($declaredClasses['dynamic']) . '
                        </div>
                        <div>
                            <p class="helper context-dump-helper">PHP core/internal</p>
                            <p class="helper context-dump-helper">Green items are reflected class/type dependencies of loaded non-internal classes. They are not guaranteed to have executed in this request.</p>
                            ' . $this->renderClassList($declaredClasses['internal'], $reliedUponInternalClasses) . '
                        </div>
                    </div>
                </div>
            </div>
        </div>';
    }

    private function dumpContext(array $context): string
    {
        ob_start();
        var_dump($context);

        return trim((string)ob_get_clean());
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

    private function renderClassList(array $classes, array $highlightedClasses = []): string
    {
        if ($classes === []) {
            return '<p class="helper class-list-empty">None loaded.</p>';
        }

        $highlightLookup = array_fill_keys($highlightedClasses, true);
        $items = array_map(function (string $className) use ($highlightLookup): string {
            $classAttribute = isset($highlightLookup[$className]) ? ' class="class-list-highlight"' : '';

            return '<li><span' . $classAttribute . '>' . HelperFramework::escape($className) . '</span></li>';
        }, $classes);

        return '<ul class="class-list">' . implode('', $items) . '</ul>';
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

