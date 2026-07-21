<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

final class _filing_evidence_lookupCard extends CardBaseFramework
{
    public function key(): string { return 'filing_evidence_lookup'; }
    public function title(): string { return 'Evidence Lookup'; }
    public function helper(array $context): string { return 'Enter an EEL-FE bundle ID or EEL-AR artifact ID. Lookup is restricted to the selected company.'; }
    protected function additionalInvalidationFacts(): array { return ['filing.evidence.selection']; }
    public function render(array $context): string
    {
        return '<form method="post" action="?page=filing_evidence" data-ajax="true" class="toolbar">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="action" value="lookup-filing-evidence">'
            . '<input type="hidden" name="company_id" value="' . (int)($context['company']['id'] ?? 0) . '">'
            . '<label for="filing-evidence-id">EEL Evidence ID</label>'
            . '<input class="input" id="filing-evidence-id" name="evidence_id" type="text" maxlength="80" '
            . 'placeholder="EEL-FE-… or EEL-AR-…" value="' . HelperFramework::escape((string)($context['filing_evidence']['reference'] ?? '')) . '">'
            . '<button class="button primary" type="submit">Look up evidence</button></form>';
    }
}
