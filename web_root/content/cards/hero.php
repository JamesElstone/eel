<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _heroCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'hero';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['dashboard.selection'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $focus = (string)($page['focus'] ?? 'architecture');
        $focusLabel = (string)($page['focus_label'] ?? 'Architecture');
        $serviceClass = (string)($page['service_class'] ?? '');
        $cardKeys = (array)($page['page_cards'] ?? []);
        $cardsHtml = '';

        foreach ($cardKeys as $cardKey) {
            $cardsHtml .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        $optionsHtml = '';
        foreach ((array)($page['focus_options'] ?? []) as $value => $label) {
            $selected = $value === $focus ? ' selected' : '';
            $optionsHtml .= '<option value="' . HelperFramework::escape((string)$value) . '"' . $selected . '>' . HelperFramework::escape((string)$label) . '</option>';
        }

        return '<div class="card">
            <div class="card-header card-header-has-eyebrow">
                <div>
                    <h2 class="card-title">Convention-led dashboard module</h2>
                </div>
                <p class="eyebrow card-header-corner-eyebrow">Example page</p>
                <span class="status-pill">Using ' . HelperFramework::escape($serviceClass) . '</span>
            </div>
            <div class="card-body stack">
                <p class="helper">This page proves the new runtime: `_dashboard` declares its services and cards, the caller injects services, and each card resolves lazily from the shared cards directory.</p>
                <form method="post" action="?page=dashboard" data-ajax="true" class="toolbar">
                    <input type="hidden" name="action" value="set-focus">
                    ' . $cardsHtml . '
                    <div class="form-row">
                        <label for="dashboard-focus">Demo focus</label>
                        <select class="select" id="dashboard-focus" name="focus">
                            ' . $optionsHtml . '
                        </select>
                    </div>
                    <div class="actions-row">
                        <button class="button primary" type="submit">Refresh cards</button>
                    </div>
                </form>
                <div class="pill-row">
                    <span class="pill">Current focus: ' . HelperFramework::escape($focusLabel) . '</span>
                    <span class="pill">AJAX card delta response</span>
                    <span class="pill">No registry table</span>
                </div>
            </div>
        </div>';
    }
}

