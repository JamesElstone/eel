<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _signup_token_lockoutsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'signup_token_lockouts';
    }

    public function title(): string
    {
        return 'Signup Token Lockouts';
    }

    public function helper(array $context): string
    {
        return 'Active client IP blocks caused by repeated invalid account completion token attempts.';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'signup_token_lockouts',
                'service' => SignupTokenRateLimitService::class,
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
            ->filename('signup-token-lockouts')
            ->exportLimit(200)
            ->empty('No client IPs are currently blocked for signup token attempts.')
            ->textColumn('client_ip', 'Client IP')
            ->textColumn('failed_attempts', 'Attempts', fallback: '0', exportType: 'number')
            ->textColumn('window_started_at', 'Window Started')
            ->textColumn('last_failed_at', 'Last Failed')
            ->textColumn('block_expires_at', 'Blocked Until')
            ->column(
                'action',
                'Action',
                html: fn(array $row): string => $this->resetButtonHtml($context, (string)($row['client_ip'] ?? '')),
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function rows(array $context): array
    {
        $rows = [];
        foreach ((array)(($context['services'] ?? [])['signup_token_lockouts'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $clientIp = trim((string)($row['client_ip'] ?? ''));
            if ($clientIp === '') {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function resetButtonHtml(array $context, string $clientIp): string
    {
        $clientIp = trim($clientIp);
        if ($clientIp === '') {
            return '';
        }

        return '<form method="post" action="?page=logs" data-ajax="true">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="action" value="logs-reset-signup-token-lockout">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape((string)($context['page']['csrf_token'] ?? '')) . '">
            <input type="hidden" name="client_ip" value="' . HelperFramework::escape($clientIp) . '">
            <button class="button primary" type="submit">Reset Lockout</button>
        </form>';
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'signup.token.lockouts');
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
