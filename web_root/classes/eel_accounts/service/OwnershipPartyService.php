<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class OwnershipPartyService
{
    private const PARTY_TYPES = ['individual', 'company', 'trust', 'partnership', 'other'];
    private const ROLE_TYPES = ['shareholder', 'participator', 'associate'];

    public function fetchSummary(int $companyId, ?string $asOf = null): array
    {
        if ($companyId <= 0) {
            return ['available' => false, 'errors' => ['Select a company before reviewing ownership.']];
        }
        if (!$this->schemaAvailable()) {
            return ['available' => false, 'errors' => ['Run the ownership and CT-period controls migration.']];
        }

        $asOf = $this->normaliseDate($asOf) ?? (new \DateTimeImmutable('today'))->format('Y-m-d');
        $parties = \InterfaceDB::fetchAll(
            'SELECT p.id, p.company_id, p.party_type, p.legal_name, p.linked_director_id,
                    p.source_note, d.full_name AS linked_director_name
             FROM company_parties p
             LEFT JOIN company_directors d ON d.id = p.linked_director_id
             WHERE p.company_id = :company_id
             ORDER BY p.legal_name, p.id',
            ['company_id' => $companyId]
        );
        $roles = \InterfaceDB::fetchAll(
            'SELECT id, party_id, role_type, effective_from, effective_to, source_note
             FROM company_party_roles
             WHERE company_id = :company_id
             ORDER BY effective_from, id',
            ['company_id' => $companyId]
        );
        $holdings = \InterfaceDB::fetchAll(
            'SELECT h.id, h.party_id, h.share_class_id, h.quantity, h.effective_from, h.effective_to,
                    h.source_note, sc.share_class, sc.currency
             FROM company_shareholdings h
             INNER JOIN company_incorporation_share_classes sc ON sc.id = h.share_class_id
             WHERE h.company_id = :company_id
             ORDER BY h.effective_from, h.id',
            ['company_id' => $companyId]
        );
        $rolesByParty = [];
        foreach ($roles as $role) {
            $rolesByParty[(int)$role['party_id']][] = $role;
        }
        $holdingsByParty = [];
        foreach ($holdings as $holding) {
            $holdingsByParty[(int)$holding['party_id']][] = $holding;
        }
        foreach ($parties as &$party) {
            $party['roles'] = $rolesByParty[(int)$party['id']] ?? [];
            $party['holdings'] = $holdingsByParty[(int)$party['id']] ?? [];
            $party['effective_roles'] = array_values(array_filter(
                $party['roles'],
                fn(array $role): bool => $this->effectiveOn($role, $asOf)
            ));
            $party['effective_holdings'] = array_values(array_filter(
                $party['holdings'],
                fn(array $holding): bool => $this->effectiveOn($holding, $asOf)
            ));
        }
        unset($party);

        return [
            'available' => true,
            'errors' => [],
            'as_of' => $asOf,
            'parties' => $parties,
            'share_classes' => $this->shareClasses($companyId),
            'directors' => (new CompanyDirectorService())->fetchForCompany($companyId),
            'reconciliation' => $this->reconciliation($companyId, $asOf),
        ];
    }

    public function effectiveParties(int $companyId, string $date): array
    {
        $date = $this->normaliseDate($date);
        if ($companyId <= 0 || $date === null || !$this->schemaAvailable()) {
            return [];
        }

        return \InterfaceDB::fetchAll(
            "SELECT DISTINCT p.id, p.legal_name, p.party_type, p.linked_director_id
             FROM company_parties p
             INNER JOIN company_party_roles r ON r.party_id = p.id AND r.company_id = p.company_id
             WHERE p.company_id = :company_id
               AND r.role_type IN ('shareholder','participator','associate')
               AND r.effective_from <= :as_of
               AND (r.effective_to IS NULL OR r.effective_to >= :as_of)
             ORDER BY p.legal_name, p.id",
            ['company_id' => $companyId, 'as_of' => $date]
        );
    }

    public function requireEffectiveParty(int $companyId, int $partyId, string $date): array
    {
        foreach ($this->effectiveParties($companyId, $date) as $party) {
            if ((int)$party['id'] === $partyId) {
                return $party;
            }
        }
        throw new \RuntimeException('Select a shareholder, participator, or associate effective on the transaction date.');
    }

    public function saveParty(array $input): array
    {
        $companyId = (int)($input['company_id'] ?? 0);
        $partyId = (int)($input['party_id'] ?? 0);
        $legalName = trim((string)($input['legal_name'] ?? ''));
        $partyType = strtolower(trim((string)($input['party_type'] ?? 'individual')));
        $linkedDirectorId = (int)($input['linked_director_id'] ?? 0);
        if ($companyId <= 0 || $legalName === '') {
            return ['success' => false, 'errors' => ['Enter a legal name for the ownership party.']];
        }
        if (!in_array($partyType, self::PARTY_TYPES, true)) {
            return ['success' => false, 'errors' => ['Select a valid party type.']];
        }
        if ($linkedDirectorId > 0) {
            (new CompanyDirectorService())->requireForCompany($companyId, $linkedDirectorId);

            if ($partyId <= 0) {
                $existingPartyId = (int)
                    \InterfaceDB::fetchColumn(
                        'SELECT id
                         FROM company_parties
                         WHERE company_id = :company_id
                           AND linked_director_id = :linked_director_id
                         LIMIT 1',
                        [
                            'company_id' => $companyId,
                            'linked_director_id' => $linkedDirectorId,
                        ]
                    );

                if ($existingPartyId > 0) {
                    $partyId = $existingPartyId;
                }
            }
        }
        if ($partyId > 0) {
            $this->requireParty($companyId, $partyId);
            $this->assertPartyMutationUnlocked($companyId, $partyId);
            \InterfaceDB::prepareExecute(
                'UPDATE company_parties
                 SET party_type = :party_type, legal_name = :legal_name,
                     linked_director_id = :linked_director_id, source_note = :source_note
                 WHERE id = :id AND company_id = :company_id',
                [
                    'party_type' => $partyType,
                    'legal_name' => $legalName,
                    'linked_director_id' => $linkedDirectorId > 0 ? $linkedDirectorId : null,
                    'source_note' => $this->nullableText($input['source_note'] ?? null),
                    'id' => $partyId,
                    'company_id' => $companyId,
                ]
            );
        } else {
            \InterfaceDB::prepareExecute(
                'INSERT INTO company_parties (company_id, party_type, legal_name, linked_director_id, source_note)
                 VALUES (:company_id, :party_type, :legal_name, :linked_director_id, :source_note)',
                [
                    'company_id' => $companyId,
                    'party_type' => $partyType,
                    'legal_name' => $legalName,
                    'linked_director_id' => $linkedDirectorId > 0 ? $linkedDirectorId : null,
                    'source_note' => $this->nullableText($input['source_note'] ?? null),
                ]
            );
            $partyId = (int)\InterfaceDB::lastInsertId();
        }

        return ['success' => true, 'errors' => [], 'party_id' => $partyId];
    }

    public function saveRole(array $input): array
    {
        $companyId = (int)($input['company_id'] ?? 0);
        $partyId = (int)($input['party_id'] ?? 0);
        $roleType = strtolower(trim((string)($input['role_type'] ?? '')));
        $from = $this->normaliseDate($input['effective_from'] ?? null);
        $to = $this->normaliseDate($input['effective_to'] ?? null);
        $this->requireParty($companyId, $partyId);
        if (!in_array($roleType, self::ROLE_TYPES, true) || $from === null || ($to !== null && $to < $from)) {
            return ['success' => false, 'errors' => ['Enter a valid role and effective date range.']];
        }
        $this->assertRangeUnlocked($companyId, $from, $to);
        \InterfaceDB::prepareExecute(
            'INSERT INTO company_party_roles (company_id, party_id, role_type, effective_from, effective_to, source_note)
             VALUES (:company_id, :party_id, :role_type, :effective_from, :effective_to, :source_note)',
            [
                'company_id' => $companyId,
                'party_id' => $partyId,
                'role_type' => $roleType,
                'effective_from' => $from,
                'effective_to' => $to,
                'source_note' => $this->nullableText($input['source_note'] ?? null),
            ]
        );
        return ['success' => true, 'errors' => []];
    }

    public function saveHolding(array $input): array
    {
        $companyId = (int)($input['company_id'] ?? 0);
        $partyId = (int)($input['party_id'] ?? 0);
        $shareClassId = (int)($input['share_class_id'] ?? 0);
        $quantity = (int)($input['quantity'] ?? 0);
        $from = $this->normaliseDate($input['effective_from'] ?? null);
        $to = $this->normaliseDate($input['effective_to'] ?? null);
        $this->requireParty($companyId, $partyId);
        if ($quantity <= 0 || $from === null || ($to !== null && $to < $from) || !$this->shareClassBelongsToCompany($companyId, $shareClassId)) {
            return ['success' => false, 'errors' => ['Enter a valid share class, positive quantity, and effective date range.']];
        }
        $this->assertRangeUnlocked($companyId, $from, $to);
        \InterfaceDB::prepareExecute(
            'INSERT INTO company_shareholdings (
                company_id, party_id, share_class_id, quantity, effective_from, effective_to, source_note
             ) VALUES (
                :company_id, :party_id, :share_class_id, :quantity, :effective_from, :effective_to, :source_note
             )',
            [
                'company_id' => $companyId,
                'party_id' => $partyId,
                'share_class_id' => $shareClassId,
                'quantity' => $quantity,
                'effective_from' => $from,
                'effective_to' => $to,
                'source_note' => $this->nullableText($input['source_note'] ?? null),
            ]
        );
        return ['success' => true, 'errors' => []];
    }

    /**
     * Records initial director shareholdings, creating the linked ownership party
     * and shareholder role where they do not already exist.
     */
    public function saveDirectorShareholdings(int $companyId, int $shareClassId, array $shareholdings): array
    {
        if ($companyId <= 0 || !$this->schemaAvailable() || !$this->shareClassBelongsToCompany($companyId, $shareClassId)) {
            return ['success' => false, 'errors' => ['Select a company and a valid share class.']];
        }

        $entries = [];
        foreach ($shareholdings as $directorId => $shareholding) {
            $quantity = (int)($shareholding['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }
            $issuedOn = $this->normaliseDate($shareholding['issued_on'] ?? null);
            if ($issuedOn === null) {
                return ['success' => false, 'errors' => ['Enter the issue date for every director with shares.']];
            }
            $entries[] = ['director_id' => (int)$directorId, 'quantity' => $quantity, 'issued_on' => $issuedOn];
        }
        if ($entries === []) {
            return ['success' => false, 'errors' => ['Enter shares for at least one director.']];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }
        try {
            foreach ($entries as $entry) {
                $director = (new CompanyDirectorService())->requireForCompany($companyId, $entry['director_id']);
                $party = \InterfaceDB::fetchOne(
                    'SELECT id FROM company_parties WHERE company_id = :company_id AND linked_director_id = :director_id ORDER BY id LIMIT 1',
                    ['company_id' => $companyId, 'director_id' => $entry['director_id']]
                );
                $partyId = (int)($party['id'] ?? 0);
                if ($partyId <= 0) {
                    $partyResult = $this->saveParty([
                        'company_id' => $companyId,
                        'legal_name' => (string)$director['full_name'],
                        'party_type' => 'individual',
                        'linked_director_id' => $entry['director_id'],
                        'source_note' => 'Created when recording a director shareholding.',
                    ]);
                    if (empty($partyResult['success'])) {
                        throw new \RuntimeException((string)(($partyResult['errors'] ?? ['Unable to save ownership party.'])[0]));
                    }
                    $partyId = (int)$partyResult['party_id'];
                }

                $hasShareholderRole = (int)\InterfaceDB::fetchColumn(
                    "SELECT COUNT(*) FROM company_party_roles WHERE company_id = :company_id AND party_id = :party_id AND role_type = 'shareholder' AND effective_from <= :issued_on AND (effective_to IS NULL OR effective_to >= :issued_on)",
                    ['company_id' => $companyId, 'party_id' => $partyId, 'issued_on' => $entry['issued_on']]
                ) > 0;
                if (!$hasShareholderRole) {
                    $roleResult = $this->saveRole([
                        'company_id' => $companyId,
                        'party_id' => $partyId,
                        'role_type' => 'shareholder',
                        'effective_from' => $entry['issued_on'],
                    ]);
                    if (empty($roleResult['success'])) {
                        throw new \RuntimeException((string)(($roleResult['errors'] ?? ['Unable to save shareholder role.'])[0]));
                    }
                }

                $holdingResult = $this->saveHolding([
                    'company_id' => $companyId,
                    'party_id' => $partyId,
                    'share_class_id' => $shareClassId,
                    'quantity' => $entry['quantity'],
                    'effective_from' => $entry['issued_on'],
                    'source_note' => 'Recorded from the directors’ shareholdings form.',
                ]);
                if (empty($holdingResult['success'])) {
                    throw new \RuntimeException((string)(($holdingResult['errors'] ?? ['Unable to save shareholding.'])[0]));
                }
            }
            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        return ['success' => true, 'errors' => []];
    }

    public function endRole(int $companyId, int $roleId, string $effectiveTo): array
    {
        $effectiveTo = $this->normaliseDate($effectiveTo) ?? '';
        $role = \InterfaceDB::fetchOne(
            'SELECT id, effective_from FROM company_party_roles
             WHERE id = :id AND company_id = :company_id AND effective_to IS NULL',
            ['id' => $roleId, 'company_id' => $companyId]
        );
        if (!is_array($role) || $effectiveTo === '' || $effectiveTo < (string)$role['effective_from']) {
            return ['success' => false, 'errors' => ['Select a valid current role and end date.']];
        }
        $this->assertRangeUnlocked(
            $companyId,
            (new \DateTimeImmutable($effectiveTo))->modify('+1 day')->format('Y-m-d'),
            null
        );
        \InterfaceDB::prepareExecute(
            'UPDATE company_party_roles SET effective_to = :effective_to WHERE id = :id AND company_id = :company_id',
            ['effective_to' => $effectiveTo, 'id' => $roleId, 'company_id' => $companyId]
        );
        return ['success' => true, 'errors' => []];
    }

    public function endHolding(int $companyId, int $holdingId, string $effectiveTo): array
    {
        $effectiveTo = $this->normaliseDate($effectiveTo) ?? '';
        $holding = \InterfaceDB::fetchOne(
            'SELECT id, effective_from FROM company_shareholdings
             WHERE id = :id AND company_id = :company_id AND effective_to IS NULL',
            ['id' => $holdingId, 'company_id' => $companyId]
        );
        if (!is_array($holding) || $effectiveTo === '' || $effectiveTo < (string)$holding['effective_from']) {
            return ['success' => false, 'errors' => ['Select a valid holding and end date.']];
        }
        $this->assertRangeUnlocked(
            $companyId,
            (new \DateTimeImmutable($effectiveTo))->modify('+1 day')->format('Y-m-d'),
            null
        );
        \InterfaceDB::prepareExecute(
            'UPDATE company_shareholdings SET effective_to = :effective_to WHERE id = :id AND company_id = :company_id',
            ['effective_to' => $effectiveTo, 'id' => $holdingId, 'company_id' => $companyId]
        );
        return ['success' => true, 'errors' => []];
    }

    public function reconciliation(int $companyId, string $asOf): array
    {
        $asOf = $this->normaliseDate($asOf) ?? '';
        if ($companyId <= 0 || $asOf === '' || !$this->schemaAvailable()) {
            return ['pass' => false, 'rows' => [], 'difference' => 0];
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT sc.id AS share_class_id, sc.share_class, sc.quantity AS issued_quantity,
                    COALESCE(SUM(CASE
                      WHEN h.effective_from <= :as_of
                       AND (h.effective_to IS NULL OR h.effective_to >= :as_of)
                      THEN h.quantity ELSE 0 END), 0) AS held_quantity
             FROM company_incorporation_share_classes sc
             LEFT JOIN company_shareholdings h ON h.share_class_id = sc.id AND h.company_id = sc.company_id
             WHERE sc.company_id = :company_id
             GROUP BY sc.id, sc.share_class, sc.quantity
             ORDER BY sc.share_class, sc.id',
            ['company_id' => $companyId, 'as_of' => $asOf]
        );
        $pass = $rows !== [];
        $difference = 0;
        foreach ($rows as &$row) {
            $row['difference'] = (int)$row['issued_quantity'] - (int)$row['held_quantity'];
            $row['status'] = (int)$row['difference'] === 0 ? 'reconciled' : 'mismatch';
            $difference += abs((int)$row['difference']);
            $pass = $pass && (int)$row['difference'] === 0;
        }
        unset($row);
        return ['pass' => $pass, 'rows' => $rows, 'difference' => $difference, 'as_of' => $asOf];
    }

    public function readinessForAccountingPeriod(int $companyId, int $accountingPeriodId): array
    {
        if (!$this->schemaAvailable()) {
            return ['available' => false, 'pass' => false, 'errors' => ['Run the ownership and CT-period controls migration.']];
        }
        $period = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if (!is_array($period)) {
            return ['available' => false, 'pass' => false, 'errors' => ['The accounting period could not be found.']];
        }
        $reconciliation = $this->reconciliation($companyId, (string)$period['period_end']);
        $unownedHoldings = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM company_shareholdings h
             WHERE h.company_id = :company_id
               AND h.effective_from <= :as_of
               AND (h.effective_to IS NULL OR h.effective_to >= :as_of)
               AND NOT EXISTS (
                   SELECT 1 FROM company_party_roles r
                   WHERE r.company_id = h.company_id
                     AND r.party_id = h.party_id
                     AND r.role_type = \'shareholder\'
                     AND r.effective_from <= :as_of
                     AND (r.effective_to IS NULL OR r.effective_to >= :as_of)
               )',
            ['company_id' => $companyId, 'as_of' => (string)$period['period_end']]
        );
        $errors = $unownedHoldings > 0
            ? [$unownedHoldings . ' effective shareholding record(s) do not have an effective shareholder role at period end.']
            : [];
        return [
            'available' => true,
            'pass' => !empty($reconciliation['pass']) && $unownedHoldings === 0,
            'errors' => $errors,
            'reconciliation' => $reconciliation,
        ];
    }

    private function requireParty(int $companyId, int $partyId): array
    {
        $party = \InterfaceDB::fetchOne(
            'SELECT id, company_id, legal_name FROM company_parties WHERE id = :id AND company_id = :company_id',
            ['id' => $partyId, 'company_id' => $companyId]
        );
        if (!is_array($party)) {
            throw new \RuntimeException('Select an ownership party belonging to this company.');
        }
        return $party;
    }

    private function assertRangeUnlocked(int $companyId, string $from, ?string $to): void
    {
        $locked = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM accounting_periods ap
             INNER JOIN year_end_reviews yer
               ON yer.company_id = ap.company_id AND yer.accounting_period_id = ap.id AND yer.is_locked = 1
             WHERE ap.company_id = :company_id
               AND ap.period_end >= :effective_from
               AND (:effective_to IS NULL OR ap.period_start <= :effective_to)',
            ['company_id' => $companyId, 'effective_from' => $from, 'effective_to' => $to]
        );
        if ($locked > 0) {
            throw new \RuntimeException('Unlock every affected accounting period before changing historical ownership or participator roles.');
        }
    }

    private function assertPartyMutationUnlocked(int $companyId, int $partyId): void
    {
        $locked = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM accounting_periods ap
             INNER JOIN year_end_reviews yer
               ON yer.company_id = ap.company_id AND yer.accounting_period_id = ap.id AND yer.is_locked = 1
             WHERE ap.company_id = :company_id
               AND (EXISTS (
                    SELECT 1 FROM company_party_roles r
                    WHERE r.company_id = ap.company_id AND r.party_id = :party_id
                      AND r.effective_from <= ap.period_end
                      AND (r.effective_to IS NULL OR r.effective_to >= ap.period_start)
               ) OR EXISTS (
                    SELECT 1 FROM company_shareholdings h
                    WHERE h.company_id = ap.company_id AND h.party_id = :party_id
                      AND h.effective_from <= ap.period_end
                      AND (h.effective_to IS NULL OR h.effective_to >= ap.period_start)
               ))',
            ['company_id' => $companyId, 'party_id' => $partyId]
        );
        if ($locked > 0) {
            throw new \RuntimeException('Unlock every affected accounting period before changing a historical ownership party or director link.');
        }
    }

    private function shareClasses(int $companyId): array
    {
        return \InterfaceDB::fetchAll(
            'SELECT id, share_class, currency, quantity FROM company_incorporation_share_classes WHERE company_id = :company_id ORDER BY share_class, id',
            ['company_id' => $companyId]
        );
    }

    private function shareClassBelongsToCompany(int $companyId, int $shareClassId): bool
    {
        return $shareClassId > 0 && (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM company_incorporation_share_classes WHERE id = :id AND company_id = :company_id',
            ['id' => $shareClassId, 'company_id' => $companyId]
        ) === 1;
    }

    private function effectiveOn(array $row, string $date): bool
    {
        return (string)($row['effective_from'] ?? '') <= $date
            && (trim((string)($row['effective_to'] ?? '')) === '' || (string)$row['effective_to'] >= $date);
    }

    private function normaliseDate(mixed $value): ?string
    {
        $value = trim((string)$value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    private function schemaAvailable(): bool
    {
        return \InterfaceDB::tableExists('company_parties')
            && \InterfaceDB::tableExists('company_party_roles')
            && \InterfaceDB::tableExists('company_shareholdings');
    }
}
