<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TestOutput.php';

final class GeneratedServiceClassTestHarness
{
    /** @var array<class-string, object> */
    private array $instances = [];

    public function run(string $className, ?callable $customAssertions = null): void
    {
        try {
            $instance = $this->instantiateClass($className);

            $this->assertTrue($instance instanceof $className);
            $this->reportPass($className, 'instantiates successfully');
        } catch (Throwable $exception) {
            $this->reportFailure($className, 'instantiates successfully', $exception);
            return;
        }

        if ($customAssertions !== null) {
            $customAssertions($this, $instance);
        }
    }

    public function runInterface(string $interfaceName, ?callable $customAssertions = null): void
    {
        try {
            $this->ensureTypeLoaded($interfaceName);
            $this->assertTrue(interface_exists($interfaceName, false));
            $this->reportPass($interfaceName, 'loads successfully');
        } catch (Throwable $exception) {
            $this->reportFailure($interfaceName, 'loads successfully', $exception);
            return;
        }

        if ($customAssertions !== null) {
            $customAssertions($this);
        }
    }

    private function instantiateClass(string $className): object
    {
        $this->ensureTypeLoaded($className);

        if (isset($this->instances[$className])) {
            return $this->instances[$className];
        }

        $reflection = new ReflectionClass($className);

        if (!$reflection->isInstantiable()) {
            throw new RuntimeException('Class is not instantiable: ' . $className);
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $this->instances[$className] = $reflection->newInstance();
        }

        $args = [];

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }

            $args[] = $this->resolveParameter($parameter);
        }

        return $this->instances[$className] = $reflection->newInstanceArgs($args);
    }

    private function ensureTypeLoaded(string $typeName): void
    {
        if (
            class_exists($typeName, false)
            || interface_exists($typeName, false)
            || trait_exists($typeName, false)
        ) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_CLASSES));

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getFilename() !== $typeName . '.php') {
                continue;
            }

            require_once $fileInfo->getPathname();
            return;
        }
    }

    private function resolveParameter(ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $namedType) {
                if ($namedType->getName() === 'null') {
                    continue;
                }

                return $this->resolveNamedType($namedType, $parameter);
            }

            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            return $this->resolveNamedType($type, $parameter);
        }

        return null;
    }

    private function resolveNamedType(ReflectionNamedType $type, ReflectionParameter $parameter): mixed
    {
        if ($type->allowsNull()) {
            return null;
        }

        $name = $type->getName();

        if ($type->isBuiltin()) {
            return $this->resolveBuiltinValue($name, $parameter);
        }

        return match ($name) {
            'DateTimeImmutable' => new DateTimeImmutable('2024-01-15'),
            default => $this->instantiateClass($name),
        };
    }

    private function resolveBuiltinValue(string $name, ReflectionParameter $parameter): mixed
    {
        return match ($name) {
            'array' => $this->arrayValueFor($parameter),
            'bool' => false,
            'callable' => static fn(): array => ['status_code' => 200, 'headers' => [], 'body' => '{}'],
            'float' => 0.0,
            'int' => $this->intValueFor($parameter),
            'string' => $this->stringValueFor($parameter),
            default => null,
        };
    }

    private function arrayValueFor(ReflectionParameter $parameter): array
    {
        $name = strtolower($parameter->getName());

        if ($name === 'config') {
            return [
                'mode' => 'TEST',
                'base_url' => 'https://example.test',
                'test_base_url' => 'https://example.test',
            ];
        }

        return [];
    }

    private function intValueFor(ReflectionParameter $parameter): int
    {
        $name = strtolower($parameter->getName());

        if (str_contains($name, 'timeout')) {
            return 10;
        }

        if (str_contains($name, 'items')) {
            return 100;
        }

        return 1;
    }

    private function stringValueFor(ReflectionParameter $parameter): string
    {
        $name = strtolower($parameter->getName());

        if (str_contains($name, 'environment') || str_contains($name, 'mode')) {
            return 'TEST';
        }

        if (
            str_contains($name, 'path')
            || str_contains($name, 'directory')
            || str_contains($name, 'root')
            || str_contains($name, 'base')
        ) {
            return APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
        }

        return 'test';
    }

    public function check(string $className, string $description, callable $callback): void
    {
        try {
            $callback();
            $this->reportPass($className, $description);
        } catch (GeneratedServiceClassTestSkippedException $exception) {
            $this->reportSkip($className, $description, $exception->getMessage());
        } catch (Throwable $exception) {
            $this->reportFailure($className, $description, $exception);
        }
    }

    public function skip(string $reason = 'skipped'): never
    {
        throw new GeneratedServiceClassTestSkippedException($reason);
    }

    private function reportPass(string $className, string $description): void
    {
        test_output_line($className . ': ' . $description . '.');
    }

    private function reportFailure(string $className, string $description, Throwable $exception): void
    {
        test_output_failure_line(
            $className . ': ' . $description . ' failed. ' . $exception->getMessage()
        );
    }

    private function reportSkip(string $className, string $description, string $reason): void
    {
        test_output_skip_line(
            $className . ': ' . $description . ' skipped. ' . $reason
        );
    }

    public function assertCount(int $expected, array $values): void
    {
        $this->assertSame($expected, count($values));
    }

    public function assertSame(mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                'Assertion failed. Expected ' . var_export($expected, true) . ' but received ' . var_export($actual, true) . '.'
            );
        }
    }

    public function assertTrue(bool $condition): void
    {
        if (!$condition) {
            throw new RuntimeException('Assertion failed. Expected condition to be true.');
        }
    }
}

final class GeneratedServiceClassTestSkippedException extends RuntimeException
{
}
