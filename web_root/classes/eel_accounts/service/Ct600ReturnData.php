<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Immutable, already-rounded input for one original CT600.
 *
 * Whole-pound values must be rounded by the CT600 mapping layer before this
 * object is created. This avoids silently applying a generic rounding rule to
 * boxes whose HMRC rules differ.
 */
final readonly class Ct600ReturnData
{
    public const SCHEMA_VERSION = '2026-v1.994';

    public const TURNOVER = 'turnover_whole_pounds';
    public const TRADING_PROFITS = 'trading_profits_whole_pounds';
    public const LOSSES_BROUGHT_FORWARD = 'losses_brought_forward_whole_pounds';
    public const NET_TRADING_PROFITS = 'net_trading_profits_whole_pounds';
    public const PROFITS_BEFORE_OTHER_DEDUCTIONS = 'profits_before_other_deductions_whole_pounds';
    public const CAPITAL_ALLOWANCES = 'capital_allowances_whole_pounds';
    public const TRADING_LOSSES = 'trading_losses_whole_pounds';
    public const TRADING_LOSSES_CARRIED_FORWARD = 'trading_losses_carried_forward_whole_pounds';
    public const PROFITS_BEFORE_DONATIONS_AND_GROUP_RELIEF = 'profits_before_donations_group_relief_whole_pounds';
    public const CHARGEABLE_PROFITS = 'chargeable_profits_whole_pounds';
    public const AIA = 'aia_whole_pounds';
    public const LOSS_ARISING = 'loss_arising_whole_pounds';
    public const CORPORATION_TAX = 'corporation_tax_pence';
    public const NET_CORPORATION_TAX = 'net_corporation_tax_pence';
    public const TOTAL_RELIEFS_AND_DEDUCTIONS = 'total_reliefs_deductions_pence';
    public const TAX_PAYABLE = 'tax_payable_pence';

    /** @var list<string> */
    private const REQUIRED_CALCULATION_KEYS = [
        self::TURNOVER,
        self::TRADING_PROFITS,
        self::LOSSES_BROUGHT_FORWARD,
        self::NET_TRADING_PROFITS,
        self::PROFITS_BEFORE_OTHER_DEDUCTIONS,
        self::CAPITAL_ALLOWANCES,
        self::TRADING_LOSSES,
        self::TRADING_LOSSES_CARRIED_FORWARD,
        self::PROFITS_BEFORE_DONATIONS_AND_GROUP_RELIEF,
        self::CHARGEABLE_PROFITS,
        self::CORPORATION_TAX,
        self::NET_CORPORATION_TAX,
        self::TOTAL_RELIEFS_AND_DEDUCTIONS,
        self::TAX_PAYABLE,
    ];

    /** @var list<string> */
    private const OPTIONAL_CALCULATION_KEYS = [
        self::AIA,
        self::LOSS_ARISING,
    ];

    /** @var list<string> */
    private const SUPPLEMENTARY_PAGES = [
        'CT600A', 'CT600B', 'CT600C', 'CT600D', 'CT600E',
        'CT600F', 'CT600G', 'CT600H', 'CT600I', 'CT600J',
        'CT600K', 'CT600L', 'CT600M', 'CT600N', 'CT600P',
    ];

    /** @param array<string, int> $calculation */
    /** @param list<string> $requiredSupplementaryPages */
    /** @param list<string> $requiredAdditionalAttachments */
    public function __construct(
        public int $companyId,
        public int $accountingPeriodId,
        public int $ctPeriodId,
        public int $ctPeriodSequence,
        public int $accountsRunId,
        public int $computationRunId,
        public string $companyName,
        public string $registrationNumber,
        public string $utr,
        public int $companyType,
        public string $accountingPeriodStart,
        public string $accountingPeriodEnd,
        public string $periodStart,
        public string $periodEnd,
        public string $declarationName,
        public string $declarationStatus,
        public bool $declarationConfirmed,
        public array $calculation,
        public bool $multipleReturns = false,
        public array $requiredSupplementaryPages = [],
        public array $requiredAdditionalAttachments = [],
        public bool $isAmendment = false,
        public string $schemaVersion = self::SCHEMA_VERSION,
    ) {
        $this->assertIdentifiers();
        $this->assertStringsAndDates();
        $this->assertCalculation();
        $this->assertScopeInputs();
    }

    public function amount(string $key): int
    {
        if (!array_key_exists($key, $this->calculation)) {
            return 0;
        }

        return $this->calculation[$key];
    }

    /** @return list<string> */
    public function scopeBlockers(): array
    {
        $blockers = [];

        if ($this->isAmendment) {
            $blockers[] = 'Phase one supports original CT600 returns only; amended returns are not implemented.';
        }
        foreach ($this->requiredSupplementaryPages as $page) {
            $blockers[] = 'Supplementary page ' . $page . ' is required but is not supported in phase one.';
        }
        foreach ($this->requiredAdditionalAttachments as $attachment) {
            $blockers[] = 'Additional attachment "' . $attachment . '" is required but is not supported in phase one.';
        }
        if (!$this->declarationConfirmed) {
            $blockers[] = 'The proper officer declaration has not been confirmed for this frozen return.';
        }
        if (
            $this->amount(self::CHARGEABLE_PROFITS) > 0
            || $this->amount(self::CORPORATION_TAX) > 0
            || $this->amount(self::NET_CORPORATION_TAX) > 0
            || $this->amount(self::TAX_PAYABLE) > 0
        ) {
            $blockers[] = 'Phase one supports AP79 nil/loss returns only; financial-year tax-rate allocations are not implemented.';
        }

        return $blockers;
    }

    private function assertIdentifiers(): void
    {
        foreach (
            [
                'companyId' => $this->companyId,
                'accountingPeriodId' => $this->accountingPeriodId,
                'ctPeriodId' => $this->ctPeriodId,
                'ctPeriodSequence' => $this->ctPeriodSequence,
                'accountsRunId' => $this->accountsRunId,
                'computationRunId' => $this->computationRunId,
            ] as $label => $value
        ) {
            if ($value <= 0) {
                throw new \InvalidArgumentException($label . ' must be a positive integer.');
            }
        }

        if (!preg_match('/^[0-9]{10}$/D', $this->utr)) {
            throw new \InvalidArgumentException('Corporation Tax UTR must contain exactly 10 digits and is stored as text.');
        }
        if (!preg_match('/^[A-Z0-9]{2,8}$/D', $this->registrationNumber)) {
            throw new \InvalidArgumentException('Company registration number must contain 2 to 8 uppercase letters or digits.');
        }
        if ($this->companyType < 0 || $this->companyType > 11) {
            throw new \InvalidArgumentException('CT600 company type must be between 0 and 11.');
        }
    }

    private function assertStringsAndDates(): void
    {
        $this->assertHmrcText($this->companyName, 'Company name', 2, 56);
        $this->assertHmrcText($this->declarationName, 'Declaration name', 2, 56);
        $this->assertHmrcText($this->declarationStatus, 'Declaration status', 2, 56);

        foreach (
            [
                'accounting period start' => $this->accountingPeriodStart,
                'accounting period end' => $this->accountingPeriodEnd,
                'CT period start' => $this->periodStart,
                'CT period end' => $this->periodEnd,
            ] as $label => $date
        ) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException(ucfirst($label) . ' must use YYYY-MM-DD.');
            }
        }

        if ($this->accountingPeriodStart > $this->periodStart || $this->periodEnd > $this->accountingPeriodEnd) {
            throw new \InvalidArgumentException('The CT period must fall within the accounting period.');
        }
        if ($this->accountingPeriodStart > $this->accountingPeriodEnd || $this->periodStart > $this->periodEnd) {
            throw new \InvalidArgumentException('Period start dates must not be after their end dates.');
        }

        $ctDays = (new \DateTimeImmutable($this->periodStart))->diff(new \DateTimeImmutable($this->periodEnd))->days;
        if (!is_int($ctDays) || $ctDays + 1 > 365) {
            throw new \InvalidArgumentException('A CT600 period must not exceed 365 days.');
        }
        if (!preg_match('/^[0-9]{4}-v[0-9]{1,3}\.[0-9]{1,3}(?:\.[0-9]{1,3})?$/D', $this->schemaVersion)) {
            throw new \InvalidArgumentException('CT600 schema version is not in the HMRC manifest format.');
        }
    }

    private function assertCalculation(): void
    {
        $allowedKeys = array_merge(self::REQUIRED_CALCULATION_KEYS, self::OPTIONAL_CALCULATION_KEYS);
        foreach (self::REQUIRED_CALCULATION_KEYS as $key) {
            if (!array_key_exists($key, $this->calculation)) {
                throw new \InvalidArgumentException('CT600 calculation is missing ' . $key . '.');
            }
        }
        foreach ($this->calculation as $key => $value) {
            if (!in_array($key, $allowedKeys, true)) {
                throw new \InvalidArgumentException('Unsupported CT600 calculation value: ' . $key . '.');
            }
            if (!is_int($value) || $value < 0) {
                throw new \InvalidArgumentException($key . ' must be a non-negative integer in its named unit.');
            }
            $isPence = str_ends_with($key, '_pence');
            $maximum = $isPence ? 9_999_999_999_999 : 99_999_999_999;
            if ($value > $maximum) {
                throw new \InvalidArgumentException($key . ' exceeds the CT600 RIM monetary limit.');
            }
        }
    }

    private function assertScopeInputs(): void
    {
        foreach ($this->requiredSupplementaryPages as $page) {
            if (!is_string($page) || !in_array($page, self::SUPPLEMENTARY_PAGES, true)) {
                throw new \InvalidArgumentException('Unknown CT600 supplementary page: ' . (string)$page . '.');
            }
        }
        if (count(array_unique($this->requiredSupplementaryPages)) !== count($this->requiredSupplementaryPages)) {
            throw new \InvalidArgumentException('Required CT600 supplementary pages must not contain duplicates.');
        }
        foreach ($this->requiredAdditionalAttachments as $attachment) {
            if (!is_string($attachment) || trim($attachment) === '') {
                throw new \InvalidArgumentException('Required additional attachment labels must be non-empty strings.');
            }
        }
    }

    private function assertHmrcText(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum) {
            throw new \InvalidArgumentException($label . ' must contain ' . $minimum . ' to ' . $maximum . ' characters.');
        }
        if (preg_match('/[£$#~€]/u', $value)) {
            throw new \InvalidArgumentException($label . ' contains a character excluded by the CT600 RIM.');
        }
    }
}
