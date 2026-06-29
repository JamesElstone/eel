<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CorporationTaxTreatmentRuleService
{
    private ?array $activeRuleCache = null;

    /**
     * @param array<int, array<string, mixed>>|null $ruleFixtures Unit-test only. Production loads rules from corporation_tax_treatment_rules.
     */
    public function __construct(private readonly ?array $ruleFixtures = null)
    {
    }

    public function resolveTaxTreatment(array $nominal, string $periodStart = '', string $periodEnd = ''): array
    {
        $fallback = trim((string)($nominal['tax_treatment'] ?? 'allowable'));
        foreach ($this->activeRules($periodStart, $periodEnd) as $rule) {
            if (!$this->ruleMatches($rule, $nominal, $periodStart, $periodEnd)) {
                continue;
            }

            return [
                'tax_treatment' => (string)$rule['tax_treatment'],
                'source' => 'corporation_tax_treatment_rules',
                'rule' => $rule,
            ];
        }

        return [
            'tax_treatment' => $fallback,
            'source' => 'nominal_accounts',
            'rule' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchRules(bool $includeInactive = true): array
    {
        if ($this->ruleFixtures !== null) {
            $rules = $includeInactive
                ? $this->ruleFixtures
                : array_values(array_filter($this->ruleFixtures, static fn(array $rule): bool => (int)($rule['is_active'] ?? 1) === 1));
            usort($rules, static function (array $left, array $right): int {
                $priority = (int)($left['priority'] ?? 100) <=> (int)($right['priority'] ?? 100);
                return $priority !== 0 ? $priority : (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0);
            });

            return $rules;
        }

        if (!\InterfaceDB::tableExists('corporation_tax_treatment_rules')) {
            return [];
        }

        $where = $includeInactive ? '' : ' WHERE is_active = 1';

        return \InterfaceDB::fetchAll(
            'SELECT id,
                    rule_code,
                    rule_version,
                    priority,
                    nominal_account_id,
                    nominal_code,
                    account_type,
                    name_contains,
                    tax_treatment,
                    effective_from,
                    effective_to,
                    source_url,
                    source_checked_at,
                    rationale,
                    review_status,
                    is_active
             FROM corporation_tax_treatment_rules'
             . $where .
            ' ORDER BY priority ASC, id ASC'
        );
    }

    public function setRuleActive(int $ruleId, bool $isActive): bool
    {
        if ($ruleId <= 0 || !\InterfaceDB::tableExists('corporation_tax_treatment_rules')) {
            return false;
        }

        $stmt = \InterfaceDB::prepare(
            'UPDATE corporation_tax_treatment_rules
             SET is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'is_active' => $isActive ? 1 : 0,
            'id' => $ruleId,
        ]);
        $this->activeRuleCache = null;

        return $stmt->rowCount() === 1;
    }

    public function setRuleReviewStatus(int $ruleId, string $reviewStatus): bool
    {
        $reviewStatus = strtolower(trim($reviewStatus));
        if (
            $ruleId <= 0
            || !in_array($reviewStatus, $this->validReviewStatuses(), true)
            || !\InterfaceDB::tableExists('corporation_tax_treatment_rules')
        ) {
            return false;
        }

        $stmt = \InterfaceDB::prepare(
            'UPDATE corporation_tax_treatment_rules
             SET review_status = :review_status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'review_status' => $reviewStatus,
            'id' => $ruleId,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeRules(string $periodStart = '', string $periodEnd = ''): array
    {
        if ($this->ruleFixtures !== null) {
            return $this->fetchRules(false);
        }

        if (!\InterfaceDB::tableExists('corporation_tax_treatment_rules')) {
            return [];
        }

        if ($this->activeRuleCache !== null) {
            return $this->activeRuleCache;
        }

        $this->activeRuleCache = $this->fetchRules(false);

        return $this->activeRuleCache;
    }

    private function ruleMatches(array $rule, array $nominal, string $periodStart, string $periodEnd): bool
    {
        if (!$this->dateWindowMatches($rule, $periodStart, $periodEnd)) {
            return false;
        }

        $nominalId = (int)($nominal['id'] ?? 0);
        $ruleNominalId = (int)($rule['nominal_account_id'] ?? 0);
        if ($ruleNominalId > 0 && $nominalId === $ruleNominalId) {
            return true;
        }

        $nominalCode = trim((string)($nominal['code'] ?? ''));
        $ruleNominalCode = trim((string)($rule['nominal_code'] ?? ''));
        if ($ruleNominalCode !== '' && strcasecmp($nominalCode, $ruleNominalCode) === 0) {
            return true;
        }

        $ruleAccountType = trim((string)($rule['account_type'] ?? ''));
        $ruleNameContains = trim((string)($rule['name_contains'] ?? ''));
        $accountTypeMatches = $ruleAccountType !== ''
            && $ruleAccountType === (string)($nominal['account_type'] ?? '');
        $nameMatches = $ruleNameContains !== ''
            && stripos((string)($nominal['name'] ?? ''), $ruleNameContains) !== false;

        if ($ruleAccountType !== '' && $ruleNameContains !== '') {
            return $accountTypeMatches && $nameMatches;
        }

        return $accountTypeMatches || $nameMatches;
    }

    private function dateWindowMatches(array $rule, string $periodStart, string $periodEnd): bool
    {
        $effectiveFrom = trim((string)($rule['effective_from'] ?? ''));
        $effectiveTo = trim((string)($rule['effective_to'] ?? ''));

        if ($periodEnd !== '' && $effectiveFrom !== '' && $effectiveFrom > $periodEnd) {
            return false;
        }

        if ($periodStart !== '' && $effectiveTo !== '' && $effectiveTo < $periodStart) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function validReviewStatuses(): array
    {
        return ['seeded', 'needs_review', 'reviewed'];
    }
}
