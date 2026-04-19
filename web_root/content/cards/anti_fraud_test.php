<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _anti_fraud_testCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'anti_fraud_test';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
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

        return '<div class="card">
            <div class="card-header card-header-has-eyebrow">
                <div>
                    <h2 class="card-title">Anti-fraud data</h2>
                </div>
                <p class="eyebrow card-header-corner-eyebrow">Card: ' . HelperFramework::escape($this->key()) . '</p>
            </div>
            <div class="card-body stack">
                <p class="helper">This card renders the calculated anti-fraud payload directly from AntiFraudService, so it stays local to this card rather than being added to the shared page context.</p>
                <pre class="panel-soft preformatted-panel">' . HelperFramework::escape($antiFraudJson) . '</pre>
            </div>
        </div>';
    }
}
