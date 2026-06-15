<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _signup_verification_lockoutsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'signup_verification_lockouts';
    }

    public function title(): string
    {
        return 'Signup Verification Lockouts';
    }

    public function helper(array $context): string
    {
        return 'Active client IP and session blocks caused by repeated failed account completion verification attempts.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'signup_verification_lockouts',
                'service' => SignupVerificationRateLimitService::class,
                'method' => 'activeBlocks',
            ],
        ];
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);

        return $this->applyTableSortContext($request, $pageContext, $this->key());
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        return $this->configuredTable($context)->render($context, [
            'cards[]' => (array)($context['page']['page_cards'] ?? []),
        ]);
    }

    public function tables(array $context): array
    {
        return [$this->configuredTable($context)];
    }

    private function configuredTable(array $context): TableFramework
    {
        return $this->configureTableSorting($this->table($context), $context, [
            'page' => (string)($context['page']['page_id'] ?? 'logs'),
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
        ]);
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('signup-verification-lockouts')
            ->exportLimit(200)
            ->empty('No client IPs or sessions are currently blocked for signup verification attempts.')
            ->textColumn('scope_type_label', 'Scope Type')
            ->textColumn('scope_label', 'Scope')
            ->textColumn('failed_attempts', 'Attempts', fallback: '0', exportType: 'number')
            ->textColumn('window_started_at', 'Window Started')
            ->textColumn('last_failed_at', 'Last Failed')
            ->textColumn('block_expires_at', 'Blocked Until')
            ->column(
                'action',
                'Action',
                html: fn(array $row): string => $this->resetButtonHtml(
                    $context,
                    (string)($row['scope_type'] ?? ''),
                    (string)($row['scope_key'] ?? '')
                ),
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function rows(array $context): array
    {
        $rows = [];
        foreach ((array)(($context['services'] ?? [])['signup_verification_lockouts'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $scopeType = strtolower(trim((string)($row['scope_type'] ?? '')));
            $scopeKey = trim((string)($row['scope_key'] ?? ''));
            if (!in_array($scopeType, ['ip', 'session'], true) || $scopeKey === '') {
                continue;
            }

            $row['scope_type_label'] = $scopeType === 'ip' ? 'Client IP' : 'Session';
            $row['scope_label'] = $this->safeScopeLabel($scopeType, (string)($row['scope_label'] ?? ''), $scopeKey);
            $rows[] = $row;
        }

        return $rows;
    }

    private function resetButtonHtml(array $context, string $scopeType, string $scopeKey): string
    {
        $scopeType = strtolower(trim($scopeType));
        $scopeKey = trim($scopeKey);
        if (!in_array($scopeType, ['ip', 'session'], true) || $scopeKey === '') {
            return '';
        }

        return '<form method="post" action="?page=logs" data-ajax="true">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="action" value="logs-reset-signup-verification-lockout">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape((string)($context['page']['csrf_token'] ?? '')) . '">
            <input type="hidden" name="scope_type" value="' . HelperFramework::escape($scopeType) . '">
            <input type="hidden" name="scope_key" value="' . HelperFramework::escape($scopeKey) . '">
            <button class="button primary" type="submit">Reset Lockout</button>
        </form>';
    }

    private function safeScopeLabel(string $scopeType, string $scopeLabel, string $scopeKey): string
    {
        $scopeLabel = trim($scopeLabel);
        if ($scopeType === 'session') {
            return str_starts_with($scopeLabel, 'session:')
                ? $scopeLabel
                : 'session:' . substr($scopeKey, 0, 12);
        }

        return $scopeLabel !== '' ? $scopeLabel : $scopeKey;
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'signup.verification.lockouts');
    }

    private function hiddenFields(array $context): string
    {
        $html = '';

        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        return $html;
    }
}
