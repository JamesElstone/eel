<?php
declare(strict_types=1);

final class _heroCard implements WebCardInterface
{
    public function key(): string
    {
        return 'hero';
    }

    public function invalidationFacts(): array
    {
        return ['dashboard.selection'];
    }

    public function render(array $context): string
    {
        $focus = (string)($context['focus'] ?? 'architecture');
        $focusLabel = (string)($context['focus_label'] ?? 'Architecture');
        $serviceClass = (string)($context['service_class'] ?? '');
        $cardKeys = (array)($context['page_cards'] ?? []);
        $cardsHtml = '';

        foreach ($cardKeys as $cardKey) {
            $cardsHtml .= '<input type="hidden" name="cards[]" value="' . FrameworkHelper::escape((string)$cardKey) . '">';
        }

        $optionsHtml = '';
        foreach ((array)($context['focus_options'] ?? []) as $value => $label) {
            $selected = $value === $focus ? ' selected' : '';
            $optionsHtml .= '<option value="' . FrameworkHelper::escape((string)$value) . '"' . $selected . '>' . FrameworkHelper::escape((string)$label) . '</option>';
        }

        return '<div class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Example page</p>
                    <h2 class="card-title">Convention-led dashboard module</h2>
                </div>
                <span class="status-pill">Using ' . FrameworkHelper::escape($serviceClass) . '</span>
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
                    <span class="pill">Current focus: ' . FrameworkHelper::escape($focusLabel) . '</span>
                    <span class="pill">AJAX card delta response</span>
                    <span class="pill">No registry table</span>
                </div>
            </div>
        </div>';
    }
}
