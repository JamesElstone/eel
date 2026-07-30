<?php
declare(strict_types=1);

final class _tax_companies_house_accounts_schemasCard extends CardBaseFramework
{
    public function key(): string { return 'tax_companies_house_accounts_schemas'; }
    public function title(): string { return 'Companies House accounts filing schemas'; }

    public function services(): array
    {
        return [[
            'key' => 'companies_house_accounts_schemas',
            'service' => \eel_accounts\Service\CompaniesHouseAccountsSchemaService::class,
            'method' => 'fetchStatus',
        ]];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['companies.house.accounts.schemas', 'companies.house.accounts.submission', 'page.context'];
    }

    public function helper(array $context): string
    {
        return 'Download, verify and install the pinned XML Gateway schemas used for Companies House accounts filing. Preflight and submission use only the installed files and never download schemas themselves.';
    }

    public function render(array $context): string
    {
        $status = (array)($context['services']['companies_house_accounts_schemas'] ?? []);
        $state = (array)($status['state'] ?? []);
        $files = (array)($status['files'] ?? []);
        $html = '<div class="settings-stack"><div class="form-row-actions">' . $this->refreshForm() . ' '
            . '<a class="button button-inline" href="https://xmlgw.companieshouse.gov.uk/SchemaStatus" target="_blank" rel="noopener noreferrer">Companies House SchemaStatus</a></div>';
        if (empty($state['ready'])) {
            $html .= '<div class="notice warning">'
                . \eel_accounts\Support\Utf8::html(
                    (string)($state['error'] ?? 'No verified Companies House filing schemas are installed.')
                )
                . '</div>';
        } else {
            $html .= '<div class="summary-grid three">'
                . $this->metric('State', 'Verified')
                . $this->metric('Files', (string)($state['file_count'] ?? 0))
                . $this->metric('Last checked', (string)($state['checked_at'] ?? ''))
                . '</div>';
        }
        $html .= '<div class="table-scroll"><table><thead><tr>'
            . '<th>Schema</th><th>Role</th><th>Relative path</th><th>Lifecycle</th><th>SHA-256</th><th>Last verification</th>'
            . '</tr></thead><tbody>';
        if ($files === []) {
            $html .= '<tr><td colspan="6">No installed Companies House schema files.</td></tr>';
        }
        foreach ($files as $file) {
            $statusLabel = trim((string)($file['catalogue_status'] ?? ''));
            $html .= '<tr><td><code>'
                . \eel_accounts\Support\Utf8::html((string)($file['schema_name'] ?? ''))
                . '</code></td><td>'
                . \eel_accounts\Support\Utf8::html(
                    HelperFramework::labelFromKey((string)($file['file_role'] ?? ''), '_')
                )
                . '</td><td><code>'
                . \eel_accounts\Support\Utf8::html((string)($file['relative_path'] ?? ''))
                . '</code></td><td>'
                . \eel_accounts\Support\Utf8::html($statusLabel !== '' ? ucfirst($statusLabel) : '-')
                . '</td><td><code>'
                . \eel_accounts\Support\Utf8::html((string)($file['sha256'] ?? ''))
                . '</code></td><td>'
                . \eel_accounts\Support\Utf8::html((string)($file['verified_at'] ?? ''))
                . '</td></tr>';
        }
        return $html . '</tbody></table></div></div>';
    }

    private function refreshForm(): string
    {
        return '<form method="post" action="?page=tax_artifacts" data-ajax="true">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="CompaniesHouseSchemaArtifacts">'
            . '<input type="hidden" name="intent" value="refresh_companies_house_accounts_schemas">'
            . '<button class="button primary" type="submit">Refresh Companies House Filing Schema</button></form>';
    }

    private function metric(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . \eel_accounts\Support\Utf8::html($label)
            . '</div><div class="summary-value">' . \eel_accounts\Support\Utf8::html($value !== '' ? $value : '-') . '</div></div>';
    }
}
