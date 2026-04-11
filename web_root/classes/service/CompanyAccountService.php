<?php
declare(strict_types=1);

final class CompanyAccountService
{
    public const TYPE_BANK = 'bank';
    public const TYPE_TRADE = 'trade';

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

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

        $sql = 'SELECT id,
                       company_id,
                       account_name,
                       account_type,
                       institution_name,
                       account_identifier,
                       internal_transfer_marker,
                       contact_name,
                       phone_number,
                       address_line_1,
                       address_line_2,
                       address_locality,
                       address_region,
                       address_postal_code,
                       address_country,
                       is_active,
                       created_at,
                       updated_at
                FROM company_accounts
                WHERE company_id = :company_id';

        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }

        $sql .= ' ORDER BY is_active DESC, account_type ASC, account_name ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['company_id' => $companyId]);

        return $stmt->fetchAll();
    }

    public function fetchAccount(int $companyId, int $accountId): ?array {
        if ($companyId <= 0 || $accountId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id,
                    company_id,
                    account_name,
                    account_type,
                    institution_name,
                    account_identifier,
                    internal_transfer_marker,
                    contact_name,
                    phone_number,
                    address_line_1,
                    address_line_2,
                    address_locality,
                    address_region,
                    address_postal_code,
                    address_country,
                    is_active,
                    created_at,
                    updated_at
             FROM company_accounts
             WHERE company_id = :company_id
               AND id = :id
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'id' => $accountId,
        ]);
        $row = $stmt->fetch();

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

        $stmt = $this->pdo->prepare(
            'INSERT INTO company_accounts (
                company_id,
                account_name,
                account_type,
                institution_name,
                account_identifier,
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

        $reload = $this->pdo->prepare(
            'SELECT id
             FROM company_accounts
             WHERE company_id = :company_id
               AND account_name = :account_name
               AND account_type = :account_type
             ORDER BY id DESC
             LIMIT 1'
        );
        $reload->execute([
            'company_id' => $companyId,
            'account_name' => $input['account_name'],
            'account_type' => $input['account_type'],
        ]);
        $accountId = $reload->fetchColumn();

        return [
            'success' => $accountId !== false,
            'errors' => $accountId !== false ? [] : ['The account was saved but could not be reloaded.'],
            'account_id' => $accountId !== false ? (int)$accountId : 0,
        ];
    }

    public function updateAccount(int $companyId, int $accountId, array $post): array {
        $errors = [];
        $input = $this->normaliseInput($post);

        if ($companyId <= 0) {
            $errors[] = 'A company must be selected before editing an account.';
        }

        if ($accountId <= 0 || $this->fetchAccount($companyId, $accountId) === null) {
            $errors[] = 'The selected account could not be found.';
        }

        $errors = array_merge($errors, $this->validateInput($companyId, $input, $accountId));

        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
                'account_id' => $accountId,
            ];
        }

        $stmt = $this->pdo->prepare(
            'UPDATE company_accounts
             SET account_name = :account_name,
                 account_type = :account_type,
                 institution_name = :institution_name,
                 account_identifier = :account_identifier,
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

        return [
            'success' => true,
            'errors' => [],
            'account_id' => $accountId,
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

        $stmt = $this->pdo->prepare(
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

        if (($input['address_line_1'] ?? null) === null) {
            $errors[] = 'Address line 1 is required.';
        }

        if (($input['phone_number'] ?? null) === null) {
            $errors[] = 'Phone number is required.';
        }

        if (($input['account_name'] ?? null) !== null && isset(self::accountTypes()[$input['account_type'] ?? ''])) {
            $params = [
                'company_id' => $companyId,
                'account_name' => $input['account_name'],
                'account_type' => $input['account_type'],
            ];
            $sql = 'SELECT COUNT(*)
                    FROM company_accounts
                    WHERE company_id = :company_id
                      AND account_name = :account_name
                      AND account_type = :account_type';

            if ($accountId !== null && $accountId > 0) {
                $sql .= ' AND id <> :account_id';
                $params['account_id'] = $accountId;
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            if ((int)$stmt->fetchColumn() > 0) {
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

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM statement_uploads WHERE account_id = :account_id');
        $stmt->execute(['account_id' => $accountId]);
        $usage['uploads'] = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM transactions WHERE account_id = :account_id');
        $stmt->execute(['account_id' => $accountId]);
        $usage['transactions'] = (int)$stmt->fetchColumn();

        return $usage;
    }
}
