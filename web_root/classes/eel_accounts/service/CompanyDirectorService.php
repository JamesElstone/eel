<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class CompanyDirectorService
{
    public function syncFromCompaniesHouseResult(int $companyId, array $result): array
    {
        $json = trim((string)($result['officers_json'] ?? ''));
        if ($companyId <= 0 || $json === '') {
            return [
                'success' => false,
                'synced_count' => 0,
                'active_count' => 0,
                'errors' => ['Companies House did not return director records to synchronise.'],
            ];
        }

        return $this->syncFromStoredOfficersJson($companyId, $json);
    }

    public function syncFromStoredOfficersJson(int $companyId, string $json): array
    {
        if ($companyId <= 0) {
            return ['success' => false, 'synced_count' => 0, 'active_count' => 0, 'errors' => ['Select a company first.']];
        }
        if (!$this->hasSchema()) {
            return ['success' => false, 'synced_count' => 0, 'active_count' => 0, 'errors' => ['The structured directors schema is not installed.']];
        }

        $directors = $this->previewFromStoredOfficersJson($json);
        if ($directors === null) {
            return ['success' => false, 'synced_count' => 0, 'active_count' => 0, 'errors' => ['Stored Companies House officers data is invalid.']];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $upsertSql = 'INSERT INTO company_directors (
                    company_id, source, external_key, full_name, officer_role,
                    appointed_on, resigned_on, is_active, source_json,
                    last_synced_at, created_at, updated_at
                 ) VALUES (
                    :company_id, :source, :external_key, :full_name, :officer_role,
                    :appointed_on, :resigned_on, :is_active, :source_json,
                    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 )';
            $upsertSql .= \InterfaceDB::driverName() === 'sqlite'
                ? ' ON CONFLICT(company_id, source, external_key) DO UPDATE SET
                    full_name = excluded.full_name,
                    officer_role = excluded.officer_role,
                    appointed_on = excluded.appointed_on,
                    resigned_on = excluded.resigned_on,
                    is_active = excluded.is_active,
                    source_json = excluded.source_json,
                    last_synced_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP'
                : ' ON DUPLICATE KEY UPDATE
                    full_name = VALUES(full_name),
                    officer_role = VALUES(officer_role),
                    appointed_on = VALUES(appointed_on),
                    resigned_on = VALUES(resigned_on),
                    is_active = VALUES(is_active),
                    source_json = VALUES(source_json),
                    last_synced_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP';
            $upsert = \InterfaceDB::prepare($upsertSql);

            foreach ($directors as $director) {
                $upsert->execute(['company_id' => $companyId] + $director);
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'synced_count' => 0, 'active_count' => 0, 'errors' => [$exception->getMessage()]];
        }

        return [
            'success' => true,
            'synced_count' => count($directors),
            'active_count' => count(array_filter($directors, static fn(array $row): bool => (int)$row['is_active'] === 1)),
            'errors' => [],
        ];
    }

    public function previewFromStoredOfficersJson(string $json): ?array
    {
        $payload = json_decode(trim($json), true);
        if (!is_array($payload) || !is_array($payload['items'] ?? null)) {
            return null;
        }

        $directors = [];
        foreach ($payload['items'] as $officer) {
            if (!is_array($officer) || strtolower(trim((string)($officer['officer_role'] ?? ''))) !== 'director') {
                continue;
            }

            $name = trim((string)($officer['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $directors[] = [
                'source' => 'companies_house',
                'external_key' => $this->externalKey($officer),
                'full_name' => $name,
                'officer_role' => trim((string)($officer['officer_role'] ?? 'director')) ?: 'director',
                'appointed_on' => $this->normaliseDate($officer['appointed_on'] ?? null),
                'resigned_on' => $this->normaliseDate($officer['resigned_on'] ?? null),
                'is_active' => trim((string)($officer['resigned_on'] ?? '')) === '' ? 1 : 0,
                'source_json' => json_encode($officer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        return $directors;
    }

    public function fetchForCompany(int $companyId): array
    {
        if ($companyId <= 0 || !$this->hasSchema()) {
            return [];
        }

        return \InterfaceDB::fetchAll(
            'SELECT id,
                    company_id,
                    source,
                    external_key,
                    full_name,
                    officer_role,
                    appointed_on,
                    resigned_on,
                    is_active,
                    last_synced_at
             FROM company_directors
             WHERE company_id = :company_id
             ORDER BY is_active DESC, COALESCE(appointed_on, \'9999-12-31\') ASC, full_name ASC, id ASC',
            ['company_id' => $companyId]
        );
    }

    public function fetchSelectableForCompany(int $companyId): array
    {
        return $this->fetchForCompany($companyId);
    }

    public function fetchForCompanyAndId(int $companyId, int $directorId): ?array
    {
        if ($companyId <= 0 || $directorId <= 0 || !$this->hasSchema()) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, full_name, officer_role, appointed_on, resigned_on, is_active, last_synced_at
             FROM company_directors
             WHERE company_id = :company_id AND id = :director_id
             LIMIT 1',
            ['company_id' => $companyId, 'director_id' => $directorId]
        );

        return is_array($row) ? $row : null;
    }

    public function requireForCompany(int $companyId, int $directorId): array
    {
        $director = $this->fetchForCompanyAndId($companyId, $directorId);
        if ($director === null) {
            throw new \RuntimeException('Select a director loan account belonging to this company.');
        }

        return $director;
    }

    public function findUniqueForDate(int $companyId, ?string $date): ?array
    {
        $date = $this->normaliseDate($date);
        $matches = array_values(array_filter(
            $this->fetchForCompany($companyId),
            static function (array $director) use ($date): bool {
                if ($date === null) {
                    return false;
                }
                $appointed = trim((string)($director['appointed_on'] ?? ''));
                $resigned = trim((string)($director['resigned_on'] ?? ''));

                return ($appointed === '' || $appointed <= $date)
                    && ($resigned === '' || $resigned >= $date);
            }
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function externalKey(array $officer): string
    {
        $appointmentsLink = trim((string)($officer['links']['officer']['appointments'] ?? ''));
        if (preg_match('~/officers/([^/]+)/appointments~', $appointmentsLink, $matches) === 1) {
            return 'officer:' . (string)$matches[1];
        }

        $identity = [
            strtolower(trim((string)($officer['name'] ?? ''))),
            strtolower(trim((string)($officer['officer_role'] ?? ''))),
            trim((string)($officer['appointed_on'] ?? '')),
            trim((string)($officer['resigned_on'] ?? '')),
            trim((string)($officer['date_of_birth']['month'] ?? '')),
            trim((string)($officer['date_of_birth']['year'] ?? '')),
        ];

        return 'derived:' . hash('sha256', implode('|', $identity));
    }

    private function normaliseDate(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function hasSchema(): bool
    {
        try {
            return \InterfaceDB::tableExists('company_directors');
        } catch (\Throwable) {
            return false;
        }
    }
}
