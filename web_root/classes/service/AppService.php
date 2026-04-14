<?php
declare(strict_types=1);

final class AppService
{
    /** @var array<class-string, object> */
    private array $instances = [];

    /** @var array<class-string, true> */
    private array $resolving = [];

    public function __construct(
        private readonly string $uploadBasePath,
    ) {
    }

    public function get(string $className): object
    {
        $className = ltrim(trim($className), '\\');

        if ($className === '') {
            throw new InvalidArgumentException('Service class name must not be empty.');
        }

        if (isset($this->instances[$className])) {
            return $this->instances[$className];
        }

        if (isset($this->resolving[$className])) {
            throw new RuntimeException('Circular service dependency detected while resolving ' . $className . '.');
        }

        if (!class_exists($className)) {
            throw new InvalidArgumentException('Unknown service class: ' . $className);
        }

        $reflection = new ReflectionClass($className);

        if (!$reflection->isInstantiable()) {
            throw new RuntimeException('Service class is not instantiable: ' . $className);
        }

        $this->resolving[$className] = true;

        try {
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return $this->instances[$className] = $reflection->newInstance();
            }

            $arguments = [];

            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->isVariadic()) {
                    continue;
                }

                $arguments[] = $this->resolveParameter($parameter);
            }

            return $this->instances[$className] = $reflection->newInstanceArgs($arguments);
        } finally {
            unset($this->resolving[$className]);
        }
    }

    public function getMany(array $classNames): array
    {
        $services = [];

        foreach ($classNames as $className) {
            $serviceClass = ltrim(trim((string)$className), '\\');
            $services[$serviceClass] = $this->get($serviceClass);
        }

        $services[CompanyStore::class] = $this->get(CompanyStore::class);

        return $services;
    }

    private function resolveParameter(ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $namedType) {
                if (!$namedType instanceof ReflectionNamedType || $namedType->getName() === 'null') {
                    continue;
                }

                return $this->resolveNamedType($namedType, $parameter);
            }
        }

        if ($type instanceof ReflectionNamedType) {
            return $this->resolveNamedType($type, $parameter);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new RuntimeException('Unable to resolve untyped constructor parameter $' . $parameter->getName() . '.');
    }

    private function resolveNamedType(ReflectionNamedType $type, ReflectionParameter $parameter): mixed
    {
        $typeName = ltrim($type->getName(), '\\');

        if ($type->isBuiltin()) {
            return $this->resolveBuiltinParameter($typeName, $parameter);
        }

        if (interface_exists($typeName)) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            if ($parameter->allowsNull()) {
                return null;
            }

            throw new RuntimeException('Unable to resolve interface dependency ' . $typeName . '.');
        }

        return $this->get($typeName);
    }

    private function resolveBuiltinParameter(string $typeName, ReflectionParameter $parameter): mixed
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        $parameterName = strtolower($parameter->getName());

        return match ($typeName) {
            'string' => $this->resolveStringParameter($parameterName),
            'int' => $this->resolveIntParameter($parameterName),
            'bool' => false,
            'array' => [],
            'float' => 0.0,
            'callable' => null,
            default => throw new RuntimeException(
                'Unable to resolve builtin dependency $' . $parameter->getName() . ' of type ' . $typeName . '.'
            ),
        };
    }

    private function resolveStringParameter(string $parameterName): string
    {
        if (
            str_contains($parameterName, 'path')
            || str_contains($parameterName, 'directory')
            || str_contains($parameterName, 'root')
            || str_contains($parameterName, 'base')
        ) {
            return $this->uploadBasePath;
        }

        return '';
    }

    private function resolveIntParameter(string $parameterName): int
    {
        if (str_contains($parameterName, 'timeout')) {
            return 20;
        }

        if (str_contains($parameterName, 'bytes')) {
            return 10485760;
        }

        if (str_contains($parameterName, 'items')) {
            return 100;
        }

        return 0;
    }
}
