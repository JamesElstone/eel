<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
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

    public static function download(string $body, string $filename, string $contentType): self
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($filename)) ?? 'download';
        $filename = trim($filename, '.-') !== '' ? trim($filename, '.-') : 'download';

        return new self(200, $contentType, $body, array_merge(self::defaultHeaders(), [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => (string)strlen($body),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]));
    }

    public function body(): string
    {
        return $this->body;
    }

    public function contentType(): string
    {
        return $this->contentType;
    }

    public function headerValue(string $name): ?string
    {
        foreach ($this->headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return (string)$value;
            }
        }

        return null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            header_remove('X-XSS-Protection');
        }

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
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self'",
                "img-src 'self' data:",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'self'",
            ]),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
    }
}
