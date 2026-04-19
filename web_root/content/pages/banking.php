<?php
declare(strict_types=1);

final class _banking extends BaseModulePageFramework
{
    public function id(): string
    {
        return 'banking';
    }

    public function title(): string
    {
        return 'Banking';
    }

    public function subtitle(): string
    {
        return 'Maintain company accounts, field mappings, and reconciliation panels in one place.';
    }

    public function cards(): array
    {
        return ['banking_accounts', 'banking_account_form', 'banking_field_mappings', 'banking_reconciliation'];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        return [
            'edit_account_id' => max(0, (int)$request->input('edit_account_id', $request->query('edit_account_id', 0))),
            'mapping_account_id' => max(0, (int)$request->input('mapping_account_id', $request->query('mapping_account_id', 0))),
        ];
    }
}
