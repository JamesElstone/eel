<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class RequestFramework
{
    private readonly array $jsonInput;
    private readonly array $headers;

    public function __construct(
        private readonly array $query,
        private readonly array $post,
        private readonly array $server,
        private readonly array $files,
        array $headers,
        ?string $rawBody = null,
        private readonly array $cookies = [],
    ) {
        $this->headers = array_merge(self::headersFromServer($server), self::normaliseHeaders($headers));
        $this->jsonInput = $this->parseJsonInput($rawBody);
    }

    public static function fromGlobals(): self
    {
        $headers = self::headersFromServer($_SERVER);
        if (function_exists('getallheaders')) {
            $headers = array_merge($headers, self::normaliseHeaders((array)getallheaders()));
        }

        $rawBody = $GLOBALS['__request_framework_raw_body'] ?? file_get_contents('php://input');

        return new self($_GET, $_POST, $_SERVER, $_FILES, $headers, is_string($rawBody) ? $rawBody : null, $_COOKIE);
    }

    public function method(): string
    {
        return strtoupper((string)($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function isAjax(): bool
    {
        $requestedWith = strtolower((string)($this->server['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($this->server['HTTP_ACCEPT'] ?? ''));
        $ajaxFlag = strtolower((string)$this->input('_ajax', ''));

        return $requestedWith === 'xmlhttprequest'
            || str_contains($accept, 'application/json')
            || $ajaxFlag === '1';
    }

    public function getPage(): string
    {
        return HelperFramework::normalisePageKey((string)($this->query['page'] ?? 'dashboard'));
    }

    public function action(): string
    {
        return trim((string)$this->input('action', ''));
    }

    public function cardAction(): string
    {
        return trim((string)$this->input('card_action', ''));
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }

        if (array_key_exists($key, $this->jsonInput)) {
            return $this->jsonInput[$key];
        }

        return $default;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        foreach ($this->headers as $headerName => $value) {
            if (strcasecmp((string)$headerName, $name) === 0) {
                return $value;
            }
        }

        return $default;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function remoteAddress(): ?string
    {
        return $this->normaliseOptionalString($this->server('REMOTE_ADDR'));
    }

    public function remotePort(): ?string
    {
        return $this->normaliseOptionalString($this->server('REMOTE_PORT'));
    }

    public function isSecure(): bool
    {
        $https = strtolower((string)$this->server('HTTPS', ''));

        return $https === 'on' || $https === '1' || (int)$this->server('SERVER_PORT', 0) === 443;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }

        if (array_key_exists($key, $this->jsonInput)) {
            return $this->jsonInput[$key];
        }

        return $this->query[$key] ?? $default;
    }

    public function cardKeys(): array
    {
        $raw = $this->input('cards', []);
        $keys = is_array($raw) ? $raw : [$raw];
        $normalised = [];

        foreach ($keys as $key) {
            $key = strtolower(trim((string)$key));
            $key = str_replace('-', '_', $key);

            if ($key !== '' && preg_match('/^[a-z0-9_]+$/', $key) === 1) {
                $normalised[] = $key;
            }
        }

        return array_values(array_unique($normalised));
    }

    public function withMergedQuery(array $values): array
    {
        $query = $this->query;

        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
                continue;
            }

            $query[$key] = $value;
        }

        return $query;
    }

    public function pageUrl(array $extraQuery = []): string
    {
        $query = $this->withMergedQuery(['page' => $this->getPage()] + $extraQuery);

        return '?' . http_build_query($query);
    }

    private function parseJsonInput(?string $rawBody): array
    {
        if (!$this->expectsJsonBody()) {
            return [];
        }

        $rawBody = is_string($rawBody) ? trim($rawBody) : '';
        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function expectsJsonBody(): bool
    {
        $contentType = strtolower((string)($this->server['CONTENT_TYPE'] ?? $this->header('Content-Type', '')));

        return str_contains($contentType, 'application/json');
    }

    private static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || strncmp($key, 'HTTP_', 5) !== 0) {
                continue;
            }

            $headers[HelperFramework::httpHeaderLabelFromServerKey($key)] = is_scalar($value) ? (string)$value : '';
        }

        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'] as $contentKey) {
            if (!array_key_exists($contentKey, $server)) {
                continue;
            }

            $headers[HelperFramework::httpHeaderLabelFromServerKey($contentKey)] = is_scalar($server[$contentKey]) ? (string)$server[$contentKey] : '';
        }

        return $headers;
    }

    private static function normaliseHeaders(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[(string)$name] = is_scalar($value) ? (string)$value : '';
        }

        return $normalised;
    }

    private function normaliseOptionalString(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        $stringValue = trim((string)$value);

        return $stringValue === '' ? null : $stringValue;
    }
}

