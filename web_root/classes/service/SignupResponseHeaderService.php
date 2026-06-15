<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SignupResponseHeaderService
{
    public function headers(): array
    {
        return [
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];
    }

    public function send(): void
    {
        if (headers_sent()) {
            return;
        }

        foreach ($this->headers() as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
