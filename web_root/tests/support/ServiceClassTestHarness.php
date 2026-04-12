<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'TestOutput.php';

final class GeneratedServiceClassTestHarness
{
    /** @var array<class-string, object> */
    private array $instances = [];
    private ?PDO $pdo = null;

    public function run(string $className, ?callable $customAssertions = null): void
    {
        $instance = $this->instantiateClass($className);

        $this->assertTrue($instance instanceof $className);
        $this->reportPass($className, 'instantiates successfully');

        if ($customAssertions !== null) {
            $customAssertions($this, $instance);
        }
    }

    public function runInterface(string $interfaceName, ?callable $customAssertions = null): void
    {
        $this->ensureTypeLoaded($interfaceName);
        $this->assertTrue(interface_exists($interfaceName, false));
        $this->reportPass($interfaceName, 'loads successfully');

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
            'PDO' => $this->testPdo(),
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

    private function testPdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        try {
            $this->pdo = new PDO('sqlite::memory:');
        } catch (Throwable) {
            $this->pdo = new GeneratedServiceClassTestPdo();
        }

        return $this->pdo;
    }

    public function check(string $className, string $description, callable $callback): void
    {
        $callback();
        $this->reportPass($className, $description);
    }

    private function reportPass(string $className, string $description): void
    {
        test_output_line($className . ': ' . $description . '.');
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

final class GeneratedServiceClassTestPdo extends PDO
{
    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return false;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return false;
    }

    public function rollBack(): bool
    {
        return true;
    }
}
