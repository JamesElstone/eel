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
        $status = (array)($context['services']['status'] ?? []);
        $installed = !empty($status['installed']);
        $version = trim((string)($status['version'] ?? ''));
        $command = trim((string)($status['command'] ?? ''));
        $label = $installed ? 'Installed' . ($version !== '' ? ': ' . $version : '') : 'Not installed';
        $detail = $installed
            ? 'The configured command is ' . ($command !== '' ? $command : 'available') . '.'
            : (string)($status['detail'] ?? 'No working Arelle command is configured.');

        return '<div class="settings-stack"><div class="panel-soft"><p><strong>Status:</strong> '
            . HelperFramework::escape($label) . '</p><p class="helper">' . HelperFramework::escape($detail)
            . '</p><p class="helper">The installer checks PyPI for the current stable arelle-release version, installs that exact version, then validates the bundled iXBRL sample. Python 3.10 or newer must be available on this server.</p>'
            . '<form method="post" data-ajax="true">' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="ArelleDownload"><input type="hidden" name="intent" value="download_arelle">'
            . '<button class="button primary" type="submit">Download and verify Arelle</button></form></div></div>';
    }
}
