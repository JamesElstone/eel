<?php
declare(strict_types=1);

final class WebResponse
{
    private function __construct(
        private readonly int $statusCode,
        private readonly string $contentType,
        private readonly string $body,
    ) {
    }

    public static function html(string $html, int $statusCode = 200): self
    {
        return new self($statusCode, 'text/html; charset=utf-8', $html);
    }

    public static function json(array $payload, int $statusCode = 200): self
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return new self($statusCode, 'application/json; charset=utf-8', $json);
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        header('Content-Type: ' . $this->contentType);
        echo $this->body;
    }
}
