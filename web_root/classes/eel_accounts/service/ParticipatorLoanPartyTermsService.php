<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Live party terms with immutable accounting-period snapshots. */
final class ParticipatorLoanPartyTermsService
{
    private const TERMS = 'participator_loan_party_terms';
    private const AUDIT = 'participator_loan_party_terms_audit';
    private const SNAPSHOTS = 'participator_loan_party_term_snapshots';

    public function fetchTerms(int $companyId, int $partyId, ?int $accountingPeriodId = null): array
    {
        $party = $this->party($companyId, $partyId);
        if ($party === null) {
            return $this->error('The selected participator loan party does not belong to this company.');
        }
        if (!$this->ready()) {
            return $this->error('Run the participator loan party terms migration.');
        }

        $locked = $accountingPeriodId !== null && $accountingPeriodId > 0
            && (new YearEndLockService())->isLocked($companyId, $accountingPeriodId);
        $snapshot = $locked ? $this->snapshot($companyId, $accountingPeriodId, $partyId) : null;
        $live = $this->live($companyId, $partyId);
        $terms = $snapshot['terms'] ?? $live ?? $this->defaults();

        return [
            'success' => true,
            'party' => $party,
            'terms' => $terms,
            'explicit' => $snapshot !== null || $live !== null,
            'terms_source' => $snapshot !== null ? 'locked_snapshot' : ($live !== null ? 'live' : 'default'),
            'is_locked' => $locked,
            'schema_ready' => true,
            'accounting_period_id' => $accountingPeriodId,
            'liability_nominal_account_id' => (int)($snapshot['liability_nominal_account_id'] ?? 0),
        ];
    }

    /** Strict reporting resolver: a relevant party in a locked period may never fall back to live terms. */
    public function resolveForReporting(int $companyId, int $accountingPeriodId, int $partyId): array
    {
        if ((new YearEndLockService())->isLocked($companyId, $accountingPeriodId)) {
            $snapshot = $this->snapshot($companyId, $accountingPeriodId, $partyId);
            if ($snapshot === null) {
                throw new \RuntimeException('The locked accounting period has no Participator Loan terms snapshot for party #' . $partyId . '.');
            }
            return $snapshot['terms'] + [
                'explicit' => true,
                'terms_source' => 'locked_snapshot',
                'liability_nominal_account_id' => (int)$snapshot['liability_nominal_account_id'],
            ];
        }

        $live = $this->live($companyId, $partyId);
        return ($live ?? $this->defaults()) + [
            'explicit' => $live !== null,
            'terms_source' => $live !== null ? 'live' : 'default',
            'liability_nominal_account_id' => 0,
        ];
    }

    /** Terms card source: each party with an assigned loan-control line up to today. */
    public function fetchTermsWorkspace(int $companyId, int $accountingPeriodId): array
    {
        if (!$this->ready()) {
            return $this->error('Run the participator loan party terms migration.');
        }
        $controls = (new DirectorLoanAttributionService())->controlNominalIds($companyId);
        if ((int)$controls['asset'] <= 0 || (int)$controls['liability'] <= 0) {
            return ['success' => true, 'parties' => [], 'schema_ready' => true, 'is_locked' => false];
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT DISTINCT p.id, p.legal_name, p.party_type, p.linked_director_id
             FROM journal_lines jl
             INNER JOIN journals j ON j.id = jl.journal_id
             INNER JOIN company_parties p ON p.id = jl.party_id AND p.company_id = j.company_id
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND j.journal_date <= :today
               AND jl.nominal_account_id IN (:asset_nominal_id, :liability_nominal_id)
               AND jl.party_id IS NOT NULL
             ORDER BY p.legal_name, p.id',
            [
                'company_id' => $companyId,
                'today' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                'asset_nominal_id' => (int)$controls['asset'],
                'liability_nominal_id' => (int)$controls['liability'],
            ]
        );
        $parties = [];
        foreach ($rows as $party) {
            $item = $this->fetchTerms($companyId, (int)$party['id'], $accountingPeriodId);
            if (!empty($item['success'])) {
                $parties[] = $item;
            }
        }
        return [
            'success' => true,
            'parties' => $parties,
            'schema_ready' => true,
            'is_locked' => (new YearEndLockService())->isLocked($companyId, $accountingPeriodId),
        ];
    }

    public function save(int $companyId, int $partyId, array $input, string $changedBy = 'web_app'): array
    {
        if (!$this->ready()) {
            return $this->error('Run the participator loan party terms migration.');
        }
        if ($this->party($companyId, $partyId) === null) {
            return $this->error('The selected participator loan party does not belong to this company.');
        }
        $repaymentValues = $this->repaymentValuesForSave($input);
        if ($repaymentValues === null) {
            return $this->error('Select one valid repayment basis.');
        }
        $terms = $this->normalise(array_replace($input, $repaymentValues));
        if ($terms === null) {
            return $this->error('Enter valid participator loan terms.');
        }

        $old = $this->live($companyId, $partyId);
        $oldRevision = (int)($old['revision'] ?? 0);
        $json = \eel_accounts\Support\PersistentJson::encode($terms, JSON_UNESCAPED_SLASHES);
        if ($old !== null
            && \eel_accounts\Support\PersistentJson::encode($this->withoutMeta($old), JSON_UNESCAPED_SLASHES) === $json) {
            return ['success' => true, 'changed' => false, 'terms' => $old];
        }

        $rateColumn = $this->interestRateColumn();
        \InterfaceDB::transaction(function () use (
            $companyId,
            $partyId,
            $terms,
            $old,
            $oldRevision,
            $changedBy,
            $json,
            $rateColumn
        ): void {
            $params = $terms + [
                'company_id' => $companyId,
                'party_id' => $partyId,
                'actor' => substr(trim($changedBy) !== '' ? trim($changedBy) : 'web_app', 0, 100),
                'revision' => $oldRevision + 1,
            ];
            if ($old !== null) {
                \InterfaceDB::prepareExecute(
                    'UPDATE ' . self::TERMS . '
                     SET ' . $rateColumn . ' = :interest_rate_percent,
                         security_type = :security_type,
                         repayable_on_demand = :repayable_on_demand,
                         repayment_timing = :repayment_timing,
                         deferment_right_confirmed = :deferment_right_confirmed,
                         set_off_right_confirmed = :set_off_right_confirmed,
                         settlement_intention = :settlement_intention,
                         revision = :revision,
                         updated_by = :actor,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE company_id = :company_id AND party_id = :party_id',
                    $params
                );
            } else {
                \InterfaceDB::prepareExecute(
                    'INSERT INTO ' . self::TERMS . ' (
                        company_id, party_id, ' . $rateColumn . ', security_type,
                        repayable_on_demand, repayment_timing, deferment_right_confirmed,
                        set_off_right_confirmed, settlement_intention, revision, created_by, updated_by
                     ) VALUES (
                        :company_id, :party_id, :interest_rate_percent, :security_type,
                        :repayable_on_demand, :repayment_timing, :deferment_right_confirmed,
                        :set_off_right_confirmed, :settlement_intention, :revision, :actor, :actor
                     )',
                    $params
                );
            }
            \InterfaceDB::prepareExecute(
                'INSERT INTO ' . self::AUDIT . ' (
                    company_id, party_id, old_terms_json, new_terms_json,
                    old_revision, new_revision, changed_by
                 ) VALUES (
                    :company_id, :party_id, :old, :new,
                    :old_revision, :new_revision, :actor
                 )',
                [
                    'company_id' => $companyId,
                    'party_id' => $partyId,
                    'old' => $old === null ? null : \eel_accounts\Support\PersistentJson::encode($this->withoutMeta($old), JSON_UNESCAPED_SLASHES),
                    'new' => $json,
                    'old_revision' => $oldRevision,
                    'new_revision' => $oldRevision + 1,
                    'actor' => substr(trim($changedBy) !== '' ? trim($changedBy) : 'web_app', 0, 100),
                ]
            );
        });
        \eel_accounts\Support\RequestCache::clear();
        return ['success' => true, 'changed' => true, 'terms' => $this->live($companyId, $partyId)];
    }

    /** Replace the selected period's complete snapshot set inside the caller's lock transaction. */
    public function snapshotPeriod(int $companyId, int $accountingPeriodId, string $actor = 'web_app'): array
    {
        if (!$this->ready()) {
            return $this->error('Run the participator loan party terms migration before locking periods.');
        }
        if (!\InterfaceDB::inTransaction()) {
            return $this->error('Participator Loan terms can only be frozen inside the Year End lock transaction.');
        }
        $controls = (new DirectorLoanAttributionService())->controlNominalIds($companyId);
        if ((int)($controls['asset'] ?? 0) <= 0 || (int)($controls['liability'] ?? 0) <= 0) {
            \InterfaceDB::prepareExecute(
                'DELETE FROM ' . self::SNAPSHOTS . '
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            );
            return ['success' => true, 'snapshotted_party_count' => 0, 'party_ids' => []];
        }
        $statement = (new DirectorLoanService())->fetchStatement($companyId, $accountingPeriodId);
        if (empty($statement['success'])) {
            return $this->error((string)(($statement['errors'] ?? [])[0] ?? 'Participator loan statement unavailable.'));
        }

        $relevant = [];
        $missing = [];
        foreach ((array)$statement['per_director'] as $position) {
            $partyId = (int)($position['director_id'] ?? 0);
            if ($partyId <= 0 || !$this->positionRequiresTerms((array)$position)) {
                continue;
            }
            $terms = $this->live($companyId, $partyId);
            if ($terms === null) {
                $missing[] = (string)($position['director_name'] ?? ('Party #' . $partyId));
                continue;
            }
            $relevant[] = ['party_id' => $partyId, 'terms' => $terms];
        }
        if ($missing !== []) {
            return $this->error('Save Participator Loan terms for: ' . implode(', ', array_values(array_unique($missing))) . '.');
        }

        $nominalId = (int)($statement['liability_nominal']['id'] ?? 0);
        if ($relevant !== [] && $nominalId <= 0) {
            return $this->error('The Participator Loan liability nominal could not be snapshotted.');
        }
        \InterfaceDB::prepareExecute(
            'DELETE FROM ' . self::SNAPSHOTS . '
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        foreach ($relevant as $row) {
            \InterfaceDB::prepareExecute(
                'INSERT INTO ' . self::SNAPSHOTS . ' (
                    company_id, accounting_period_id, party_id, liability_nominal_account_id,
                    terms_json, created_by
                 ) VALUES (
                    :company_id, :accounting_period_id, :party_id, :liability_nominal_account_id,
                    :terms_json, :created_by
                 )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'party_id' => (int)$row['party_id'],
                    'liability_nominal_account_id' => $nominalId,
                    'terms_json' => \eel_accounts\Support\PersistentJson::encode(
                        $this->withoutMeta((array)$row['terms']) + [
                            'revision' => max(0, (int)(($row['terms'] ?? [])['revision'] ?? 0)),
                        ],
                        JSON_UNESCAPED_SLASHES
                    ),
                    'created_by' => substr(trim($actor) !== '' ? trim($actor) : 'web_app', 0, 100),
                ]
            );
        }
        return ['success' => true, 'snapshotted_party_count' => count($relevant), 'party_ids' => array_column($relevant, 'party_id')];
    }

    public function clearPeriodSnapshots(int $companyId, int $accountingPeriodId): array
    {
        if (!\InterfaceDB::inTransaction()) {
            return $this->error('Participator Loan snapshots can only be reopened inside the Year End unlock transaction.');
        }
        \InterfaceDB::prepareExecute(
            'DELETE FROM ' . self::SNAPSHOTS . '
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        return ['success' => true];
    }

    public function resolved(int $companyId, int $accountingPeriodId, int $partyId): array
    {
        return $this->resolveForReporting($companyId, $accountingPeriodId, $partyId);
    }

    /**
     * Return the liability control nominal frozen with a locked period.
     *
     * All rows in a period are written together, so differing mappings indicate
     * corrupt lock evidence and must not be silently resolved.
     */
    public function periodLiabilityNominalAccountId(int $companyId, int $accountingPeriodId): ?int
    {
        if (!$this->ready()) {
            return null;
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT DISTINCT liability_nominal_account_id
             FROM ' . self::SNAPSHOTS . '
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int)($row['liability_nominal_account_id'] ?? 0),
            $rows
        ), static fn(int $id): bool => $id > 0)));
        if (count($ids) > 1) {
            throw new \RuntimeException('The locked accounting period contains inconsistent Participator Loan liability nominal mappings.');
        }
        return $ids[0] ?? null;
    }

    private function live(int $companyId, int $partyId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::TERMS . '
             WHERE company_id = :company_id AND party_id = :party_id LIMIT 1',
            ['company_id' => $companyId, 'party_id' => $partyId]
        );
        if (is_array($row)
            && !array_key_exists('interest_rate_percent', $row)
            && array_key_exists('INTEGERerest_rate_percent', $row)) {
            $row['interest_rate_percent'] = $row['INTEGERerest_rate_percent'];
        }
        $normalised = is_array($row) ? $this->normalise($row) : null;
        return $normalised !== null ? $normalised + ['revision' => (int)($row['revision'] ?? 0)] : null;
    }

    /**
     * eelKit's current MariaDB-to-SQLite test converter interprets the `int`
     * prefix of this identifier as a data type. Keep that test-only artefact
     * isolated here; MariaDB and corrected SQLite schemas use the real name.
     */
    private function interestRateColumn(): string
    {
        if (\InterfaceDB::columnExists(self::TERMS, 'interest_rate_percent')) {
            return '`interest_rate_percent`';
        }
        if (\InterfaceDB::driverName() === 'sqlite'
            && \InterfaceDB::columnExists(self::TERMS, 'INTEGERerest_rate_percent')) {
            return '`INTEGERerest_rate_percent`';
        }
        return '`interest_rate_percent`';
    }

    private function snapshot(int $companyId, int $periodId, int $partyId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT liability_nominal_account_id, terms_json, created_at
             FROM ' . self::SNAPSHOTS . '
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND party_id = :party_id
             LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $periodId, 'party_id' => $partyId]
        );
        if (!is_array($row)) {
            return null;
        }
        $decoded = json_decode((string)$row['terms_json'], true);
        $terms = is_array($decoded) ? $this->normalise($decoded) : null;
        if ($terms === null) {
            throw new \RuntimeException('The saved Participator Loan terms snapshot is invalid.');
        }
        $terms['revision'] = max(0, (int)($decoded['revision'] ?? 0));
        return [
            'terms' => $terms,
            'liability_nominal_account_id' => (int)($row['liability_nominal_account_id'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    private function party(int $companyId, int $partyId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id, legal_name, party_type, linked_director_id
             FROM company_parties
             WHERE id = :party_id AND company_id = :company_id LIMIT 1',
            ['party_id' => $partyId, 'company_id' => $companyId]
        );
        return is_array($row) ? $row : null;
    }

    private function positionRequiresTerms(array $position): bool
    {
        if ((int)($position['period_movement_count'] ?? 0) > 0
            || !empty($position['has_period_movement'])) {
            return true;
        }
        foreach (['gross_asset', 'gross_liability', 'movement_asset', 'movement_liability'] as $key) {
            if (abs((float)($position[$key] ?? 0)) >= 0.005) {
                return true;
            }
        }
        return false;
    }

    private function defaults(): array
    {
        return [
            'interest_rate_percent' => 0.0,
            'security_type' => 'unsecured',
            'repayable_on_demand' => 1,
            'repayment_timing' => 'within_12_months',
            'deferment_right_confirmed' => 0,
            'set_off_right_confirmed' => 0,
            'settlement_intention' => 'independently',
            'revision' => 0,
        ];
    }

    private function normalise(array $values): ?array
    {
        $rate = (float)($values['interest_rate_percent'] ?? 0);
        $security = (string)($values['security_type'] ?? 'unsecured');
        $timing = (string)($values['repayment_timing'] ?? 'within_12_months');
        $settlement = (string)($values['settlement_intention'] ?? 'independently');
        if ($rate < 0 || $rate > 100
            || !in_array($security, ['secured', 'unsecured'], true)
            || !in_array($timing, ['within_12_months', 'after_12_months'], true)
            || !in_array($settlement, ['net', 'simultaneous', 'independently'], true)) {
            return null;
        }
        return [
            'interest_rate_percent' => round($rate, 4),
            'security_type' => $security,
            'repayable_on_demand' => !empty($values['repayable_on_demand']) ? 1 : 0,
            'repayment_timing' => $timing,
            'deferment_right_confirmed' => !empty($values['deferment_right_confirmed']) ? 1 : 0,
            'set_off_right_confirmed' => !empty($values['set_off_right_confirmed']) ? 1 : 0,
            'settlement_intention' => $settlement,
        ];
    }

    /**
     * Resolve new writes to one of the three representable repayment states.
     *
     * Stored live terms and immutable snapshots deliberately continue through
     * normalise() alone so historical contradictory evidence remains readable.
     */
    private function repaymentValuesForSave(array $values): ?array
    {
        $mappings = [
            'on_demand' => [
                'repayable_on_demand' => 1,
                'repayment_timing' => 'within_12_months',
                'deferment_right_confirmed' => 0,
            ],
            'within_12_months' => [
                'repayable_on_demand' => 0,
                'repayment_timing' => 'within_12_months',
                'deferment_right_confirmed' => 0,
            ],
            'after_12_months' => [
                'repayable_on_demand' => 0,
                'repayment_timing' => 'after_12_months',
                'deferment_right_confirmed' => 1,
            ],
        ];

        if (array_key_exists('repayment_basis', $values)) {
            $basis = trim((string)$values['repayment_basis']);
            $mapped = $mappings[$basis] ?? null;
            if ($mapped === null) {
                return null;
            }
            foreach ($mapped as $key => $expected) {
                if (!array_key_exists($key, $values)) {
                    continue;
                }
                $actual = $key === 'repayment_timing'
                    ? trim((string)$values[$key])
                    : $this->normaliseFlag($values[$key]);
                if ($actual !== $expected) {
                    return null;
                }
            }
            return $mapped;
        }

        foreach (['repayable_on_demand', 'repayment_timing', 'deferment_right_confirmed'] as $key) {
            if (!array_key_exists($key, $values)) {
                return null;
            }
        }
        $onDemand = $this->normaliseFlag($values['repayable_on_demand']);
        $deferment = $this->normaliseFlag($values['deferment_right_confirmed']);
        $timing = trim((string)$values['repayment_timing']);
        if ($onDemand === null || $deferment === null) {
            return null;
        }

        foreach ($mappings as $mapped) {
            if ($mapped['repayable_on_demand'] === $onDemand
                && $mapped['repayment_timing'] === $timing
                && $mapped['deferment_right_confirmed'] === $deferment) {
                return $mapped;
            }
        }
        return null;
    }

    private function normaliseFlag(mixed $value): ?int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value) || is_float($value)) {
            return in_array($value, [0, 0.0, 1, 1.0], true) ? (int)$value : null;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on' => 1,
                '0', 'false', 'no', 'off' => 0,
                default => null,
            };
        }
        return null;
    }

    private function withoutMeta(array $values): array
    {
        return $this->normalise($values) ?? array_diff_key($this->defaults(), ['revision' => true]);
    }

    private function ready(): bool
    {
        return \InterfaceDB::tableExists(self::TERMS)
            && \InterfaceDB::tableExists(self::AUDIT)
            && \InterfaceDB::tableExists(self::SNAPSHOTS);
    }

    private function error(string $message): array
    {
        return ['success' => false, 'errors' => [$message]];
    }
}
