<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Renderer;

final class WorkflowHandoffRenderer
{
    public static function button(
        string $page,
        string $label,
        array $fields = [],
        string $buttonClass = 'button',
        bool $ajax = true,
        string $formClass = 'inline-form workflow-handoff-form'
    ): string {
        $page = self::pageKey($page);
        if ($page === '') {
            return '';
        }

        unset($fields['page']);

        return '<form method="post" action="?page=' . \HelperFramework::escape(rawurlencode($page)) . '"'
            . ($ajax ? ' data-ajax="true"' : '')
            . ' class="' . \HelperFramework::escape($formClass) . '">'
            . self::hiddenFields($fields)
            . '<button type="submit" class="' . \HelperFramework::escape($buttonClass) . '">'
            . \HelperFramework::escape($label !== '' ? $label : 'Open Related Workflow')
            . '</button></form>';
    }

    public static function fromUrl(
        string $url,
        string $label,
        array $extraFields = [],
        string $buttonClass = 'button',
        bool $ajax = true,
        string $formClass = 'inline-form workflow-handoff-form'
    ): string {
        $parts = parse_url(trim($url));
        $queryFields = [];
        if (isset($parts['query'])) {
            parse_str((string)$parts['query'], $queryFields);
        }

        $page = (string)($queryFields['page'] ?? '');
        unset($queryFields['page']);

        return self::button(
            $page,
            $label,
            array_merge($queryFields, $extraFields),
            $buttonClass,
            $ajax,
            $formClass
        );
    }

    public static function fromWorkflow(
        array $workflow,
        string $fallbackLabel,
        array $extraFields = [],
        string $buttonClass = 'button',
        bool $ajax = true,
        string $formClass = 'inline-form workflow-handoff-form'
    ): string {
        $label = trim((string)($workflow['workflow_label'] ?? $workflow['action_label'] ?? $fallbackLabel));
        $page = trim((string)($workflow['workflow_page'] ?? ''));
        $fields = (array)($workflow['workflow_fields'] ?? []);

        if ($page !== '') {
            return self::button(
                $page,
                $label,
                array_merge($fields, $extraFields),
                $buttonClass,
                $ajax,
                $formClass
            );
        }

        $url = trim((string)($workflow['workflow_url'] ?? $workflow['action_url'] ?? ''));
        return $url !== ''
            ? self::fromUrl($url, $label, $extraFields, $buttonClass, $ajax, $formClass)
            : '';
    }

    private static function pageKey(string $page): string
    {
        $page = trim($page);
        if (str_starts_with($page, '?')) {
            $parts = parse_url($page);
            $query = [];
            parse_str((string)($parts['query'] ?? ''), $query);
            $page = (string)($query['page'] ?? '');
        }

        return trim($page);
    }

    private static function hiddenFields(array $fields): string
    {
        $html = '';
        foreach ($fields as $name => $value) {
            $html .= self::hiddenField((string)$name, $value);
        }

        return $html;
    }

    private static function hiddenField(string $name, mixed $value): string
    {
        if ($name === '' || $value === null) {
            return '';
        }

        if (is_array($value)) {
            $html = '';
            foreach ($value as $childName => $childValue) {
                $fieldName = is_int($childName) ? $name . '[]' : $name . '[' . (string)$childName . ']';
                $html .= self::hiddenField($fieldName, $childValue);
            }

            return $html;
        }

        return '<input type="hidden" name="' . \HelperFramework::escape($name) . '" value="'
            . \HelperFramework::escape((string)$value) . '">';
    }
}
