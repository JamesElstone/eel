<?php
declare(strict_types=1);

final class WebRequest
{
    public function __construct(
        private readonly array $query,
        private readonly array $post,
        private readonly array $server,
        private readonly array $files,
        private readonly array $headers,
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = function_exists('getallheaders') ? (array)getallheaders() : [];

        return new self($_GET, $_POST, $_SERVER, $_FILES, $headers);
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
        return FrameworkHelper::normalisePageKey((string)($this->query['page'] ?? 'dashboard'));
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
        return $this->post[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
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
}
