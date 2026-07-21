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
        return 'Pre-warm the pinned XML Gateway transport schemas used for revised accounts. Every actual submission performs this check again and fails closed if it cannot refresh or validate.';
    }

    public function render(array $context): string
    {
        $status = (array)($context['services']['companies_house_accounts_schemas'] ?? []);
        $snapshot = is_array($status['snapshot'] ?? null) ? $status['snapshot'] : null;
        $roots = (array)($status['roots'] ?? []);
        $html = '<div class="settings-stack"><div class="form-row-actions">' . $this->refreshForm() . ' '
            . '<a class="button button-inline" href="https://xmlgw.companieshouse.gov.uk/SchemaStatus" target="_blank" rel="noopener noreferrer">Companies House SchemaStatus</a></div>';
        if ($snapshot === null) {
            $html .= '<div class="notice warning">No verified Companies House accounts transport snapshot is installed yet.</div>';
        } else {
            $html .= '<div class="summary-grid four">'
                . $this->metric('State', 'Verified')
                . $this->metric('Files', (string)($snapshot['file_count'] ?? 0))
                . $this->metric('Last checked', (string)($snapshot['checked_at'] ?? ''))
                . $this->metric('Manifest SHA-256', (string)($snapshot['manifest_sha256'] ?? ''))
                . '</div>';
        }
        $html .= '<div class="table-scroll"><table><thead><tr><th>Profile role</th><th>Pinned schema</th></tr></thead><tbody>';
        foreach ($roots as $role => $url) {
            $html .= '<tr><td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)$role, '_')) . '</td><td><code>' . HelperFramework::escape(basename((string)parse_url((string)$url, PHP_URL_PATH))) . '</code></td></tr>';
        }
        return $html . '</tbody></table></div></div>';
    }

    private function refreshForm(): string
    {
        return '<form method="post" action="?page=tax_artifacts" data-ajax="true">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="CompaniesHouseSchemaArtifacts">'
            . '<input type="hidden" name="intent" value="refresh_companies_house_accounts_schemas">'
            . '<button class="button primary" type="submit">Refresh Companies House filing schemas</button></form>';
    }

    private function metric(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label)
            . '</div><div class="summary-value">' . HelperFramework::escape($value !== '' ? $value : '-') . '</div></div>';
    }
}
