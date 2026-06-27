<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class FieldValidationFramework
{
    public static function normaliseType(string $type): ?string
    {
        return match (strtolower(trim($type))) {
            'bool', 'boolean' => 'boolean',
            'int', 'integer' => 'int',
            'float', 'decimal', 'number' => 'float',
            'string', 'ascii' => 'ascii',
            'null' => 'null',
            default => null,
        };
    }

    public static function validateTypedValue(mixed $value, string $type): array
    {
        $type = self::normaliseType($type) ?? '';
        $value = is_scalar($value) || $value === null ? trim((string)$value) : '';

        return match ($type) {
            'boolean' => self::validateBooleanValue($value),
            'int' => self::validateIntValue($value),
            'float' => self::validateFloatValue($value),
            'ascii' => self::validateAsciiValue($value),
            'null' => self::validResult(null, 'null'),
            default => self::invalidResult('Unsupported value type.'),
        };
    }

    public static function renderTypedValueControl(string $name, mixed $value, string $type, array $options = []): string
    {
        $normalisedType = self::normaliseType($type) ?? '';

        return match ($normalisedType) {
            'boolean' => self::renderBooleanControl($name, $value, $options),
            'int' => self::renderInputControl($name, $value, 'int', 'numeric', 'data-validate-int', $options),
            'float' => self::renderInputControl($name, $value, 'float', 'decimal', 'data-validate-float', $options),
            'ascii' => self::renderInputControl($name, $value, 'ascii', '', 'data-validate-ascii', $options),
            'null' => self::renderNullControl($name, $options),
            default => self::renderInputControl($name, $value, 'ascii', '', 'data-validate-ascii', $options),
        };
    }

    private static function validateBooleanValue(string $value): array
    {
        $value = strtolower($value);
        if (!in_array($value, ['true', 'false'], true)) {
            return self::invalidResult('Value must be true or false.');
        }

        return self::validResult($value, 'boolean');
    }

    private static function validateIntValue(string $value): array
    {
        if (preg_match('/^[0-9]+$/', $value) !== 1) {
            return self::invalidResult('Value must contain digits only.');
        }

        return self::validResult($value, 'int');
    }

    private static function validateFloatValue(string $value): array
    {
        if (preg_match('/^[0-9]*\.?[0-9]+$/', $value) !== 1) {
            return self::invalidResult('Value must be a decimal number.');
        }

        return self::validResult($value, 'float');
    }

    private static function validateAsciiValue(string $value): array
    {
        if (preg_match('/[\x80-\xFF]/', $value) === 1) {
            return self::invalidResult('Value must contain ASCII characters only.');
        }

        return self::validResult($value, 'ascii');
    }

    private static function validResult(mixed $value, string $type): array
    {
        return [
            'success' => true,
            'value' => $value,
            'type' => $type,
            'error' => '',
        ];
    }

    private static function invalidResult(string $error): array
    {
        return [
            'success' => false,
            'value' => null,
            'type' => '',
            'error' => $error,
        ];
    }

    private static function renderBooleanControl(string $name, mixed $value, array $options): string
    {
        $currentValue = strtolower(trim((string)$value));
        $attributes = self::controlAttributes($name, $options, 'select', [
            'data-validate-boolean' => true,
        ]);

        $html = '<select' . $attributes . '>';
        foreach (['true', 'false'] as $optionValue) {
            $html .= '<option value="' . HelperFramework::escape($optionValue) . '"'
                . ($currentValue === $optionValue ? ' selected' : '')
                . '>' . HelperFramework::escape($optionValue) . '</option>';
        }
        $html .= '</select>';

        return $html;
    }

    private static function renderInputControl(
        string $name,
        mixed $value,
        string $normalisedType,
        string $inputMode,
        string $validationAttribute,
        array $options
    ): string {
        $extraAttributes = [
            'type' => 'text',
            $validationAttribute => true,
        ];

        if ($inputMode !== '') {
            $extraAttributes['inputmode'] = $inputMode;
        }

        $attributes = self::controlAttributes($name, $options, 'input', $extraAttributes);

        return '<input' . $attributes . ' value="' . HelperFramework::escape(self::renderValue($value, $normalisedType)) . '">';
    }

    private static function renderNullControl(string $name, array $options): string
    {
        $visibleAttributes = self::controlAttributes('', $options, 'input', [
            'type' => 'text',
            'disabled' => true,
        ]);

        return '<input' . $visibleAttributes . ' value="">'
            . '<input type="hidden" name="' . HelperFramework::escape($name) . '" value="">';
    }

    private static function renderValue(mixed $value, string $type): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        if ($type === 'boolean' && is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string)$value;
    }

    private static function controlAttributes(string $name, array $options, string $element, array $extraAttributes = []): string
    {
        $attributes = [
            'class' => (string)($options['class'] ?? ($element === 'select' ? 'select' : 'input')),
        ];

        if ($name !== '') {
            $attributes['name'] = $name;
        }

        foreach (['id', 'autocomplete', 'placeholder', 'maxlength'] as $key) {
            if (array_key_exists($key, $options) && !is_array($options[$key]) && !is_object($options[$key]) && $options[$key] !== null) {
                $attributes[$key] = (string)$options[$key];
            }
        }

        $typeToken = trim((string)($options['type_token'] ?? ''));
        if ($typeToken !== '') {
            $attributes['data-validate-type-target'] = $typeToken;
        }

        foreach ((array)($options['attributes'] ?? []) as $key => $value) {
            $key = trim((string)$key);
            if ($key === '' || is_array($value) || is_object($value) || $value === null) {
                continue;
            }

            $attributes[$key] = $value === true ? true : (string)$value;
        }

        foreach ($extraAttributes as $key => $value) {
            $attributes[$key] = $value;
        }

        if (!empty($options['required'])) {
            $attributes['required'] = true;
        }

        if (!empty($options['disabled'])) {
            $attributes['disabled'] = true;
        }

        $html = '';
        foreach ($attributes as $key => $value) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }

            if ($value === true) {
                $html .= ' ' . HelperFramework::escape($key);
                continue;
            }

            if ($value === false || $value === null) {
                continue;
            }

            $html .= ' ' . HelperFramework::escape($key) . '="' . HelperFramework::escape((string)$value) . '"';
        }

        return $html;
    }
}
