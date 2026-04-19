<?php
declare(strict_types=1);

final class _assets extends BaseModulePageFramework
{
    public function id(): string
    {
        return 'assets';
    }

    public function title(): string
    {
        return 'Assets';
    }

    public function subtitle(): string
    {
        return 'Manage the fixed asset register, additions, and tax view for the selected accounting period.';
    }

    public function cards(): array
    {
        return ['asset_create', 'asset_register', 'asset_tax'];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        return [
            'prefill_transaction_id' => max(0, (int)$request->input('transaction_id', $request->query('transaction_id', 0))),
        ];
    }
}
