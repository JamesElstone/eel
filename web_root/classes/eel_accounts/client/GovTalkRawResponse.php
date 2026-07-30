<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Client;

final class GovTalkRawResponse
{
    public readonly ?string $sha256;
    public readonly int $bytes;

    /** @param array<mixed> $headers */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body
    ) {
        $this->sha256 = $body !== '' ? hash('sha256', $body) : null;
        $this->bytes = strlen($body);
    }
}
