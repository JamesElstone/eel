<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _transmit extends PageContextFramework
{
    public function id(): string { return 'transmit'; }

    public function title(): string { return 'Transmit'; }

    public function subtitle(): string
    {
        return 'Review, send, poll and audit immutable statutory filing packages for HMRC and Companies House.';
    }

    public function ajaxPendingBlurScope(): string { return 'page'; }

    public function cards(): array
    {
        return ['hmrc_submission_unavailable', 'companies_house_transmission'];
    }

    public function cardLayout(): array
    {
        return [
            ['tab' => 'HMRC', 'cards' => ['hmrc_submission_unavailable']],
            ['tab' => 'Companies House', 'cards' => ['companies_house_transmission']],
        ];
    }
}
