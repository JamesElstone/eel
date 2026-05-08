<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _test_sourceCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'test_source';
    }

    public function helper(array $context): string {
        return "This card posts values into the page context. The `Test Target` card reads from the shared context array.";
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $testContext = (array)($pageContext['test.context'] ?? []);
        $sharedContext = (array)($testContext['shared_demo_context'] ?? []);
        $sharedContext['handled_by_cards'] = array_values(array_unique(array_merge(
            (array)($sharedContext['handled_by_cards'] ?? []),
            [$this->key()]
        )));
        $sharedContext['last_action_type'] = $request->action();
        $testContext['shared_demo_context'] = $sharedContext;
        $pageContext['test.context'] = $testContext;

        return $pageContext;
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['test.context'];
    }



    public function render(array $context): string
    {
        $testContext = (array)($context['test.context'] ?? []);
        $preset = (string)($testContext['selected_preset'] ?? 'alpha');
        $note = (string)($testContext['note'] ?? '');
        $serviceClass = (string)($testContext['service_class'] ?? '');
        $cardsHtml = '';

        foreach ((array)(($context['page']['page_cards'] ?? [])) as $cardKey) {
            $cardsHtml .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        $optionsHtml = '';
        foreach ((array)($testContext['preset_options'] ?? []) as $value => $label) {
            $selected = $value === $preset ? ' selected' : '';
            $optionsHtml .= '<option value="' . HelperFramework::escape((string)$value) . '"' . $selected . '>' . HelperFramework::escape((string)$label) . '</option>';
        }

        return '
            <form method="post" data-ajax="true" class="toolbar">
                <input type="hidden" name="action" value="set-test-context">
                ' . $cardsHtml . '
                <div class="form-row">
                    <label for="test-preset">Preset</label>
                    <select class="select" id="test-preset" name="preset">
                        ' . $optionsHtml . '
                    </select>
                </div>
                <div class="form-row">
                    <label for="test-note">Note</label>
                    <input class="input" id="test-note" name="note" type="text" value="' . HelperFramework::escape($note) . '">
                </div>
                <div class="form-row">
                    <button class="button primary" type="submit">Apply Test Context</button>
                </div>
            </form>
            <div class="pill-row">
                <span class="pill">Selected preset: ' . HelperFramework::escape(ucfirst($preset)) . '</span>
                <span class="pill">Context owner: page</span>
            </div>
        ';
    }
}
