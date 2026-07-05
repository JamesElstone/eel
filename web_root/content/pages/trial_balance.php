<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _trial_balance extends PageContextFramework
{
    public function id(): string
    {
        return 'trial_balance';
    }

    public function title(): string
    {
        return 'Trial Balances';
    }

    public function subtitle(): string
    {
        return 'Review the trial balance state and work through the selected company trial balance workspace.';
    }

    public function ajaxPendingBlurScope(): string
    {
        return 'page';
    }

    public function cards(): array
    {
        return [
            'trial_balance_state',
            'trial_balance_validation',
            'trial_balance_losses',
        ];
    }

    public function cardLayout(): array
    {
        return [
            ['tab' => 'Summary', 'cards' => ['trial_balance_state']],
            ['tab' => 'Validation', 'cards' => ['trial_balance_validation']],
            ['tab' => 'Losses', 'cards' => ['trial_balance_losses']],
        ];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $filters = (array)($actionResult->context()['trial_balance_filters'] ?? []);

        if ($filters === []) {
            $filters = [
                'search' => trim((string)$request->input('search', '')),
                'account_type' => trim((string)$request->input('account_type', 'all')),
                'focus' => trim((string)$request->input('focus', 'all')),
                'view_mode' => trim((string)$request->input('view_mode', 'summary')),
            ];
        }

        $filters['account_type'] = $this->normaliseOption((string)($filters['account_type'] ?? 'all'), [
            'all',
            'asset',
            'liability',
            'equity',
            'income',
            'cost_of_sales',
            'expense',
        ]);
        $filters['focus'] = $this->normaliseOption((string)($filters['focus'] ?? 'all'), [
            'all',
            'income_statement',
            'balance_sheet',
            'exception',
        ]);
        $filters['view_mode'] = $this->normaliseOption((string)($filters['view_mode'] ?? 'summary'), ['summary', 'detailed']);

        return [
            'trial_balance_filters' => [
                'search' => (string)($filters['search'] ?? ''),
                'account_type' => $filters['account_type'],
                'focus' => $filters['focus'],
            ],
            'trial_balance_view_mode' => $filters['view_mode'],
            'trial_balance_include_zero' => $this->truthy($actionResult->context()['trial_balance_include_zero'] ?? $request->input('include_zero', '0')),
        ];
    }

    private function normaliseOption(string $value, array $allowed): string
    {
        $value = trim($value);

        return in_array($value, $allowed, true) ? $value : (string)$allowed[0];
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
