<?php
declare(strict_types=1);

final class CompanyRepository
{
    public function fetchCompanies(): array
    {
        return InterfaceDB::fetchAll($this->fetchCompaniesSql());
    }

    public function fetchCompanySelectorRows(): array
    {
        return InterfaceDB::fetchAll(
            'SELECT id,
                    company_name,
                    company_number
             FROM companies
             ORDER BY company_name, id'
        );
    }

    public function fetchSettingsCompany(int $companyId): ?array
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

        $row = InterfaceDB::fetchOne(
            'SELECT ' . implode(', ', $select) . ' FROM companies WHERE id = :id',
            ['id' => $companyId]
        );

        return is_array($row) ? $row : null;
    }

    public function updateBasicDetails(array $settings): void
    {
        InterfaceDB::prepareExecute('UPDATE companies SET company_name = ?, company_number = ? WHERE id = ?', [
            $settings['company_name'],
            $settings['companies_house_number'] !== '' ? $settings['companies_house_number'] : null,
            (int)$settings['company_id'],
        ]);
    }

    public function saveCompanySection(array $settings): void
    {
        $isVatRegistered = !empty($settings['is_vat_registered']);
        $canPersistVatDetails = $isVatRegistered && in_array(
            trim((string)($settings['vat_validation_status'] ?? '')),
            ['valid', 'mismatch_override'],
            true
        );
        $vatCountryCode = $canPersistVatDetails
            ? (trim((string)($settings['vat_country_code'] ?? '')) !== '' ? strtoupper(trim((string)$settings['vat_country_code'])) : null)
            : null;
        $vatNumber = $canPersistVatDetails
            ? (trim((string)($settings['vat_number'] ?? '')) !== '' ? trim((string)$settings['vat_number']) : null)
            : null;
        $vatValidationStatus = $canPersistVatDetails ? trim((string)($settings['vat_validation_status'] ?? '')) : null;
        $vatValidatedAt = $canPersistVatDetails ? (trim((string)($settings['vat_validated_at'] ?? '')) !== '' ? trim((string)$settings['vat_validated_at']) : null) : null;
        $vatValidationSource = $canPersistVatDetails ? (trim((string)($settings['vat_validation_source'] ?? '')) !== '' ? trim((string)$settings['vat_validation_source']) : null) : null;
        $vatValidationName = $canPersistVatDetails ? (trim((string)($settings['vat_validation_name'] ?? '')) !== '' ? trim((string)$settings['vat_validation_name']) : null) : null;
        $vatValidationAddressLine1 = $canPersistVatDetails ? (trim((string)($settings['vat_validation_address_line1'] ?? '')) !== '' ? trim((string)$settings['vat_validation_address_line1']) : null) : null;
        $vatValidationPostcode = $canPersistVatDetails ? (trim((string)($settings['vat_validation_postcode'] ?? '')) !== '' ? trim((string)$settings['vat_validation_postcode']) : null) : null;
        $vatValidationCountryCode = $canPersistVatDetails ? (trim((string)($settings['vat_validation_country_code'] ?? '')) !== '' ? strtoupper(trim((string)$settings['vat_validation_country_code'])) : null) : null;
        $vatLastError = $canPersistVatDetails ? (trim((string)($settings['vat_last_error'] ?? '')) !== '' ? trim((string)$settings['vat_last_error']) : null) : null;

        $sql = 'UPDATE companies
             SET company_name = ?,
                 company_number = ?,
                 is_vat_registered = ?,
                 vat_country_code = ?,
                 vat_number = ?,
                 vat_validation_status = ?,
                 vat_validated_at = ?,
                 vat_validation_source = ?,
                 vat_validation_name = ?,
                 vat_validation_address_line1 = ?,
                 vat_validation_postcode = ?,
                 vat_validation_country_code = ?';
        $params = [
            $settings['company_name'],
            $settings['companies_house_number'] !== '' ? $settings['companies_house_number'] : null,
            $isVatRegistered ? 1 : 0,
            $vatCountryCode,
            $vatNumber,
            $vatValidationStatus,
            $vatValidatedAt,
            $vatValidationSource,
            $vatValidationName,
            $vatValidationAddressLine1,
            $vatValidationPostcode,
            $vatValidationCountryCode,
        ];

        $sql .= ',
                 vat_last_error = ?
             WHERE id = ?';
        $params[] = $vatLastError;
        $params[] = (int)$settings['company_id'];

        InterfaceDB::prepareExecute($sql, $params);
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
            $companyId = InterfaceDB::fetchColumn(
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

        $companyId = InterfaceDB::fetchColumn(
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
            throw new RuntimeException('Company name is required.');
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
                    InterfaceDB::prepareExecute('UPDATE companies SET ' . implode(', ', $updateClauses) . ' WHERE id = ?', $params);
                }
            }

            return $existingCompanyId;
        }

        InterfaceDB::beginTransaction();

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

            InterfaceDB::prepareExecute(
                'INSERT INTO companies (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
                ,
                $payload
            );

            $companyId = InterfaceDB::fetchColumn(
                'SELECT id
                 FROM companies
                 WHERE company_name = ?
                   AND ((company_number = ?) OR (company_number IS NULL AND ? IS NULL))
                 ORDER BY id DESC
                 LIMIT 1',
                [$companyName, $companyNumber, $companyNumber]
            );

            if ($companyId === false) {
                throw new RuntimeException('Company was inserted but could not be reloaded.');
            }

            InterfaceDB::commit();
        } catch (Throwable $e) {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            throw $e;
        }

        return (int)$companyId;
    }

    public function deleteCompany(int $companyId): void
    {
        if ($companyId <= 0) {
            throw new RuntimeException('A valid company must be selected before deletion.');
        }

        InterfaceDB::beginTransaction();

        try {
            $companyNumber = trim((string)InterfaceDB::fetchColumn('SELECT company_number FROM companies WHERE id = ?', [$companyId]));

            if ($companyNumber !== '') {
                InterfaceDB::prepareExecute('DELETE FROM companies_house_documents WHERE company_id = ? OR company_number = ?', [$companyId, $companyNumber]);
            } else {
                InterfaceDB::prepareExecute('DELETE FROM companies_house_documents WHERE company_id = ?', [$companyId]);
            }

            InterfaceDB::prepareExecute('DELETE FROM company_settings WHERE company_id = ?', [$companyId]);

            $stmt = InterfaceDB::prepareExecute('DELETE FROM companies WHERE id = ?', [$companyId]);

            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('The selected company could not be deleted.');
            }

            InterfaceDB::commit();
        } catch (Throwable $e) {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            throw $e;
        }
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
                       companies_house_profile_json
                FROM companies
                ORDER BY company_name, id';
    }
}
