<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dashboard_action_queueCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'dashboard_action_queue';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'dashboard_data',
                'service' => \eel_accounts\Repository\DashboardRepository::class,
                'method' => 'fetchDashboardData',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function helper(array $context): string {
        return 'This is a to-do list for this application. Check back here to see what to do next.';
    }

    public function title():string {
        return 'Actions requiring attention';
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $dashboardData = (array)(($context['services'] ?? [])['dashboard_data'] ?? []);
        $actionQueue = (array)($dashboardData['activity'] ?? (($context['page'] ?? [])['action_queue'] ?? []));
        $itemsHtml = '';

        foreach ($actionQueue as $item) {
            if (!is_array($item)) {
                continue;
            }

            $state = $this->actionState($item);
            $itemsHtml .= '<div class="list-item">
                <strong>' . HelperFramework::escape((string)($item['title'] ?? '')) . '</strong>
                <span class="status-indicator">
                    <span class="status-square ' . HelperFramework::escape($state) . '"></span>
                    ' . HelperFramework::escape($this->stateLabel($state)) . '
                </span>
                <span>' . HelperFramework::escape((string)($item['detail'] ?? '')) . '</span>
            </div>';
        }

        if ($itemsHtml === '') {
            $itemsHtml = '<div class="list-item">
                <strong>No queued actions</strong>
                <span class="status-indicator">
                    <span class="status-square ok"></span>
                    OK
                </span>
                <span>The dashboard has nothing urgent to surface right now.</span>
            </div>';
        }

        return '<div class="list">' . $itemsHtml . '</div>';
    }

    private function actionState(array $item): string
    {
        $state = strtolower(trim((string)($item['state'] ?? '')));

        if (in_array($state, ['ok', 'warn', 'bad'], true)) {
            return $state;
        }

        $title = strtolower(trim((string)($item['title'] ?? '')));

        if ($title === '' || str_contains($title, 'no immediate actions') || str_contains($title, 'no queued actions')) {
            return 'ok';
        }

        if (str_starts_with($title, 'company health:')) {
            return 'bad';
        }

        return 'warn';
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'ok' => 'OK',
            'bad' => 'Needs attention',
            default => 'Warning',
        };
    }
}
