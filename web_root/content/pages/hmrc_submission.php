<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _hmrc_submission extends PageContextFramework
{
    public function id(): string { return 'hmrc_submission'; }

    public function title(): string { return 'HMRC Submission'; }

    public function subtitle(): string { return 'Prepare, approve, and track Corporation Tax XML submissions from an immutable locked Year End.'; }

    public function cards(): array
    {
        return ['hmrc_submission_overview', 'hmrc_submission_controls', 'hmrc_submission_log', 'hmrc_submission_history'];
    }

    public function ajaxPendingBlurScope(): string
    {
        return 'page';
    }

    public function cardLayout(): array
    {
        return [
            ['tab' => 'Submit', 'cards' => ['hmrc_submission_overview', 'hmrc_submission_controls', 'hmrc_submission_log']],
            ['tab' => 'History', 'cards' => ['hmrc_submission_history']],
        ];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $querySelection = (int)($actionResult->query()['ct_period_id'] ?? 0);
        $selectedCtPeriodId = (int)$request->input('ct_period_id', $querySelection);

        // The cards resolve the read model declaratively. Keep this page hook
        // limited to request-local selector state so a GET cannot mutate tax
        // periods, submission schema, packages, or HMRC state.
        return [
            'hmrc_submission_selection' => [
                'selected_ct_period_id' => $selectedCtPeriodId,
            ],
        ];
    }
}
