<?php
declare(strict_types=1);

final class _tax_arelle_downloadCard extends CardBaseFramework
{
    public function key(): string { return 'tax_arelle_download'; }
    public function title(): string { return 'Arelle Download'; }
    public function services(): array { return [['key' => 'status', 'service' => \eel_accounts\Service\ArelleDownloadService::class, 'method' => 'status']]; }
    protected function additionalInvalidationFacts(): array { return ['arelle.download', 'ixbrl.readiness', 'ixbrl.generation', 'page.context']; }
    public function helper(array $context): string { return 'Download and smoke-test the current Arelle command-line validator in this server’s project-local Python environment.'; }
    public function render(array $context): string
    {
        $status = (array)($context['services']['status'] ?? []); $installed = !empty($status['installed']); $version = trim((string)($status['version'] ?? '')); $latestVersion = trim((string)($context['arelle_download']['latest_version'] ?? ''));
        $label = $installed ? 'Installed' . ($version !== '' ? ': ' . $version : '') : 'Not installed';
        $canDownload = !empty($status['python_available']);
        return '<div class="settings-stack"><div class="panel-soft"><p><strong>Status:</strong> ' . \eel_accounts\Support\Utf8::html($label) . '</p><p><strong>Arelle command:</strong> ' . (!empty($status['command_exists']) ? 'OK' : 'Missing') . '</p><p><strong>Python 3.10:</strong> ' . (!empty($status['python_available']) ? 'OK' : 'Missing') . '</p>' . ($latestVersion !== '' ? '<p><strong>Latest:</strong> ' . \eel_accounts\Support\Utf8::html($latestVersion) . '</p>' : '') . '<form method="post" data-ajax="true">' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '<input type="hidden" name="card_action" value="ArelleDownload"><input type="hidden" name="intent" value="download_arelle"><button class="button primary" type="submit"' . ($canDownload ? '' : ' disabled aria-disabled="true"') . '>Download and verify Arelle</button></form><form method="post" data-ajax="true">' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '<input type="hidden" name="card_action" value="ArelleDownload"><input type="hidden" name="intent" value="check_arelle_version"><button class="button" type="submit">Check latest version</button></form></div></div>';
    }
}