<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _test_targetCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'test_target';
    }

    public function services(): array
    {
        return [];
    }

    public function helper(array $context) : string
    {
        $testContext = (array)($context['test.context'] ?? []);
        $sharedContext = (array)($testContext['shared_demo_context'] ?? []);

        return HelperFramework::escape((string)($sharedContext['title'] ?? 'No title')) . ' - ' . HelperFramework::escape((string)($sharedContext['summary'] ?? 'No summary available.'));
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['test.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        $message = (string)($error['message'] ?? 'Unknown card service error.');
        $type = (string)($error['type'] ?? 'error');

        return 'Service Error: ' . HelperFramework::escape($message)  . ' (' . HelperFramework::escape($type, '_') .')';
    }

    public function render(array $context): string
    {
        $testContext = (array)($context['test.context'] ?? []);
        $shared = (array)($testContext['shared_demo_context'] ?? []);
        $handledByCards = (array)($shared['handled_by_cards'] ?? []);
        $accounts = (array)($context['services']['accounts'] ?? []);
        $accountsError = $context['service_errors']['accounts'] ?? null;
        $itemsHtml = '';
        $accountsHtml = '';

        foreach ((array)($shared['items'] ?? []) as $item) {
            $itemsHtml .= '
                <div class="panel-soft">
                    <strong>' . HelperFramework::escape((string)$item) . '</strong>
                    <br>
                    <span>Read from the shared context payload prepared by the source card.</span>
                </div>
            ';
        }

        foreach ($accounts as $account) {
            $accountsHtml .= '<div class="list-item">
                <strong>' . HelperFramework::escape((string)($account['account_name'] ?? 'Unknown account')) . '</strong>
                <span>' . HelperFramework::escape((string)($account['account_type'] ?? '')) . '</span>
            </div>';
        }

        if ($accountsHtml === '') {
            $accountsHtml = "No account data available.";
        }

        return '
            <div class="panel-soft">
                <strong>Passed note</strong>
                <div class="helper">' . HelperFramework::escape((string)($shared['note'] ?? '')) . '</div>
            </div>
            <div class="panel-soft">
                <strong>Card handle pipeline</strong>
                <div class="helper">' . HelperFramework::escape(implode(', ', $handledByCards)) . '</div>
            </div>
            ' . $itemsHtml . '
        ';
    }
}
