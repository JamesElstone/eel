<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Client;

final class GovTalkExchangeResult
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly array $payload,
        public readonly GovTalkPreparedRequest $request,
        public readonly ?GovTalkRawResponse $response
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
