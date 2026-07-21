<?php
declare(strict_types=1);

final class _tax_frc_taxonomyCard extends CardBaseFramework
{
    public function key(): string { return 'tax_frc_taxonomy'; }
    public function title(): string { return 'FRC accounts iXBRL taxonomy'; }
    public function services(): array { return [['key' => 'packages', 'service' => \eel_accounts\Service\FrcTaxonomyPackageService::class, 'method' => 'fetchPackages']]; }
    protected function additionalInvalidationFacts(): array { return ['frc.taxonomy', 'ixbrl.readiness', 'ixbrl.generation', 'page.context']; }
    public function helper(array $context): string { return 'Install the official FRC taxonomy package used by Arelle for offline validation of FRS 105 accounts iXBRL.'; }
    public function render(array $context): string
    {
        $packages = (array)($context['services']['packages'] ?? []); $active = array_values(array_filter($packages, static fn(array $p): bool => !empty($p['is_active'])))[0] ?? null;
        $status = is_array($active) ? 'Verified ' . (string)$active['taxonomy_version'] . ' / ' . (string)$active['artifact_version'] . ' (' . substr((string)$active['sha256'], 0, 12) . ')' : 'No verified package installed';
        return '<div class="settings-stack"><div class="panel-soft"><p><strong>Status:</strong> ' . HelperFramework::escape($status) . '</p><p class="helper">The package is stored outside Arelle under the managed FRC artifact store and is loaded offline.</p><form method="post" data-ajax="true">' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '<input type="hidden" name="card_action" value="FrcTaxonomy"><input type="hidden" name="intent" value="refresh_frc_taxonomy"><button class="button primary" type="submit">Download and verify FRC taxonomy</button></form></div></div>';
    }
}
