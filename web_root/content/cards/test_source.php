<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _test_sourceCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'test_source';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'accountsPreview',
                'service' => CompanyAccountService::class,
                'method' => 'fetchAccounts',
                'params' => [
                    'companyId' => ':company_id',
                    'activeOnly' => true,
                ],
            ],
        ];
    }

    public function invalidationFacts(): array
    {
        return ['test.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '<span class="pill">Service state: ' . HelperFramework::escape((string)($error['type'] ?? 'error')) . '</span>';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $preset = (string)($page['selected_preset'] ?? 'alpha');
        $note = (string)($page['note'] ?? '');
        $companyId = (int)($page['company_id'] ?? 0);
        $serviceClass = (string)($page['service_class'] ?? '');
        $accountsPreview = (array)($context['services']['accountsPreview'] ?? []);
        $accountsError = $context['service_errors']['accountsPreview'] ?? null;
        $cardsHtml = '';

        foreach ((array)($page['page_cards'] ?? []) as $cardKey) {
            $cardsHtml .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        $optionsHtml = '';
        foreach ((array)($page['preset_options'] ?? []) as $value => $label) {
            $selected = $value === $preset ? ' selected' : '';
            $optionsHtml .= '<option value="' . HelperFramework::escape((string)$value) . '"' . $selected . '>' . HelperFramework::escape((string)$label) . '</option>';
        }

        return '<div class="card">
            <div class="card-header card-header-has-eyebrow">
                <div>
                    <h2 class="card-title">Context source</h2>
                </div>
                <p class="eyebrow card-header-corner-eyebrow">Card: ' . HelperFramework::escape($this->key()) . '</p>
                <span class="status-pill">Using ' . HelperFramework::escape($serviceClass) . '</span>
            </div>
            <div class="card-body stack">
                <p class="helper">This card posts values into the page context. The next two cards only read from that shared array.</p>
                <form method="post" action="?page=test" data-ajax="true" class="toolbar">
                    <input type="hidden" name="action" value="set-test-context">
                    ' . $cardsHtml . '
                    <div class="form-row">
                        <label for="test-preset">Preset</label>
                        <select class="select" id="test-preset" name="preset">
                            ' . $optionsHtml . '
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="test-note">Note</label>
                        <input class="input" id="test-note" name="note" type="text" value="' . HelperFramework::escape($note) . '">
                    </div>
                    <div class="form-row">
                        <label for="test-company-id">Company ID</label>
                        <input class="input" id="test-company-id" name="company_id" type="number" min="0" value="' . HelperFramework::escape((string)$companyId) . '">
                    </div>
                    <div class="actions-row">
                        <button class="button primary" type="submit">Send context</button>
                    </div>
                </form>
                <div class="pill-row">
                    <span class="pill">Selected preset: ' . HelperFramework::escape(ucfirst($preset)) . '</span>
                    <span class="pill">Context owner: page</span>
                    <span class="pill">Company ID: ' . HelperFramework::escape((string)$companyId) . '</span>
                    <span class="pill">Preview accounts: ' . HelperFramework::escape((string)count($accountsPreview)) . '</span>
                    ' . (is_array($accountsError) && isset($accountsError['rendered']) ? (string)$accountsError['rendered'] : '') . '
                </div>
            </div>
        </div>';
    }
}

