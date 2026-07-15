<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Repository;

final class CompanyRepository
{
    private const NOMINAL_SETTING_KEYS = [
        'default_bank_nominal_id',
        'default_trade_nominal_id',
        'default_expense_nominal_id',
        'tools_small_equipment_nominal_id',
        'prepayment_asset_nominal_id',
        'director_loan_nominal_id',
        'director_loan_asset_nominal_id',
        'director_loan_liability_nominal_id',
        'vat_nominal_id',
        'uncategorised_nominal_id',
        'corporation_tax_expense_nominal_id',
        'corporation_tax_liability_nominal_id',
    ];

    public function fetchCompanies(): array
    {
        return \InterfaceDB::fetchAll($this->fetchCompaniesSql());
    }

    public function fetchCompanySelectorRows(): array
    {
        return \InterfaceDB::fetchAll(
            'SELECT id,
                    company_name,
                    company_number
             FROM companies
             ORDER BY company_name, id'
        );
    }

    public function fetchCompanyDetails(int $companyId): ?array
    {
        if ($companyId <= 0) {
            return null;
        }

        $select = [
            'id',
            'company_name',
            'company_number',
            'incorporation_date',
            'company_status',
            'companies_house_type',
            'companies_house_jurisdiction',
            'registered_office_address_line_1',
            'registered_office_address_line_2',
            'registered_office_locality',
            'registered_office_region',
            'registered_office_postal_code',
            'registered_office_country',
            'registered_office_care_of',
            'registered_office_po_box',
            'registered_office_premises',
            'can_file',
            'has_charges',
            'has_insolvency_history',
            'has_been_liquidated',
            'registered_office_is_in_dispute',
            'undeliverable_registered_office_address',
            'has_super_secure_pscs',
            'companies_house_environment',
            'companies_house_etag',
            'companies_house_last_checked_at',
            'companies_house_profile_json',
            'companies_house_active_director_count',
            'companies_house_officers_last_checked_at',
            'companies_house_officers_json',
            'is_vat_registered',
            'vat_country_code',
            'vat_number',
            'vat_validation_status',
            'vat_validated_at',
            'vat_validation_source',
            'vat_validation_name',
            'vat_validation_address_line1',
            'vat_validation_postcode',
            'vat_validation_country_code',
            'vat_last_error',
        ];
        if (\InterfaceDB::columnExists('companies', 'vat_validation_mode')) {
            $select[] = 'vat_validation_mode';
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT ' . implode(', ', $select) . ' FROM companies WHERE id = :id',
            ['id' => $companyId]
        );

        if (!is_array($row)) {
            return null;
        }

        $sicService = new \eel_accounts\Service\CompaniesHouseSICService();
        $sicCodes = $sicService->extractSicCodesFromProfileJson((string)($row['companies_house_profile_json'] ?? ''));
        $resolvedSicCodes = $sicService->fetchResolvedCodes($sicCodes);
        $row['sic_codes'] = $sicCodes;
        $row['resolved_sic_codes'] = $resolvedSicCodes;
        $row['resolved_sic_code_lines'] = $sicService->formatResolvedCodesForDisplay($resolvedSicCodes);

        return $row;
    }

    public function updateBasicDetails(array $settings): void
    {
        \InterfaceDB::prepareExecute('UPDATE companies SET company_name = ?, company_number = ? WHERE id = ?', [
            $settings['company_name'],
            $settings['companies_house_number'] !== '' ? $settings['companies_house_number'] : null,
            (int)$settings['company_id'],
        ]);
    }

    public function saveCompanySection(array $settings): void
    {
        $isVatRegistered = !empty($settings['is_vat_registered']);
        $vatCountryCode = $isVatRegistered
            ? (trim((string)($settings['vat_country_code'] ?? '')) !== '' ? strtoupper(trim((string)$settings['vat_country_code'])) : null)
            : null;
        $vatNumber = $isVatRegistered
            ? (trim((string)($settings['vat_number'] ?? '')) !== '' ? trim((string)$settings['vat_number']) : null)
            : null;
        $vatValidationStatus = $isVatRegistered ? (trim((string)($settings['vat_validation_status'] ?? '')) !== '' ? trim((string)$settings['vat_validation_status']) : null) : null;
        $vatValidatedAt = $isVatRegistered ? (trim((string)($settings['vat_validated_at'] ?? '')) !== '' ? trim((string)$settings['vat_validated_at']) : null) : null;
        $vatValidationSource = $isVatRegistered ? (trim((string)($settings['vat_validation_source'] ?? '')) !== '' ? trim((string)$settings['vat_validation_source']) : null) : null;
        $vatValidationMode = $isVatRegistered ? (trim((string)($settings['vat_validation_mode'] ?? '')) !== '' ? strtoupper(trim((string)$settings['vat_validation_mode'])) : null) : null;
        $vatValidationName = $isVatRegistered ? (trim((string)($settings['vat_validation_name'] ?? '')) !== '' ? trim((string)$settings['vat_validation_name']) : null) : null;
        $vatValidationAddressLine1 = $isVatRegistered ? (trim((string)($settings['vat_validation_address_line1'] ?? '')) !== '' ? trim((string)$settings['vat_validation_address_line1']) : null) : null;
        $vatValidationPostcode = $isVatRegistered ? (trim((string)($settings['vat_validation_postcode'] ?? '')) !== '' ? trim((string)$settings['vat_validation_postcode']) : null) : null;
        $vatValidationCountryCode = $isVatRegistered ? (trim((string)($settings['vat_validation_country_code'] ?? '')) !== '' ? strtoupper(trim((string)$settings['vat_validation_country_code'])) : null) : null;
        $vatLastError = $isVatRegistered ? (trim((string)($settings['vat_last_error'] ?? '')) !== '' ? trim((string)$settings['vat_last_error']) : null) : null;

        $sql = 'UPDATE companies
             SET company_name = ?,
                 company_number = ?,
                 is_vat_registered = ?,
                 vat_country_code = ?,
                 vat_number = ?,
                 vat_validation_status = ?,
                 vat_validated_at = ?,
                 vat_validation_source = ?';
        $params = [
            $settings['company_name'],
            $settings['companies_house_number'] !== '' ? $settings['companies_house_number'] : null,
            $isVatRegistered ? 1 : 0,
            $vatCountryCode,
            $vatNumber,
            $vatValidationStatus,
            $vatValidatedAt,
            $vatValidationSource,
        ];
        if (\InterfaceDB::columnExists('companies', 'vat_validation_mode')) {
            $sql .= ',
                 vat_validation_mode = ?';
            $params[] = $vatValidationMode;
        }
        $sql .= ',
                 vat_validation_name = ?,
                 vat_validation_address_line1 = ?,
                 vat_validation_postcode = ?,
                 vat_validation_country_code = ?';
        array_push($params, $vatValidationName, $vatValidationAddressLine1, $vatValidationPostcode, $vatValidationCountryCode);

        $sql .= ',
                 vat_last_error = ?
             WHERE id = ?';
        $params[] = $vatLastError;
        $params[] = (int)$settings['company_id'];

        \InterfaceDB::prepareExecute($sql, $params);
    }

    public function normaliseCompaniesHouseProfileForStorage(?array $profile, string $environment = ''): array
    {
        if (!is_array($profile) || $profile === []) {
            return [];
        }

        $address = is_array($profile['registered_office_address'] ?? null) ? $profile['registered_office_address'] : [];
        $profileJson = json_encode($profile, JSON_UNESCAPED_SLASHES);

        if ($profileJson === false) {
            $profileJson = null;
        }

        return [
            'company_status' => trim((string)($profile['company_status'] ?? '')) ?: null,
            'companies_house_type' => trim((string)($profile['type'] ?? '')) ?: null,
            'companies_house_jurisdiction' => trim((string)($profile['jurisdiction'] ?? '')) ?: null,
            'registered_office_address_line_1' => trim((string)($address['address_line_1'] ?? '')) ?: null,
            'registered_office_address_line_2' => trim((string)($address['address_line_2'] ?? '')) ?: null,
            'registered_office_locality' => trim((string)($address['locality'] ?? '')) ?: null,
            'registered_office_region' => trim((string)($address['region'] ?? '')) ?: null,
            'registered_office_postal_code' => trim((string)($address['postal_code'] ?? '')) ?: null,
            'registered_office_country' => trim((string)($address['country'] ?? '')) ?: null,
            'registered_office_care_of' => trim((string)($address['care_of'] ?? '')) ?: null,
            'registered_office_po_box' => trim((string)($address['po_box'] ?? '')) ?: null,
            'registered_office_premises' => trim((string)($address['premises'] ?? ($address['premise'] ?? ''))) ?: null,
            'can_file' => array_key_exists('can_file', $profile) ? ((bool)$profile['can_file'] ? 1 : 0) : null,
            'has_charges' => array_key_exists('has_charges', $profile) ? ((bool)$profile['has_charges'] ? 1 : 0) : null,
            'has_insolvency_history' => array_key_exists('has_insolvency_history', $profile) ? ((bool)$profile['has_insolvency_history'] ? 1 : 0) : null,
            'has_been_liquidated' => array_key_exists('has_been_liquidated', $profile) ? ((bool)$profile['has_been_liquidated'] ? 1 : 0) : null,
            'registered_office_is_in_dispute' => array_key_exists('registered_office_is_in_dispute', $profile) ? ((bool)$profile['registered_office_is_in_dispute'] ? 1 : 0) : null,
            'undeliverable_registered_office_address' => array_key_exists('undeliverable_registered_office_address', $profile) ? ((bool)$profile['undeliverable_registered_office_address'] ? 1 : 0) : null,
            'has_super_secure_pscs' => array_key_exists('has_super_secure_pscs', $profile) ? ((bool)$profile['has_super_secure_pscs'] ? 1 : 0) : null,
            'companies_house_environment' => trim($environment) !== '' ? trim($environment) : null,
            'companies_house_etag' => trim((string)($profile['etag'] ?? '')) ?: null,
            'companies_house_profile_json' => $profileJson,
        ];
    }

    public function findExistingCompanyId(string $companyName, ?string $companyNumber = null): int
    {
        $companyName = trim($companyName);
        $companyNumber = $companyNumber !== null ? trim($companyNumber) : null;
        $companyNumber = $companyNumber !== '' ? $companyNumber : null;

        if ($companyNumber !== null) {
            $companyId = \InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = ? ORDER BY id DESC LIMIT 1',
                [$companyNumber]
            );

            if ($companyId !== false) {
                return (int)$companyId;
            }
        }

        if ($companyName === '') {
            return 0;
        }

        $companyId = \InterfaceDB::fetchColumn(
            'SELECT id
             FROM companies
             WHERE company_name = ?
               AND ((company_number = ?) OR (company_number IS NULL AND ? IS NULL))
             ORDER BY id DESC
             LIMIT 1',
            [$companyName, $companyNumber, $companyNumber]
        );

        return $companyId !== false ? (int)$companyId : 0;
    }

    public function createCompany(
        string $companyName,
        ?string $companyNumber = null,
        ?string $incorporationDate = null,
        ?array $companiesHouseProfile = null,
        string $companiesHouseEnvironment = ''
    ): int {
        $companyName = trim($companyName);
        $companyNumber = $companyNumber !== null ? trim($companyNumber) : null;
        $incorporationDate = $incorporationDate !== null ? trim($incorporationDate) : null;
        $storedProfile = $this->normaliseCompaniesHouseProfileForStorage($companiesHouseProfile, $companiesHouseEnvironment);

        if ($companyName === '') {
            throw new \RuntimeException('Company name is required.');
        }

        $companyNumber = $companyNumber !== '' ? $companyNumber : null;
        $incorporationDate = $incorporationDate !== '' ? $incorporationDate : null;
        $existingCompanyId = $this->findExistingCompanyId($companyName, $companyNumber);

        if ($existingCompanyId > 0) {
            if ($incorporationDate !== null || !empty($storedProfile)) {
                $updateClauses = [];
                $params = [];

                if ($incorporationDate !== null) {
                    $updateClauses[] = 'incorporation_date = ?';
                    $params[] = $incorporationDate;
                }

                if (!empty($storedProfile)) {
                    foreach ($storedProfile as $column => $value) {
                        $updateClauses[] = $column . ' = ?';
                        $params[] = $value;
                    }
                    $updateClauses[] = 'companies_house_last_checked_at = CURRENT_TIMESTAMP';
                }

                if ($updateClauses !== []) {
                    $params[] = $existingCompanyId;
                    \InterfaceDB::prepareExecute('UPDATE companies SET ' . implode(', ', $updateClauses) . ' WHERE id = ?', $params);
                }
            }

            return $existingCompanyId;
        }

        \InterfaceDB::beginTransaction();

        try {
            $columns = ['company_name', 'company_number'];
            $placeholders = ['?', '?'];
            $payload = [$companyName, $companyNumber];

            $columns[] = 'incorporation_date';
            $placeholders[] = '?';
            $payload[] = $incorporationDate;

            if (!empty($storedProfile)) {
                foreach ($storedProfile as $column => $value) {
                    $columns[] = $column;
                    $placeholders[] = '?';
                    $payload[] = $value;
                }
                $columns[] = 'companies_house_last_checked_at';
                $placeholders[] = 'CURRENT_TIMESTAMP';
            }

            \InterfaceDB::prepareExecute(
                'INSERT INTO companies (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
                ,
                $payload
            );

            $companyId = \InterfaceDB::fetchColumn(
                'SELECT id
                 FROM companies
                 WHERE company_name = ?
                   AND ((company_number = ?) OR (company_number IS NULL AND ? IS NULL))
                 ORDER BY id DESC
                 LIMIT 1',
                [$companyName, $companyNumber, $companyNumber]
            );

            if ($companyId === false) {
                throw new \RuntimeException('Company was inserted but could not be reloaded.');
            }

            \InterfaceDB::commit();
        } catch (\Throwable $e) {
            if (\InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            throw $e;
        }

        return (int)$companyId;
    }

    public function deleteCompany(int $companyId): array
    {
        if ($companyId <= 0) {
            throw new \RuntimeException('A valid company must be selected before deletion.');
        }

        \InterfaceDB::beginTransaction();

        try {
            $companyNumber = trim((string)\InterfaceDB::fetchColumn('SELECT company_number FROM companies WHERE id = ?', [$companyId]));
            $autoNominalIds = $this->fetchAutoNominalIdsForCompany($companyId);

            if ($companyNumber !== '') {
                \InterfaceDB::prepareExecute('DELETE FROM companies_house_documents WHERE company_id = ? OR company_number = ?', [$companyId, $companyNumber]);
            } else {
                \InterfaceDB::prepareExecute('DELETE FROM companies_house_documents WHERE company_id = ?', [$companyId]);
            }

            \InterfaceDB::prepareExecute('DELETE FROM company_settings WHERE company_id = ?', [$companyId]);

            $stmt = \InterfaceDB::prepareExecute('DELETE FROM companies WHERE id = ?', [$companyId]);

            if ($stmt->rowCount() < 1) {
                throw new \RuntimeException('The selected company could not be deleted.');
            }

            $nominalCleanup = $this->deleteUnreferencedAutoNominals($autoNominalIds);

            \InterfaceDB::commit();
        } catch (\Throwable $e) {
            if (\InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            throw $e;
        }

        return [
            'auto_nominals' => $nominalCleanup ?? [
                'candidates' => 0,
                'deleted' => 0,
                'skipped' => 0,
            ],
        ];
    }

    private function fetchAutoNominalIdsForCompany(int $companyId): array
    {
        if ($companyId <= 0) {
            return [];
        }

        $ids = \InterfaceDB::prepareExecute(
            'SELECT id
             FROM nominal_accounts
             WHERE origin_type = ?
               AND origin_company_id = ?',
            ['company_account_auto', $companyId]
        )->fetchAll(\PDO::FETCH_COLUMN);

        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    }

    private function deleteUnreferencedAutoNominals(array $nominalIds): array
    {
        $nominalIds = array_values(array_unique(array_filter(array_map('intval', $nominalIds), static fn(int $id): bool => $id > 0)));
        $summary = [
            'candidates' => count($nominalIds),
            'deleted' => 0,
            'skipped' => 0,
        ];

        foreach ($nominalIds as $nominalId) {
            if ($this->nominalReferenceCount($nominalId) > 0) {
                $summary['skipped']++;
                continue;
            }

            $stmt = \InterfaceDB::prepareExecute(
                'DELETE FROM nominal_accounts
                 WHERE id = ?
                   AND origin_type = ?',
                [$nominalId, 'company_account_auto']
            );
            $summary['deleted'] += $stmt->rowCount();
        }

        return $summary;
    }

    private function nominalReferenceCount(int $nominalId): int
    {
        if ($nominalId <= 0) {
            return 0;
        }

        $count = 0;
        foreach ($this->nominalReferenceQueries() as $sql) {
            $count += (int)\InterfaceDB::fetchColumn($sql, ['nominal_id' => $nominalId]);
        }

        return $count;
    }

    private function nominalReferenceQueries(): array
    {
        return [
            'SELECT COUNT(*) FROM company_accounts WHERE nominal_account_id = :nominal_id',
            'SELECT COUNT(*) FROM categorisation_rules WHERE nominal_account_id = :nominal_id',
            'SELECT COUNT(*) FROM expense_claim_lines WHERE nominal_account_id = :nominal_id',
            'SELECT COUNT(*) FROM journal_lines WHERE nominal_account_id = :nominal_id',
            'SELECT COUNT(*) FROM corporation_tax_treatment_rules WHERE nominal_account_id = :nominal_id',
            'SELECT COUNT(*) FROM transaction_category_audit WHERE old_nominal_account_id = :nominal_id OR new_nominal_account_id = :nominal_id',
            'SELECT COUNT(*) FROM transactions WHERE nominal_account_id = :nominal_id',
            'SELECT COUNT(*) FROM asset_register WHERE nominal_account_id = :nominal_id OR accum_dep_nominal_id = :nominal_id',
            $this->companySettingsNominalReferenceSql(),
        ];
    }

    private function companySettingsNominalReferenceSql(): string
    {
        $quotedSettings = array_map(
            static fn(string $setting): string => "'" . str_replace("'", "''", $setting) . "'",
            self::NOMINAL_SETTING_KEYS
        );

        return 'SELECT COUNT(*)
                FROM company_settings
                WHERE setting IN (' . implode(', ', $quotedSettings) . ')
                  AND TRIM(COALESCE(value, \'\')) = CAST(:nominal_id AS CHAR)';
    }

    private function fetchCompaniesSql(): string
    {
        return 'SELECT id,
                       company_name,
                       company_number,
                       incorporation_date,
                       company_status,
                       registered_office_address_line_1,
                       registered_office_address_line_2,
                       registered_office_locality,
                       registered_office_region,
                       registered_office_postal_code,
                       registered_office_country,
                       registered_office_care_of,
                       registered_office_po_box,
                       registered_office_premises,
                       can_file,
                       has_charges,
                       has_insolvency_history,
                       has_been_liquidated,
                       registered_office_is_in_dispute,
                       undeliverable_registered_office_address,
                       has_super_secure_pscs,
                       companies_house_environment,
                       companies_house_etag,
                       companies_house_last_checked_at,
                       companies_house_profile_json,
                       companies_house_active_director_count,
                       companies_house_officers_last_checked_at,
                       companies_house_officers_json
                FROM companies
                ORDER BY company_name, id';
    }

    public function updateCompaniesHouseDirectorCheck(int $companyId, array $directorCheck): void
    {
        if ($companyId <= 0 || !\InterfaceDB::columnExists('companies', 'companies_house_active_director_count')) {
            return;
        }

        if (!array_key_exists('director_count', $directorCheck) || $directorCheck['director_count'] === null) {
            return;
        }

        \InterfaceDB::prepareExecute(
            'UPDATE companies
             SET companies_house_active_director_count = :director_count,
                 companies_house_officers_json = :officers_json,
                 companies_house_officers_last_checked_at = CURRENT_TIMESTAMP
             WHERE id = :company_id',
            [
                'director_count' => (int)$directorCheck['director_count'],
                'officers_json' => $directorCheck['officers_json'] ?? null,
                'company_id' => $companyId,
            ]
        );
    }
}
