<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 */
declare(strict_types=1);

final class _api_keys_editorCard extends CardBaseFramework
{
    public function key(): string { return 'api_keys_editor'; }
    public function title(): string { return 'API Keys Editor'; }

    public function helper(array $context): string
    {
        return 'API key values are write-only. Leave a password field blank to preserve its saved value.';
    }

    public function services(): array
    {
        return [[
            'key' => 'api_keys_editor',
            'service' => \eel_accounts\Service\ApiKeysEditorService::class,
            'method' => 'listing',
        ]];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['api.keys.editor', 'api.connectivity.test', 'companies.house.accounts.submission'];
    }

    public function render(array $context): string
    {
        $rows = (array)(($context['services'] ?? [])['api_keys_editor']['rows'] ?? []);
        $csrf = (string)($context['page']['csrf_token'] ?? '');
        return '<div class="settings-stack">'
            . $this->editorForm($rows, $csrf)
            . $this->companiesHouseTestForm($csrf)
            . '</div>';
    }

    /** @param list<array<string, mixed>> $rows */
    private function editorForm(array $rows, string $csrf): string
    {
        $body = '';
        foreach ($rows as $row) {
            $id = (string)($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $prefix = 'credentials[' . HelperFramework::escape($id) . ']';
            $environment = strtoupper((string)($row['environment'] ?? 'TEST'));
            $body .= '<tr>'
                . $this->hidden($prefix . '[id]', $id)
                . '<td>' . $this->input($prefix . '[provider]', (string)($row['provider'] ?? ''), 'text') . '</td>'
                . '<td>' . $this->input($prefix . '[tag]', (string)($row['tag'] ?? ''), 'text') . '</td>'
                . '<td>' . (!empty($row['legacy'])
                    ? '<input class="input" value="DEFAULT" readonly>'
                        . $this->hidden($prefix . '[environment]', 'DEFAULT')
                    : $this->environment($prefix . '[environment]', $environment)) . '</td>'
                . '<td>' . $this->input($prefix . '[schema]', (string)($row['schema'] ?? ''), 'text') . '</td>'
                . '<td>' . $this->input($prefix . '[url]', (string)($row['url'] ?? ''), 'url') . '</td>'
                . '<td>' . $this->input($prefix . '[api_key]', '', 'password', 'Set/replace API key') . '</td>'
                . '</tr>';
        }
        if ($body === '') {
            $body = '<tr><td colspan="6">No API credential metadata is available.</td></tr>';
        }
        return '<form method="post" action="?page=settings" data-ajax="true" class="settings-stack">'
            . HelperFramework::csrfHiddenInput($csrf)
            . '<input type="hidden" name="card_action" value="ApiKeysEditor">'
            . '<input type="hidden" name="intent" value="save">'
            . '<section class="panel-soft"><div class="table-scroll"><table><thead><tr>'
            . '<th>Provider</th><th>Tag</th><th>Environment</th><th>Schema</th><th>URL</th><th>Set/replace API key</th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table></div></section>'
            . '<section class="panel-soft"><h3 class="card-title">Add credential</h3><div class="form-grid">'
            . $this->labeled('Provider', 'new_credential[provider]')
            . $this->labeled('Tag', 'new_credential[tag]')
            . $this->environment('new_credential[environment]', 'TEST')
            . $this->labeled('Schema', 'new_credential[schema]', 'text', '')
            . $this->labeled('URL', 'new_credential[url]', 'url')
            . $this->labeled('API key', 'new_credential[api_key]', 'password', '', 'Required for a new credential')
            . '</div></section><button class="button primary" type="submit">Save API credentials</button></form>';
    }

    private function companiesHouseTestForm(string $csrf): string
    {
        $fields = [
            'ACCOUNTS_FILING_PRESENTER_ID' => ['Test Presenter ID', 'password', ''],
            'ACCOUNTS_FILING_AUTHENTICATION' => ['Filing authentication value', 'password', ''],
            'ACCOUNTS_FILING_PACKAGE_REFERENCE' => ['Test package reference', 'text', '0012'],
            'COMPANY_DATA_PRESENTER_ID' => ['CompanyData Presenter ID', 'password', ''],
            'COMPANY_DATA_AUTHENTICATION' => ['CompanyData authentication value', 'password', ''],
            'PREFLIGHT_BINDING_HMAC_KEY' => ['Preflight binding key', 'password', 'Optional when generation is selected'],
        ];
        $inputs = '';
        foreach ($fields as $tag => [$label, $type, $placeholder]) {
            $inputs .= $this->labeled(
                $label,
                'companies_house_test[' . $tag . ']',
                $type,
                $tag === 'ACCOUNTS_FILING_PACKAGE_REFERENCE' ? '0012' : '',
                $placeholder
            );
        }
        return '<form method="post" action="?page=settings" data-ajax="true" class="settings-stack">'
            . HelperFramework::csrfHiddenInput($csrf)
            . '<input type="hidden" name="card_action" value="ApiKeysEditor">'
            . '<input type="hidden" name="intent" value="configure_companies_house_test">'
            . '<section class="panel-soft"><h3 class="card-title">Companies House XML TEST credentials</h3>'
            . '<div class="helper">Creates or updates the six Companies House TEST rows. CompanyData credentials remain separate from filing credentials.</div>'
            . '<div class="form-grid">' . $inputs . '</div>'
            . '<label class="checkbox-row"><input type="hidden" name="generate_binding_key" value="0">'
            . '<input type="checkbox" name="generate_binding_key" value="1" checked> Generate preflight binding key</label>'
            . '<button class="button primary" type="submit">Save Companies House TEST credentials</button></section></form>';
    }

    private function labeled(string $label, string $name, string $type = 'text', string $value = '', string $placeholder = ''): string
    {
        return '<label>' . HelperFramework::escape($label)
            . '<input class="input" name="' . HelperFramework::escape($name) . '" type="' . HelperFramework::escape($type)
            . '" value="' . HelperFramework::escape($value) . '" autocomplete="off"'
            . ($placeholder !== '' ? ' placeholder="' . HelperFramework::escape($placeholder) . '"' : '') . '></label>';
    }

    private function input(string $name, string $value, string $type, string $placeholder = ''): string
    {
        return '<input class="input" name="' . HelperFramework::escape($name) . '" type="' . HelperFramework::escape($type)
            . '" value="' . HelperFramework::escape($value) . '" autocomplete="off"'
            . ($placeholder !== '' ? ' placeholder="' . HelperFramework::escape($placeholder) . '"' : '') . '>';
    }

    private function environment(string $name, string $selected): string
    {
        return '<label>Environment<select class="select" name="' . HelperFramework::escape($name) . '">'
            . '<option value="TEST"' . ($selected === 'TEST' ? ' selected' : '') . '>TEST</option>'
            . '<option value="LIVE"' . ($selected === 'LIVE' ? ' selected' : '') . '>LIVE</option>'
            . '</select></label>';
    }

    private function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="' . HelperFramework::escape($name) . '" value="' . HelperFramework::escape($value) . '">';
    }
}
