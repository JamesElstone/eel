<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CompanyAccountService
{
    public const TYPE_BANK = 'bank';
    public const TYPE_TRADE = 'trade';

    public static function accountTypes(): array {
        return [
            self::TYPE_BANK => 'Bank',
            self::TYPE_TRADE => 'Trade',
        ];
    }

    public function fetchAccounts(int $companyId, bool $activeOnly = false): array {
        if ($companyId <= 0) {
            return [];
        }

        $sql = 'SELECT ca.id,
                       ca.company_id,
                       ca.account_name,
                       ca.account_type,
                       ca.institution_name,
                       ca.account_identifier,
                       ca.nominal_account_id,
                       COALESCE(na.code, \'\') AS nominal_code,
                       COALESCE(na.name, \'\') AS nominal_name,
                       ca.internal_transfer_marker,
                       ca.contact_name,
                       ca.phone_number,
                       ca.address_line_1,
                       ca.address_line_2,
                       ca.address_locality,
                       ca.address_region,
                       ca.address_postal_code,
                       ca.address_country,
                       ca.is_active,
                       ca.created_at,
                       ca.updated_at
                FROM company_accounts ca
                LEFT JOIN nominal_accounts na ON na.id = ca.nominal_account_id
                WHERE ca.company_id = :company_id';

        if ($activeOnly) {
            $sql .= ' AND ca.is_active = 1';
        }

        $sql .= ' ORDER BY ca.is_active DESC, ca.account_type ASC, ca.account_name ASC, ca.id ASC';

        return \InterfaceDB::fetchAll( $sql, ['company_id' => $companyId]);
    }

    public function fetchAccount(int $companyId, int $accountId): ?array {
        if ($companyId <= 0 || $accountId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne( 'SELECT ca.id,
                    ca.company_id,
                    ca.account_name,
                    ca.account_type,
                    ca.institution_name,
                    ca.account_identifier,
                    ca.nominal_account_id,
                    COALESCE(na.code, \'\') AS nominal_code,
                    COALESCE(na.name, \'\') AS nominal_name,
                    ca.internal_transfer_marker,
                    ca.contact_name,
                    ca.phone_number,
                    ca.address_line_1,
                    ca.address_line_2,
                    ca.address_locality,
                    ca.address_region,
                    ca.address_postal_code,
                    ca.address_country,
                    ca.is_active,
                    ca.created_at,
                    ca.updated_at
             FROM company_accounts ca
             LEFT JOIN nominal_accounts na ON na.id = ca.nominal_account_id
             WHERE ca.company_id = :company_id
               AND ca.id = :id
             LIMIT 1', [
            'company_id' => $companyId,
            'id' => $accountId,
        ]);

        return is_array($row) ? $row : null;
    }

    public function createAccount(int $companyId, array $post): array {
        $errors = [];
        $input = $this->normaliseInput($post);

        if ($companyId <= 0) {
            $errors[] = 'A company must be selected before adding an account.';
        }

        $errors = array_merge($errors, $this->validateInput($companyId, $input));

        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
                'account_id' => 0,
            ];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();

        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $input['company_id'] = $companyId;
            $nominalService = new \eel_accounts\Service\CompanyAccountNominalService();
            $nominalResult = $nominalService->resolveNominalForAccountInput($input);
            if (empty($nominalResult['success'])) {
                if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }

                return [
                    'success' => false,
                    'errors' => array_map('strval', (array)($nominalResult['errors'] ?? [])),
                    'account_id' => 0,
                ];
            }

            $stmt = \InterfaceDB::prepare(
                'INSERT INTO company_accounts (
                company_id,
                account_name,
                account_type,
                institution_name,
                account_identifier,
                nominal_account_id,
                internal_transfer_marker,
                contact_name,
                phone_number,
                address_line_1,
                address_line_2,
                address_locality,
                address_region,
                address_postal_code,
                address_country,
                is_active
            ) VALUES (
                :company_id,
                :account_name,
                :account_type,
                :institution_name,
                :account_identifier,
                :nominal_account_id,
                :internal_transfer_marker,
                :contact_name,
                :phone_number,
                :address_line_1,
                :address_line_2,
                :address_locality,
                :address_region,
                :address_postal_code,
                :address_country,
                :is_active
            )'
            );
            $stmt->execute([
                'company_id' => $companyId,
                'account_name' => $input['account_name'],
                'account_type' => $input['account_type'],
                'institution_name' => $input['institution_name'],
                'account_identifier' => $input['account_identifier'],
                'nominal_account_id' => (int)$nominalResult['nominal_account_id'],
                'internal_transfer_marker' => $input['internal_transfer_marker'],
                'contact_name' => $input['contact_name'],
                'phone_number' => $input['phone_number'],
                'address_line_1' => $input['address_line_1'],
                'address_line_2' => $input['address_line_2'],
                'address_locality' => $input['address_locality'],
                'address_region' => $input['address_region'],
                'address_postal_code' => $input['address_postal_code'],
                'address_country' => $input['address_country'],
                'is_active' => $input['is_active'] ? 1 : 0,
            ]);

            $accountId = \InterfaceDB::fetchColumn( 'SELECT id
             FROM company_accounts
             WHERE company_id = :company_id
               AND account_name = :account_name
               AND account_type = :account_type
             ORDER BY id DESC
             LIMIT 1', [
            'company_id' => $companyId,
            'account_name' => $input['account_name'],
            'account_type' => $input['account_type'],
            ]);

            if ($accountId !== false && !empty($nominalResult['created'])) {
                $nominalService->assignAutoNominalOriginAccount(
                    (int)$nominalResult['nominal_account_id'],
                    $companyId,
                    (int)$accountId
                );
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return [
                'success' => false,
                'errors' => ['The account could not be saved: ' . $exception->getMessage()],
                'account_id' => 0,
            ];
        }

        return [
            'success' => $accountId !== false,
            'errors' => $accountId !== false ? [] : ['The account was saved but could not be reloaded.'],
            'account_id' => $accountId !== false ? (int)$accountId : 0,
            'nominal_account_id' => (int)$nominalResult['nominal_account_id'],
            'nominal_code' => (string)($nominalResult['nominal_code'] ?? ''),
            'nominal_created' => !empty($nominalResult['created']),
        ];
    }

    public function updateAccount(int $companyId, int $accountId, array $post): array {
        $errors = [];
        $input = $this->normaliseInput($post);
        $existingAccount = null;

        if ($companyId <= 0) {
            $errors[] = 'A company must be selected before editing an account.';
        }

        if ($accountId > 0) {
            $existingAccount = $this->fetchAccount($companyId, $accountId);
        }

        if ($accountId <= 0 || $existingAccount === null) {
            $errors[] = 'The selected account could not be found.';
        }

        if (!array_key_exists('nominal_account_id', $post) && $existingAccount !== null) {
            $existingNominalId = (int)($existingAccount['nominal_account_id'] ?? 0);
            $input['nominal_account_id'] = $existingNominalId > 0 ? $existingNominalId : null;
        }

        $errors = array_merge($errors, $this->validateInput($companyId, $input, $accountId));

        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
                'account_id' => $accountId,
            ];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();

        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $input['company_id'] = $companyId;
            $input['id'] = $accountId;
            $nominalResult = (new \eel_accounts\Service\CompanyAccountNominalService())->resolveNominalForAccountInput($input);
            if (empty($nominalResult['success'])) {
                if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }

                return [
                    'success' => false,
                    'errors' => array_map('strval', (array)($nominalResult['errors'] ?? [])),
                    'account_id' => $accountId,
                ];
            }

            $stmt = \InterfaceDB::prepare(
                'UPDATE company_accounts
             SET account_name = :account_name,
                 account_type = :account_type,
                 institution_name = :institution_name,
                 account_identifier = :account_identifier,
                 nominal_account_id = :nominal_account_id,
                 internal_transfer_marker = :internal_transfer_marker,
                 contact_name = :contact_name,
                 phone_number = :phone_number,
                 address_line_1 = :address_line_1,
                 address_line_2 = :address_line_2,
                 address_locality = :address_locality,
                 address_region = :address_region,
                 address_postal_code = :address_postal_code,
                 address_country = :address_country,
                 is_active = :is_active
             WHERE company_id = :company_id
               AND id = :id'
            );
            $stmt->execute([
                'account_name' => $input['account_name'],
                'account_type' => $input['account_type'],
                'institution_name' => $input['institution_name'],
                'account_identifier' => $input['account_identifier'],
                'nominal_account_id' => (int)$nominalResult['nominal_account_id'],
                'internal_transfer_marker' => $input['internal_transfer_marker'],
                'contact_name' => $input['contact_name'],
                'phone_number' => $input['phone_number'],
                'address_line_1' => $input['address_line_1'],
                'address_line_2' => $input['address_line_2'],
                'address_locality' => $input['address_locality'],
                'address_region' => $input['address_region'],
                'address_postal_code' => $input['address_postal_code'],
                'address_country' => $input['address_country'],
                'is_active' => $input['is_active'] ? 1 : 0,
                'company_id' => $companyId,
                'id' => $accountId,
            ]);

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return [
                'success' => false,
                'errors' => ['The account could not be updated: ' . $exception->getMessage()],
                'account_id' => $accountId,
            ];
        }

        return [
            'success' => true,
            'errors' => [],
            'account_id' => $accountId,
            'nominal_account_id' => (int)$nominalResult['nominal_account_id'],
            'nominal_code' => (string)($nominalResult['nominal_code'] ?? ''),
            'nominal_created' => !empty($nominalResult['created']),
        ];
    }

    public function deleteAccount(int $companyId, int $accountId): array {
        if ($companyId <= 0) {
            return [
                'success' => false,
                'errors' => ['A company must be selected before deleting an account.'],
            ];
        }

        if ($accountId <= 0 || $this->fetchAccount($companyId, $accountId) === null) {
            return [
                'success' => false,
                'errors' => ['The selected account could not be found.'],
            ];
        }

        $usage = $this->fetchAccountUsage($accountId);

        if ($usage['uploads'] > 0 || $usage['transactions'] > 0) {
            $errors = ['This account cannot be deleted because uploads or transactions already reference it. Mark it inactive instead.'];

            if ($usage['uploads'] > 0) {
                $errors[] = sprintf('Uploads linked: %d.', $usage['uploads']);
            }

            if ($usage['transactions'] > 0) {
                $errors[] = sprintf('Transactions linked: %d.', $usage['transactions']);
            }

            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        $stmt = \InterfaceDB::prepare(
            'DELETE FROM company_accounts
             WHERE company_id = :company_id
               AND id = :id'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'id' => $accountId,
        ]);

        return [
            'success' => true,
            'errors' => [],
        ];
    }

    private function normaliseInput(array $post): array {
        $stringFields = [
            'account_name',
            'institution_name',
            'account_identifier',
            'internal_transfer_marker',
            'contact_name',
            'phone_number',
            'address_line_1',
            'address_line_2',
            'address_locality',
            'address_region',
            'address_postal_code',
            'address_country',
        ];

        $input = [
            'account_type' => trim((string)($post['account_type'] ?? self::TYPE_BANK)),
            'nominal_account_id' => (new \eel_accounts\Service\CompanyAccountNominalService())->normaliseNominalId($post['nominal_account_id'] ?? null),
            'is_active' => isset($post['is_active']),
        ];

        foreach ($stringFields as $field) {
            $value = trim((string)($post[$field] ?? ''));
            $input[$field] = $value !== '' ? $value : null;
        }

        if (($input['account_type'] ?? self::TYPE_BANK) !== self::TYPE_BANK) {
            $input['internal_transfer_marker'] = null;
        }

        return $input;
    }

    private function validateInput(int $companyId, array $input, ?int $accountId = null): array {
        $errors = [];

        if (($input['account_name'] ?? null) === null) {
            $errors[] = 'Account name is required.';
        }

        if (!isset(self::accountTypes()[$input['account_type'] ?? ''])) {
            $errors[] = 'Account type must be bank or trade.';
        }

        $errors = array_merge(
            $errors,
            (new \eel_accounts\Service\CompanyAccountNominalService())->validateNominalForAccountType(
                (string)($input['account_type'] ?? ''),
                $input['nominal_account_id'] ?? null
            )
        );

        if (($input['address_line_1'] ?? null) === null) {
            $errors[] = 'Address line 1 is required.';
        }

        if (($input['phone_number'] ?? null) === null) {
            $errors[] = 'Phone number is required.';
        }

        if (($input['account_name'] ?? null) !== null && isset(self::accountTypes()[$input['account_type'] ?? ''])) {
            $conditions = [
                'company_id' => $companyId,
                'account_name' => $input['account_name'],
                'account_type' => $input['account_type'],
            ];
            $existingCount = $accountId !== null && $accountId > 0
                ? \InterfaceDB::countWhereCompare('company_accounts', 'id', '<>', $accountId, $conditions)
                : \InterfaceDB::countWhere('company_accounts', $conditions);

            if ($existingCount > 0) {
                $errors[] = 'An account with that name and type already exists for the selected company.';
            }
        }

        return $errors;
    }

    private function fetchAccountUsage(int $accountId): array {
        $usage = [
            'uploads' => 0,
            'transactions' => 0,
        ];

        $usage['uploads'] = \InterfaceDB::countWhere('statement_uploads', 'account_id', $accountId);
        $usage['transactions'] = \InterfaceDB::countWhere('transactions', 'account_id', $accountId);

        return $usage;
    }
}


