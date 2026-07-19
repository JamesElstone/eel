<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Serializes frozen monetary facts for a resolved CT600 RIM target without changing the source basis. */
final class Ct600MonetaryValuePolicyService
{
    public const POLICY_VERSION = 'ct600-monetary-v1';

    private const RULE_POUND_PENCE = 'pound_pence';
    private const RULE_WHOLE_POUND_NEAREST = 'whole_pound_nearest';
    private const RULE_WHOLE_POUND_DOWN = 'whole_pound_down';

    /**
     * RIM validations can prescribe a field-specific rule which overrides its general datatype.
     * Keys are stable suffixes of catalogued RIM component paths.
     */
    private const TARGET_RULE_OVERRIDES = [
        'CTF/TonnageTax/QualifyingShips/Total' => self::RULE_WHOLE_POUND_DOWN,
    ];

    private const POUND_PENCE_TYPES = [
        'ct_ctnonzeropoundpencestructure',
        'ct_irmonetarystructure',
        'ct_irnonnegativemonetarystructure',
        'ct_irnonnegativemonetarytype',
        'ctpoundpencestructure',
    ];

    private const WHOLE_POUND_TYPES = [
        'ct_ctnonzerowholepoundstructure',
        'ct_irnonnegativenonzerowholeunitsmonetarystructure',
        'ct_irnonnegativenonzerowholeunitsmonetarytype',
        'ct_irnonnegativewholeunitsmonetarytype',
        'ctwholepoundstructure',
        'irnonnegativewholeunitsmonetarystructure',
    ];

    public function serialize(mixed $sourceValue, string $rimDataType, string $targetPath): string
    {
        $targetPath = trim(str_replace('\\', '/', $targetPath));
        if ($targetPath === '') {
            throw new \InvalidArgumentException('A catalogued RIM target path is required for monetary serialization.');
        }

        $rule = $this->ruleFor($rimDataType, $targetPath);
        [$negative, $whole, $fraction] = $this->decimalParts($sourceValue);

        if ($rule === self::RULE_WHOLE_POUND_DOWN) {
            if ($negative) {
                throw new \InvalidArgumentException('A whole-pound round-down CT600 value cannot be negative.');
            }
            return $whole . '.00';
        }

        $scale = $rule === self::RULE_POUND_PENCE ? 2 : 0;
        $digits = $whole . str_pad(substr($fraction, 0, $scale), $scale, '0');
        $roundingDigit = (int)($fraction[$scale] ?? '0');
        if ($roundingDigit >= 5) {
            $digits = $this->incrementDigits($digits);
        }

        if ($scale === 2) {
            $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);
            $result = substr($digits, 0, -2) . '.' . substr($digits, -2);
        } else {
            $result = $digits . '.00';
        }

        return $negative && $result !== '0.00' ? '-' . $result : $result;
    }

    private function ruleFor(string $rimDataType, string $targetPath): string
    {
        foreach (self::TARGET_RULE_OVERRIDES as $pathSuffix => $rule) {
            if ($targetPath === $pathSuffix || str_ends_with($targetPath, '/' . $pathSuffix)) {
                return $rule;
            }
        }

        $type = strtolower(trim($rimDataType));
        if ($type === '') {
            throw new \InvalidArgumentException('The RIM monetary datatype is missing.');
        }
        $type = str_contains($type, ':') ? (string)substr($type, strrpos($type, ':') + 1) : $type;
        if (in_array($type, self::WHOLE_POUND_TYPES, true)) {
            return self::RULE_WHOLE_POUND_NEAREST;
        }
        if (in_array($type, self::POUND_PENCE_TYPES, true)) {
            return self::RULE_POUND_PENCE;
        }

        throw new \InvalidArgumentException('Unsupported or ambiguous RIM monetary datatype: ' . $rimDataType . '.');
    }

    /** @return array{0: bool, 1: string, 2: string} */
    private function decimalParts(mixed $value): array
    {
        if (is_int($value)) {
            $text = (string)$value;
        } elseif (is_float($value) && is_finite($value)) {
            $text = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        } elseif (is_string($value)) {
            $text = trim($value);
        } else {
            throw new \InvalidArgumentException('The CT600 monetary source value must be a finite decimal number.');
        }

        if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $text) !== 1) {
            throw new \InvalidArgumentException('The CT600 monetary source value is malformed.');
        }

        $negative = str_starts_with($text, '-');
        $unsigned = ltrim($text, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;

        return [$negative && ($whole !== '0' || trim($fraction, '0') !== ''), $whole, $fraction];
    }

    private function incrementDigits(string $digits): string
    {
        $digits = $digits === '' ? '0' : $digits;
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string)((int)$digits[$index] + 1);
                return $digits;
            }
            $digits[$index] = '0';
        }

        return '1' . $digits;
    }
}
