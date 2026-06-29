<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _hmrc_anti_fraud_testCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'hmrc_anti_fraud_test';
    }

    public function services(): array
    {
        return [];
    }

    public function helper(array $context) : string {
        return 'Runs a same-origin XHR request through this app, then validates the translated Gov-* fraud headers against HMRC using browser-derived facts plus server best-efforts IP detection.';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['test.antifraud'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return 'Service state: ' . HelperFramework::escape((string)($error['type'] ?? 'error'));
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $companyId = (int)($context['company']['id'] ?? 0);
        $hmrcMode = (new \eel_accounts\Service\hmrcService())->resolveHmrcMode($companyId);
        $result = $context['hmrc_antifraud_test_result'] ?? null;
        $hasApiError = is_array($result) && array_key_exists('success', $result) && !$result['success'];
        $cardsHtml = '';

        foreach ((array)($page['page_cards'] ?? []) as $cardKey) {
            $cardsHtml .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        $resultJson = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($resultJson === false) {
            $resultJson = '{}';
        }

        return '
            <form method="post" data-ajax="true" data-ajax-transport="xhr" class="toolbar">
                <input type="hidden" name="card_action" value="hmrcCheck">
                <input type="hidden" name="company_id" value="' . HelperFramework::escape((string)$companyId) . '">
                ' . $cardsHtml . '
                <div class="actions-row">
                    <button class="button primary" type="submit">Test anti-fraud headers</button>
                </div>
            </form>
            <div class="pill-row">
                <span class="pill">HMRC mode: ' . HelperFramework::escape($hmrcMode) . '</span>
                ' . ($hasApiError ? '<span class="pill danger">API error</span>' : '') . '
            </div>
            <pre class="panel-soft">' . HelperFramework::escape($resultJson) . '</pre>
            <div class="pill-row">
                <span class="pill">Selected company ID: ' . HelperFramework::escape((string)$companyId) . '</span>
                <span class="pill">Device cookie only: af_client_device_id</span>
            </div>
        ';
    }
}
