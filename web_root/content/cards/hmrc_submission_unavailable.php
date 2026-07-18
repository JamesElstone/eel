<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _hmrc_submission_unavailableCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_submission_unavailable'; }

    public function title(): string { return 'Corporation Tax submission'; }

    public function services(): array { return []; }

    public function render(array $context): string
    {
        return '<div class="notice warning"><strong>CT600 submission is not implemented.</strong>'
            . '<div class="helper">No return can be prepared, approved, sent, polled, or downloaded from this page.</div></div>';
    }
}
