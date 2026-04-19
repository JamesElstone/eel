<?php
declare(strict_types=1);

final class _year_end extends BaseModulePageFramework
{
    public function id(): string
    {
        return 'year_end';
    }

    public function title(): string
    {
        return 'Year End';
    }

    public function subtitle(): string
    {
        return 'Work through the year-end checklist, review the workspace, and inspect recent year-end audit activity.';
    }

    public function cards(): array
    {
        return ['year_end_state', 'year_end_workspace', 'year_end_audit_log'];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        return [
            'year_end_audit_rows' => (new LogsRepository())->fetchRecentYearEndAudit(200),
        ];
    }
}
