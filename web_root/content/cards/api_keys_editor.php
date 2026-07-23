<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _api_keys_editorCard extends CardBaseFramework
{
    public function key(): string { return 'api_keys_editor'; }
    public function title(): string { return 'API Keys Editor'; }

    public function helper(array $context): string
    {
        return 'API identity and API key values are write-only. Leave either value blank while editing to preserve its saved value.';
    }

    public function services(): array
    {
        return [['key' => 'api_keys_editor', 'service' => ApiKeysEditorService::class, 'method' => 'listing']];
    }

    protected function additionalInvalidationFacts(): array { return ['api.keys.editor']; }

    public function render(array $context): string
    {
        $result = (array)(($context['services'] ?? [])['api_keys_editor'] ?? []);
        $rows = (array)($result['rows'] ?? []);
        $catalog = array_values(array_filter((array)($result['catalog'] ?? []), 'is_array'));
        $csrf = (string)($context['page']['csrf_token'] ?? '');
        $catalogJson = json_encode($catalog, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
        if (!is_string($catalogJson)) {
            throw new RuntimeException('API credential editor catalog could not be encoded.');
        }

        return '<form method="post" action="?page=settings" data-ajax="true" class="settings-stack" data-api-credential-editor="true" data-api-credential-catalog="' . HelperFramework::escape($catalogJson) . '">'
            . $this->hiddenPageCards($context)
            . HelperFramework::csrfHiddenInput($csrf)
            . '<input type="hidden" name="card_action" value="ApiKeysEditor"><input type="hidden" name="edit_credential_id" value="" data-api-credential-id>'
            . '<section class="panel-soft"><h3 class="card-title">Existing API Keys</h3><div class="table-scroll"><table><thead><tr><th>Provider</th><th>Gateway</th><th>Tag</th><th>Environment</th><th>Schema</th><th>URL</th><th>Action</th></tr></thead><tbody>'
            . $this->rows($rows) . '</tbody></table></div></section>'
            . '<section class="panel-soft"><h3 class="card-title" data-api-credential-editor-title>Add Credential</h3><div class="form-grid">'
            . $this->select('Provider', 'credential[provider]', 'provider', $catalog)
            . $this->select('Gateway', 'credential[gateway]', 'gateway', $catalog)
            . $this->select('Tag', 'credential[tag]', 'tag', $catalog)
            . $this->select('Environment', 'credential[environment]', 'environment', $catalog)
            . $this->input('Schema', 'credential[schema]')
            . $this->input('URL', 'credential[url]', 'url')
            . $this->secretInput('API identity', 'credential[api_identity]', 'Set/replace API identity (optional for new credentials)')
            . $this->secretInput('API key', 'credential[api_key]', 'Set/replace API key')
            . '</div><button class="button primary" type="submit">Save API Credential</button></section></form>';
    }

    /** @param list<array<string, mixed>> $rows */
    private function rows(array $rows): string
    {
        if ($rows === []) {
            return '<tr><td colspan="7">No API credential metadata is available.</td></tr>';
        }
        $html = '';
        foreach ($rows as $row) {
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '') { continue; }
            $html .= '<tr><td>' . HelperFramework::escape((string)($row['provider'] ?? '')) . '</td><td>' . HelperFramework::escape((string)($row['gateway'] ?? '')) . '</td><td>' . HelperFramework::escape((string)($row['tag'] ?? '')) . '</td><td>' . HelperFramework::escape((string)($row['environment'] ?? '')) . '</td><td>' . HelperFramework::escape((string)($row['schema'] ?? '')) . '</td><td>' . HelperFramework::escape((string)($row['url'] ?? '')) . '</td><td><button class="button button-inline" type="button" data-api-credential-edit="true" data-credential-id="' . HelperFramework::escape($id) . '" data-credential-provider="' . HelperFramework::escape((string)($row['provider'] ?? '')) . '" data-credential-gateway="' . HelperFramework::escape((string)($row['gateway'] ?? '')) . '" data-credential-tag="' . HelperFramework::escape((string)($row['tag'] ?? '')) . '" data-credential-environment="' . HelperFramework::escape((string)($row['environment'] ?? '')) . '" data-credential-schema="' . HelperFramework::escape((string)($row['schema'] ?? '')) . '" data-credential-url="' . HelperFramework::escape((string)($row['url'] ?? '')) . '">Edit</button></td></tr>';
        }
        return $html;
    }

    /** @param list<array<string, mixed>> $catalog */
    private function select(string $label, string $name, string $field, array $catalog): string
    {
        $options = [];
        foreach ($catalog as $entry) {
            $value = (string)($entry[$field] ?? '');
            if ($value !== '') { $options[$value] = (string)($entry[$field . '_label'] ?? $value); }
        }
        $html = '<label>' . HelperFramework::escape($label) . '<select class="select" name="' . HelperFramework::escape($name) . '" data-api-credential-field="' . HelperFramework::escape($field) . '" data-no-submit-on-change="true"><option value="">Select ' . HelperFramework::escape($label) . '</option>';
        foreach ($options as $value => $optionLabel) { $html .= '<option value="' . HelperFramework::escape($value) . '">' . HelperFramework::escape($optionLabel) . '</option>'; }
        return $html . '</select></label>';
    }

    private function input(string $label, string $name, string $type = 'text', string $placeholder = ''): string
    {
        return '<label>' . HelperFramework::escape($label) . '<input class="input" name="' . HelperFramework::escape($name) . '" type="' . HelperFramework::escape($type) . '" value="" autocomplete="off"' . ($placeholder !== '' ? ' placeholder="' . HelperFramework::escape($placeholder) . '"' : '') . '></label>';
    }

    private function secretInput(string $label, string $name, string $placeholder): string
    {
        return '<label>' . HelperFramework::escape($label) . '<textarea class="input" name="' . HelperFramework::escape($name) . '" rows="3" autocomplete="off" placeholder="' . HelperFramework::escape($placeholder) . '"></textarea></label>';
    }

    private function hiddenPageCards(array $context): string
    {
        $html = '';
        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }
        return $html;
    }
}
