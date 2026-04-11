<?php
declare(strict_types=1);

final class WebPageServices
{
    public function __construct(private readonly array $services)
    {
    }

    public function get(string $key): object
    {
        if (!array_key_exists($key, $this->services)) {
            throw new InvalidArgumentException('Page service not provided: ' . $key);
        }

        return $this->services[$key];
    }

    public function all(): array
    {
        return $this->services;
    }
}
