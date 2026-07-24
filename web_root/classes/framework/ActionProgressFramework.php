<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ActionProgressFramework
{
    private static ?self $activeStream = null;

    private readonly Closure $emitter;
    private bool $started = false;
    private bool $terminal = false;
    private int $sequence = 0;

    public function __construct(?Closure $emitter = null, private readonly bool $emitHeaders = true)
    {
        $this->emitter = $emitter ?? static function (string $line): void {
            echo $line;
            flush();
        };
    }

    public function report(string $message, ?int $percent = null): void
    {
        if ($this->terminal) {
            throw new LogicException('Action progress cannot be reported after the stream has finished.');
        }

        if ($message === '') {
            throw new InvalidArgumentException('Action progress messages must not be empty.');
        }

        if ($percent !== null && ($percent < 0 || $percent > 100)) {
            throw new InvalidArgumentException('Action progress percent must be between 0 and 100.');
        }

        $this->start();

        $event = [
            'type' => 'progress',
            'sequence' => ++$this->sequence,
            'message' => '[' . date('d/m/Y H:i:s') . '] - ' . $message,
        ];

        if ($percent !== null) {
            $event['percent'] = $percent;
        }

        $this->emit($event);
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function isTerminal(): bool
    {
        return $this->terminal;
    }

    public function complete(ResponseFramework $response): bool
    {
        if (!$this->started) {
            return false;
        }

        if ($this->terminal) {
            throw new LogicException('Action progress stream has already finished.');
        }

        if (stripos($response->contentType(), 'application/json') !== 0) {
            return $this->fail('The action completed with an unsupported response type.');
        }

        try {
            $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->fail('The action completed with an invalid response.');
        }

        if (!is_array($payload)) {
            return $this->fail('The action completed with an invalid response.');
        }

        $this->emit([
            'type' => 'complete',
            'payload' => $payload,
        ]);
        $this->finish();

        return true;
    }

    public function fail(string $message): bool
    {
        if (!$this->started || $this->terminal) {
            return false;
        }

        $this->emit([
            'type' => 'error',
            'message' => $message !== '' ? $message : 'The action could not be completed.',
        ]);
        $this->finish();

        return true;
    }

    public static function failActive(string $message): bool
    {
        return self::$activeStream?->fail($message) ?? false;
    }

    private function start(): void
    {
        if ($this->started) {
            return;
        }

        if (self::$activeStream !== null && self::$activeStream !== $this) {
            throw new LogicException('Only one action progress stream can be active in a request.');
        }

        if ($this->emitHeaders) {
            if (headers_sent($filename, $line)) {
                throw new RuntimeException(
                    'Action progress cannot start after response headers were sent at ' . $filename . ':' . $line . '.'
                );
            }

            ini_set('zlib.output_compression', '0');
            ini_set('implicit_flush', '1');

            header('Content-Type: application/x-ndjson; charset=utf-8');
            foreach (ResponseFramework::securityHeaders() as $header => $value) {
                header($header . ': ' . $value);
            }
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Accel-Buffering: no');

            while (ob_get_level() > 0) {
                if (!@ob_end_flush()) {
                    break;
                }
            }

            ob_implicit_flush(true);
        }

        $this->started = true;
        self::$activeStream = $this;
    }

    private function emit(array $event): void
    {
        ($this->emitter)(json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    }

    private function finish(): void
    {
        $this->terminal = true;
        if (self::$activeStream === $this) {
            self::$activeStream = null;
        }
    }
}
