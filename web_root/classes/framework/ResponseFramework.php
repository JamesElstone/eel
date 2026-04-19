<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ResponseFramework
{
    private function __construct(
        private readonly int $statusCode,
        private readonly string $contentType,
        private readonly string $body,
        private readonly array $headers = [],
    ) {
    }

    public static function html(string $html, int $statusCode = 200): self
    {
        return new self($statusCode, 'text/html; charset=utf-8', $html, self::defaultHeaders());
    }

    public static function json(array $payload, int $statusCode = 200): self
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return new self($statusCode, 'application/json; charset=utf-8', $json, self::defaultHeaders());
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        header('Content-Type: ' . $this->contentType);
        foreach ($this->headers as $header => $value) {
            header($header . ': ' . $value);
        }
        echo $this->body;
    }

    private static function defaultHeaders(): array
    {
        return [
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];
    }
}
