<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _anti_fraud_testCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'anti_fraud_test';
    }

    public function services(): array
    {
        return [];
    }

    public function helper(array $context): string
    {
        return 'This card renders the calculated anti-fraud payload directly from AntiFraudService, so it stays local to this card rather than being added to the shared page context.';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['test.context', 'test.antifraud'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '[' . $serviceKey . '] ' . (string)($error['type'] ?? 'error') . ': ' . (string)($error['message'] ?? '');
    }

    public function render(array $context): string
    {
        $antiFraudData = AntiFraudService::instance()->getAntifraudData();
        $antiFraudJson = json_encode($antiFraudData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($antiFraudJson === false) {
            $antiFraudJson = '{}';
        }

        return '
            <div class="stack">
                <pre class="panel-soft preformatted-panel">' . HelperFramework::escape($antiFraudJson) . '</pre>
            </div>
        ';
    }
}
