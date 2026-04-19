<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _hmrc_anti_fraud_testCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'hmrc_anti_fraud_test';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['test.antifraud'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '<span class="pill">Service state: ' . HelperFramework::escape((string)($error['type'] ?? 'error')) . '</span>';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $selectedCompanyId = (int)($page['selected_company_id'] ?? 0);
        $hmrcMode = (string)($page['hmrc_mode'] ?? 'TEST');
        $result = $page['hmrc_antifraud_test_result'] ?? null;
        $cardsHtml = '';

        foreach ((array)($page['page_cards'] ?? []) as $cardKey) {
            $cardsHtml .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        $resultJson = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($resultJson === false) {
            $resultJson = '{}';
        }

        return '<div class="card">
            <div class="card-header card-header-has-eyebrow">
                <div>
                    <h2 class="card-title">HMRC anti-fraud header test</h2>
                </div>
                <p class="eyebrow card-header-corner-eyebrow">Card: ' . HelperFramework::escape($this->key()) . '</p>
                <span class="status-pill">HMRC mode: ' . HelperFramework::escape($hmrcMode) . '</span>
            </div>
            <div class="card-body stack">
                <p class="helper">Runs a same-origin XHR request through this app, then validates the translated Gov-* fraud headers against HMRC using browser-derived facts plus server best-efforts IP detection.</p>
                <form method="post" action="?page=test" data-ajax="true" data-ajax-transport="xhr" class="toolbar">
                    <input type="hidden" name="action" value="run-hmrc-antifraud-test">
                    <input type="hidden" name="company_id" value="' . HelperFramework::escape((string)$selectedCompanyId) . '">
                    ' . $cardsHtml . '
                    <div class="actions-row">
                        <button class="button primary" type="submit">Test anti-fraud headers</button>
                    </div>
                </form>
                <div class="pill-row">
                    <span class="pill">Selected company ID: ' . HelperFramework::escape((string)$selectedCompanyId) . '</span>
                    <span class="pill">Device cookie only: af_client_device_id</span>
                </div>
                <pre class="panel-soft preformatted-panel">' . HelperFramework::escape($resultJson) . '</pre>
            </div>
        </div>';
    }
}
