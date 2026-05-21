<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SiteContextRendererFramework
{
    private const SLOTS = ['sidebar', 'topbar', 'summary'];

    public function renderSlots(
        SiteContextResultFramework $result,
        PageInterfaceFramework $page,
        RequestFramework $request,
        array $context,
        array $hiddenKeys = []
    ): array {
        $html = [];

        foreach (self::SLOTS as $slot) {
            $html[$slot] = $this->renderSlot($slot, $result->selectors(), $page, $request, $context, $hiddenKeys);
        }

        return $html;
    }

    private function renderSlot(
        string $slot,
        array $selectors,
        PageInterfaceFramework $page,
        RequestFramework $request,
        array $context,
        array $hiddenKeys
    ): string {
        $html = [];

        foreach ($selectors as $selector) {
            if (!is_array($selector) || $this->selectorSlot($selector) !== $slot) {
                continue;
            }

            $key = $this->selectorKey($selector);
            if ($key === '' || in_array($key, $hiddenKeys, true) || !$this->selectorVisible($selector)) {
                continue;
            }

            $html[] = $this->selectorType($selector) === 'summary'
                ? $this->renderSummary($selector, $slot)
                : $this->renderSelectorForm($selector, $slot, $page, $request, $context);
        }

        return implode("\n", $html);
    }

    private function renderSelectorForm(
        array $selector,
        string $slot,
        PageInterfaceFramework $page,
        RequestFramework $request,
        array $context
    ): string {
        $key = $this->selectorKey($selector);
        $label = $this->selectorLabel($selector, $key);
        $value = (string)($selector['value'] ?? '');
        $inputName = $this->selectorInputName($selector);
        $fieldName = $inputName !== '' ? $inputName : 'site_context_value';
        $disabled = $this->selectorDisabled($selector);
        $selectClass = 'selector-input' . ($slot === 'sidebar' ? ' sidebar-select' : '');
        $shellClass = $slot === 'topbar'
            ? 'selector-shell topbar-select-shell'
            : 'selector-shell';
        $cards = $this->pageCards($page, $context);

        $hiddenInputs = '<input type="hidden" name="action" value="' . HelperFramework::escape(SiteContextCoordinatorFramework::ACTION) . '">'
            . '<input type="hidden" name="page" value="' . HelperFramework::escape($page->id()) . '">'
            . '<input type="hidden" name="_ajax" value="1">'
            . '<input type="hidden" name="site_context_key" value="' . HelperFramework::escape($key) . '">';

        if ($inputName !== '') {
            $hiddenInputs .= '<input type="hidden" name="site_context_input_name" value="' . HelperFramework::escape($inputName) . '">';
        }

        foreach ($cards as $cardKey) {
            $hiddenInputs .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape($cardKey) . '">';
        }

        return '<form class="selector-form site-context-selector-form" method="post" action="'
            . HelperFramework::escape($this->formAction($request, $page))
            . '" data-ajax="true">'
            . $hiddenInputs
            . '<label class="' . HelperFramework::escape($shellClass) . '">'
            . '<span class="selector-label">' . HelperFramework::escape($label) . '</span>'
            . '<select class="' . HelperFramework::escape($selectClass) . '" name="' . HelperFramework::escape($fieldName) . '" data-site-context-key="' . HelperFramework::escape($key) . '"'
            . ($inputName !== '' ? ' data-site-context-input-name="' . HelperFramework::escape($inputName) . '"' : '')
            . ($disabled ? ' disabled' : '') . '>'
            . $this->renderOptions($selector, $value)
            . '</select>'
            . '</label>'
            . '</form>';
    }

    private function renderSummary(array $selector, string $slot): string
    {
        $key = $this->selectorKey($selector);
        $label = $this->selectorLabel($selector, $key);
        $value = trim((string)($selector['value'] ?? ''));

        if ($value === '') {
            return '';
        }

        return '<div class="site-context-summary-item site-context-summary-item-' . HelperFramework::escape($slot) . '">'
            . '<span class="site-context-summary-label">' . HelperFramework::escape($label) . '</span>'
            . '<span class="site-context-summary-value">' . HelperFramework::escape($value) . '</span>'
            . '</div>';
    }

    private function renderOptions(array $selector, string $selectedValue): string
    {
        $options = is_array($selector['options'] ?? null) ? $selector['options'] : [];
        if ($options === [] && $selectedValue !== '') {
            $options = [
                [
                    'value' => $selectedValue,
                    'label' => $selectedValue,
                ],
            ];
        }

        $html = '';
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $value = (string)($option['value'] ?? '');
            $label = trim((string)($option['label'] ?? ''));
            $label = $label !== '' ? $label : $value;
            $shortLabel = trim((string)($option['short_label'] ?? ''));

            $html .= '<option value="' . HelperFramework::escape($value) . '"'
                . ($value === $selectedValue ? ' selected' : '')
                . ($shortLabel !== '' ? ' data-short-label="' . HelperFramework::escape($shortLabel) . '"' : '')
                . '>' . HelperFramework::escape($label) . '</option>';
        }

        return $html;
    }

    private function selectorKey(array $selector): string
    {
        return trim((string)($selector['key'] ?? ''));
    }

    private function selectorSlot(array $selector): string
    {
        $slot = strtolower(trim((string)($selector['slot'] ?? 'topbar')));

        return in_array($slot, self::SLOTS, true) ? $slot : '';
    }

    private function selectorType(array $selector): string
    {
        $type = strtolower(trim((string)($selector['type'] ?? 'selector')));

        return $type === 'summary' ? 'summary' : 'selector';
    }

    private function selectorLabel(array $selector, string $key): string
    {
        $label = trim((string)($selector['label'] ?? ''));

        return $label !== '' ? $label : HelperFramework::labelFromKey($key, '_', 'Context');
    }

    private function selectorVisible(array $selector): bool
    {
        return !array_key_exists('visible', $selector) || (bool)$selector['visible'];
    }

    private function selectorDisabled(array $selector): bool
    {
        return array_key_exists('disabled', $selector) && (bool)$selector['disabled'];
    }

    private function selectorInputName(array $selector): string
    {
        $inputName = trim((string)($selector['input_name'] ?? ''));

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $inputName) === 1 ? $inputName : '';
    }

    private function formAction(RequestFramework $request, PageInterfaceFramework $page): string
    {
        $pageId = $page->id() !== '' ? $page->id() : $request->getPage();

        return '?page=' . rawurlencode($pageId);
    }

    private function pageCards(PageInterfaceFramework $page, array $context): array
    {
        $cards = $context['page']['page_cards'] ?? $page->cards();

        if (!is_array($cards)) {
            return [];
        }

        return array_values(array_map('strval', $cards));
    }
}
