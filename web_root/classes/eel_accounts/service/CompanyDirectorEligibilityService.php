<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CompanyDirectorEligibilityService
{
    public function __construct(
        private readonly ?\eel_accounts\Service\CompaniesHouseService $companiesHouseService = null,
    ) {
    }

    public function assertSingleActiveDirector(int $companyId): array
    {
        if ($companyId <= 0) {
            return [
                'success' => false,
                'director_count' => null,
                'errors' => ['Select a company before checking directors.'],
            ];
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT company_number, companies_house_environment FROM companies WHERE id = :id LIMIT 1',
            ['id' => $companyId]
        );

        if (!is_array($row)) {
            return [
                'success' => false,
                'director_count' => null,
                'errors' => ['The selected company could not be found before checking directors.'],
            ];
        }

        $companyNumber = trim((string)($row['company_number'] ?? ''));
        $environment = trim((string)($row['companies_house_environment'] ?? ''));

        $result = $this->assertSingleActiveDirectorByNumber($companyNumber, $environment);
        $this->persistDirectorCheck($companyId, $result);
        if (!empty($result['success'])) {
            (new CompanyDirectorService())->syncFromCompaniesHouseResult($companyId, $result);
        }

        return $result;
    }

    public function assertSingleActiveDirectorByNumber(string $companyNumber, string $environment = ''): array
    {
        $companyNumber = trim($companyNumber);
        if ($companyNumber === '') {
            return [
                'success' => false,
                'director_count' => null,
                'errors' => ['A Companies House company number is required before checking directors.'],
            ];
        }

        return $this->companiesHouseService($environment)->fetchActiveDirectorCountByNumber($companyNumber);
    }

    private function companiesHouseService(string $environment): \eel_accounts\Service\CompaniesHouseService
    {
        if ($this->companiesHouseService instanceof \eel_accounts\Service\CompaniesHouseService) {
            return $this->companiesHouseService;
        }

        $environment = trim($environment) !== ''
            ? $environment
            : \eel_accounts\Store\AccountingConfigurationStore::companiesHouseMode();

        return new \eel_accounts\Service\CompaniesHouseService($environment);
    }

    private function persistDirectorCheck(int $companyId, array $result): void
    {
        if ($companyId <= 0 || !array_key_exists('director_count', $result) || $result['director_count'] === null) {
            return;
        }

        if (!\InterfaceDB::columnExists('companies', 'companies_house_active_director_count')) {
            return;
        }

        \InterfaceDB::prepareExecute(
            'UPDATE companies
             SET companies_house_active_director_count = :director_count,
                 companies_house_officers_json = :officers_json,
                 companies_house_officers_last_checked_at = CURRENT_TIMESTAMP
             WHERE id = :company_id',
            [
                'director_count' => (int)$result['director_count'],
                'officers_json' => $result['officers_json'] ?? null,
                'company_id' => $companyId,
            ]
        );
    }
}
