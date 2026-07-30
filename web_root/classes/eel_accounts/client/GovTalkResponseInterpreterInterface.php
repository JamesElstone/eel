<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Client;

interface GovTalkResponseInterpreterInterface
{
    /** @return array<string,mixed> */
    public function interpret(
        GovTalkPreparedRequest $request,
        GovTalkRawResponse $response
    ): array;
}
