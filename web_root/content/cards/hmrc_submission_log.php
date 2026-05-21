<?php
declare(strict_types=1);

final class _hmrc_submission_logCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_submission_log'; }
    public function title(): string { return 'Submission Log'; }

    public function render(array $context): string
    {
        return '<pre id="hmrc-submission-log" class="submission-log" aria-live="polite"></pre>';
    }
}
