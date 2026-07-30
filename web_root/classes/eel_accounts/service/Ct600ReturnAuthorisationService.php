<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class Ct600ReturnAuthorisationService
{
    public const STATUSES = [
        'Director',
        'Company Secretary',
        'Authorised Agent',
        'Authorised Employee',
        'Tax Agent or Accountant',
        'Liquidator',
    ];

    public function fetch(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0
            || !\InterfaceDB::tableExists('ct600_return_authorisations')) {
            return [];
        }

        $row = (array)(\InterfaceDB::fetchOne(
            'SELECT *
             FROM ct600_return_authorisations
             WHERE company_id = :company_id AND accounting_period_id = :period_id
             LIMIT 1',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        ) ?: []);
        if ($row !== []) {
            $row['saved_by_display_name'] = $this->savedByDisplayName((string)($row['saved_by'] ?? ''));
        }
        return $row;
    }

    /**
     * @return list<array{
     *   reference:string,name:string,status:string,source_type:string,
     *   director_id:?int,party_id:?int,role_id:?int
     * }>
     */
    public function eligibleAuthorisers(int $companyId, string $date): array
    {
        $date = $this->normaliseDate($date);
        if ($companyId <= 0 || $date === null) {
            return [];
        }

        $authorisers = [];
        foreach ((new CompanyDirectorService())->fetchForCompany($companyId) as $director) {
            $directorId = (int)($director['id'] ?? 0);
            $name = trim((string)($director['full_name'] ?? ''));
            $appointedOn = trim((string)($director['appointed_on'] ?? ''));
            $resignedOn = trim((string)($director['resigned_on'] ?? ''));
            if ($directorId <= 0 || $name === ''
                || strtolower(trim((string)($director['officer_role'] ?? ''))) !== 'director'
                || ($appointedOn !== '' && $appointedOn > $date)
                || ($resignedOn !== '' && $resignedOn < $date)) {
                continue;
            }
            $authorisers[] = [
                'reference' => 'director:' . $directorId,
                'name' => $name,
                'status' => 'Director',
                'source_type' => 'director',
                'director_id' => $directorId,
                'party_id' => null,
                'role_id' => null,
            ];
        }

        foreach ((new OwnershipPartyService())->effectiveAuthorisationRoles($companyId, $date) as $role) {
            $authorisers[] = [
                'reference' => 'party-role:' . (int)$role['id'],
                'name' => (string)$role['legal_name'],
                'status' => (string)$role['role_label'],
                'source_type' => 'party_role',
                'director_id' => null,
                'party_id' => (int)$role['party_id'],
                'role_id' => (int)$role['id'],
            ];
        }

        usort($authorisers, static fn(array $left, array $right): int =>
            [(string)$left['name'], (string)$left['status'], (string)$left['reference']]
            <=> [(string)$right['name'], (string)$right['status'], (string)$right['reference']]
        );
        return $authorisers;
    }

    public function save(int $companyId, int $accountingPeriodId, array $input, string $actor): array
    {
        if (!$this->structuredSchemaAvailable()) {
            return [
                'success' => false,
                'errors' => ['Apply the structured CT600 authorisation migration before saving an authorisation.'],
            ];
        }

        $reference = trim((string)($input['declarant_authority'] ?? ''));
        $confirmed = static fn(string $key): bool =>
            !empty($input[$key]) && in_array((string)$input[$key], ['1', 'on', 'true'], true);
        $errors = [];
        foreach (['original_unfiled_confirmed', 'authority_confirmed', 'declaration_confirmed'] as $key) {
            if (!$confirmed($key)) {
                $errors[] = 'Confirm every Corporation Tax return authorisation statement.';
                break;
            }
        }

        $declaredAt = new \DateTimeImmutable('now');
        $authoriser = $this->resolveEligibleAuthoriser($companyId, $reference, $declaredAt->format('Y-m-d'));
        if ($authoriser === null) {
            $errors[] = 'Select a person with an eligible authority that is effective on the declaration date.';
        } elseif (mb_strlen((string)$authoriser['name']) > 100) {
            $errors[] = 'The selected declarant name is too long to snapshot in the filing approval.';
        }
        if (!$this->accountingPeriodBelongsToCompany($companyId, $accountingPeriodId)) {
            $errors[] = 'Select a company and accounting period before saving the authorisation.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $sql = 'INSERT INTO ct600_return_authorisations (
                company_id, accounting_period_id, declarant_name, declarant_status,
                declarant_party_id, declarant_director_id, declarant_role_id,
                original_unfiled_confirmed, authority_confirmed, declaration_confirmed,
                saved_at, saved_by
             ) VALUES (
                :company_id, :period_id, :declarant_name, :declarant_status,
                :declarant_party_id, :declarant_director_id, :declarant_role_id,
                :original, :authority, :declaration, :saved_at, :actor
             )';
        $sql .= strtolower(\InterfaceDB::driverName()) === 'sqlite'
            ? ' ON CONFLICT(company_id, accounting_period_id) DO UPDATE SET
                declarant_name = excluded.declarant_name,
                declarant_status = excluded.declarant_status,
                declarant_party_id = excluded.declarant_party_id,
                declarant_director_id = excluded.declarant_director_id,
                declarant_role_id = excluded.declarant_role_id,
                original_unfiled_confirmed = excluded.original_unfiled_confirmed,
                authority_confirmed = excluded.authority_confirmed,
                declaration_confirmed = excluded.declaration_confirmed,
                saved_at = excluded.saved_at,
                saved_by = excluded.saved_by'
            : ' ON DUPLICATE KEY UPDATE
                declarant_name = VALUES(declarant_name),
                declarant_status = VALUES(declarant_status),
                declarant_party_id = VALUES(declarant_party_id),
                declarant_director_id = VALUES(declarant_director_id),
                declarant_role_id = VALUES(declarant_role_id),
                original_unfiled_confirmed = VALUES(original_unfiled_confirmed),
                authority_confirmed = VALUES(authority_confirmed),
                declaration_confirmed = VALUES(declaration_confirmed),
                saved_at = VALUES(saved_at),
                saved_by = VALUES(saved_by)';
        \InterfaceDB::prepareExecute(
            $sql,
            [
                'company_id' => $companyId,
                'period_id' => $accountingPeriodId,
                'declarant_name' => (string)$authoriser['name'],
                'declarant_status' => (string)$authoriser['status'],
                'declarant_party_id' => $authoriser['party_id'],
                'declarant_director_id' => $authoriser['director_id'],
                'declarant_role_id' => $authoriser['role_id'],
                'original' => 1,
                'authority' => 1,
                'declaration' => 1,
                'saved_at' => $declaredAt->format('Y-m-d H:i:s'),
                'actor' => trim($actor) !== '' ? trim($actor) : 'web_app',
            ]
        );
        return ['success' => true, 'errors' => [], 'authorisation' => $this->fetch($companyId, $accountingPeriodId)];
    }

    public function current(int $companyId, int $accountingPeriodId): array
    {
        $row = $this->fetch($companyId, $accountingPeriodId);
        return $row !== []
            && !empty($row['original_unfiled_confirmed'])
            && !empty($row['authority_confirmed'])
            && !empty($row['declaration_confirmed'])
            ? $row
            : [];
    }

    public function isStructured(array $authorisation): bool
    {
        return trim((string)($authorisation['declarant_name'] ?? '')) !== ''
            && ((int)($authorisation['declarant_director_id'] ?? 0) > 0
                || (int)($authorisation['declarant_role_id'] ?? 0) > 0);
    }

    private function resolveEligibleAuthoriser(int $companyId, string $reference, string $date): ?array
    {
        if (preg_match('/^(?:director|party-role):[1-9]\d*$/D', $reference) !== 1) {
            return null;
        }
        foreach ($this->eligibleAuthorisers($companyId, $date) as $authoriser) {
            if (hash_equals((string)$authoriser['reference'], $reference)) {
                return $authoriser;
            }
        }
        return null;
    }

    private function structuredSchemaAvailable(): bool
    {
        foreach ([
            'declarant_name',
            'declarant_party_id',
            'declarant_director_id',
            'declarant_role_id',
        ] as $column) {
            if (!\InterfaceDB::columnExists('ct600_return_authorisations', $column)) {
                return false;
            }
        }
        return true;
    }

    private function accountingPeriodBelongsToCompany(int $companyId, int $accountingPeriodId): bool
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0
            || !\InterfaceDB::tableExists('accounting_periods')) {
            return false;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM accounting_periods
             WHERE id = :period_id AND company_id = :company_id',
            ['period_id' => $accountingPeriodId, 'company_id' => $companyId]
        ) === 1;
    }

    private function savedByDisplayName(string $actor): string
    {
        $actor = trim($actor);
        if ($actor === '') {
            return '';
        }
        if (preg_match('/^user:([1-9]\d*)$/D', $actor, $matches) === 1) {
            try {
                $user = (new \UserAuthenticationService())->userById((int)$matches[1]);
                $displayName = trim((string)($user['display_name'] ?? ''));
                return $displayName !== '' ? $displayName : 'Unknown user';
            } catch (\Throwable) {
                return 'Unknown user';
            }
        }
        if (str_starts_with(strtolower($actor), 'user:')) {
            return 'Unknown user';
        }
        return $actor === 'web_app' ? 'Web app' : $actor;
    }

    private function normaliseDate(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : null;
    }
}
