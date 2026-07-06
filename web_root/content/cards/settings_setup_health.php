<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _settings_setup_healthCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'settings_setup_health';
    }

    public function services(): array
    {
        return [];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['settings_setup_health'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function title() : string {
        return 'Health Status';
    }

    public function helper(array $context): string
    {
        return 'Click "Check Setup Health" to refresh the installation checks for this environment.';
    }

    public function render(array $context): string
    {
        $installationSetupHealthItems = (array)($context['installation_setup_health_items'] ?? []);
        $CompanySetupHealthItems = (array)($context['company_setup_health_items'] ?? []);

        $itemsHtml = '';
        if ($installationSetupHealthItems !== [] || $CompanySetupHealthItems !== []) {

            $itemsHtml .= '<div class="list">';

            foreach ($installationSetupHealthItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $state = $this->itemState($item);
                $itemsHtml .= '<div class="list-item">
                    <strong>' . HelperFramework::escape((string)($item['title'] ?? '')) . '</strong>
                    <span class="status-indicator">
                        <span class="status-square ' . HelperFramework::escape($state) . '"></span>
                        ' . HelperFramework::escape($this->stateLabel($state)) . '
                    </span>
                    <span>' . HelperFramework::escape((string)($item['detail'] ?? '')) . '</span>
                </div>';
            }

            foreach ($CompanySetupHealthItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $state = $this->itemState($item);
                $itemsHtml .= '<div class="list-item">
                    <strong>' . HelperFramework::escape((string)($item['title'] ?? '')) . '</strong>
                    <span class="status-indicator">
                        <span class="status-square ' . HelperFramework::escape($state) . '"></span>
                        ' . HelperFramework::escape($this->stateLabel($state)) . '
                    </span>
                    <span>' . HelperFramework::escape((string)($item['detail'] ?? '')) . '</span>
                </div>';
            }

            $itemsHtml .= '</div>';
        }

        return '<div class="stack">
            <form method="post" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Health">
                <button class="button primary" type="submit" name="intent" value="check">Check Setup Health</button>
            </form>
            ' . $itemsHtml . '
        </div>';
    }

    private function itemState(array $item): string
    {
        $state = strtolower(trim((string)($item['state'] ?? '')));

        if (in_array($state, ['ok', 'warn', 'bad'], true)) {
            return $state;
        }

        return !empty($item['ok']) ? 'ok' : 'bad';
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'ok' => 'OK',
            'warn' => 'Warning',
            default => 'Needs attention',
        };
    }
}
