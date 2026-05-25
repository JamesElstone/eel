<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class CompanyAccountNominalService
{
    private const BANK_CODE_START = 1001;
    private const BANK_CODE_END = 1099;
    private const TRADE_CODE_START = 2001;
    private const TRADE_CODE_END = 2099;

    public function assignMissingNominals(int $companyId): array
    {
        if ($companyId <= 0) {
            return [
                'success' => false,
                'assigned' => 0,
                'created' => 0,
                'unchanged' => 0,
                'errors' => ['Select a company before assigning account nominals.'],
            ];
        }

        $settings = (new CompanySettingsStore($companyId))->all();
        $defaultBankNominalId = (int)($settings['default_bank_nominal_id'] ?? 0);
        $accounts = $this->fetchCompanyAccounts($companyId);
        $summary = [
            'success' => true,
            'assigned' => 0,
            'created' => 0,
            'unchanged' => 0,
            'errors' => [],
        ];

        foreach ($accounts as $account) {
            if (!$this->needsNominalAssignment($account, $defaultBankNominalId)) {
                $summary['unchanged']++;
                continue;
            }

            $result = $this->createNominalForAccount($account);

            if (empty($result['success'])) {
                $summary['errors'] = array_merge($summary['errors'], array_map('strval', (array)($result['errors'] ?? [])));
                continue;
            }

            $this->updateCompanyAccountNominal((int)$account['id'], (int)$result['nominal_account_id']);
            $summary['assigned']++;
            if (!empty($result['created'])) {
                $summary['created']++;
            }
        }

        $summary['success'] = $summary['errors'] === [];

        return $summary;
    }

    public function normaliseNominalId(mixed $value): ?int
    {
        $value = trim((string)$value);

        return ctype_digit($value) && (int)$value > 0 ? (int)$value : null;
    }

    public function validateNominalForAccountType(string $accountType, ?int $nominalAccountId): array
    {
        if ($nominalAccountId === null || $nominalAccountId <= 0) {
            return [];
        }

        $nominal = $this->fetchNominal($nominalAccountId);
        if ($nominal === null || (int)($nominal['is_active'] ?? 0) !== 1) {
            return ['The selected nominal account could not be found or is inactive.'];
        }

        $nominalType = (string)($nominal['account_type'] ?? '');
        $subtypeCode = (string)($nominal['subtype_code'] ?? '');

        if ($accountType === CompanyAccountService::TYPE_BANK) {
            if ($nominalType !== 'asset') {
                return ['Bank company accounts must use an asset nominal.'];
            }

            return [];
        }

        if ($accountType === CompanyAccountService::TYPE_TRADE) {
            if ($nominalType !== 'liability') {
                return ['Trade company accounts must use a liability nominal.'];
            }

            if ($subtypeCode !== '' && !in_array($subtypeCode, ['trade_creditor', 'expense_payable'], true)) {
                return ['Trade company accounts must use a trade creditor/payable nominal.'];
            }

            return [];
        }

        return ['Account type must be bank or trade.'];
    }

    public function resolveNominalForAccountInput(array $input): array
    {
        $nominalAccountId = isset($input['nominal_account_id']) ? (int)$input['nominal_account_id'] : 0;
        if ($nominalAccountId > 0) {
            return [
                'success' => true,
                'nominal_account_id' => $nominalAccountId,
                'created' => false,
                'errors' => [],
            ];
        }

        return $this->createNominalForAccount([
            'account_name' => (string)($input['account_name'] ?? ''),
            'account_type' => (string)($input['account_type'] ?? CompanyAccountService::TYPE_BANK),
        ]);
    }

    private function createNominalForAccount(array $account): array
    {
        $accountType = (string)($account['account_type'] ?? CompanyAccountService::TYPE_BANK);
        $definition = $this->definitionForAccountType($accountType);

        if ($definition === null) {
            return [
                'success' => false,
                'errors' => ['Account type must be bank or trade before a nominal can be assigned.'],
            ];
        }

        $code = $this->nextFreeCode((int)$definition['start'], (int)$definition['end']);
        if ($code === null) {
            return [
                'success' => false,
                'errors' => [sprintf('No free nominal codes remain in the %d-%d range.', (int)$definition['start'], (int)$definition['end'])],
            ];
        }

        $subtypeId = $this->findOrCreateSubtype(
            (string)$definition['subtype_code'],
            (string)$definition['subtype_name'],
            (string)$definition['account_type'],
            (int)$definition['subtype_sort_order']
        );

        if ($subtypeId <= 0) {
            return [
                'success' => false,
                'errors' => ['The nominal subtype for this account could not be prepared.'],
            ];
        }

        $name = $this->generatedNominalName((string)($account['account_name'] ?? ''), $accountType);
        InterfaceDB::prepareExecute(
            'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $code,
                $name,
                (string)$definition['account_type'],
                $subtypeId,
                'other',
                1,
                (int)$code,
            ]
        );

        $nominal = $this->fetchNominalByCode($code);

        if ($nominal === null) {
            return [
                'success' => false,
                'errors' => ['The generated nominal could not be reloaded after creation.'],
            ];
        }

        return [
            'success' => true,
            'nominal_account_id' => (int)$nominal['id'],
            'nominal_code' => (string)$nominal['code'],
            'created' => true,
            'errors' => [],
        ];
    }

    private function needsNominalAssignment(array $account, int $defaultBankNominalId): bool
    {
        $accountType = (string)($account['account_type'] ?? '');
        $nominalId = (int)($account['nominal_account_id'] ?? 0);
        $nominalCode = trim((string)($account['nominal_code'] ?? ''));
        $nominalType = (string)($account['nominal_account_type'] ?? '');
        $subtypeCode = (string)($account['nominal_subtype_code'] ?? '');
        $isActive = (int)($account['nominal_is_active'] ?? 0) === 1;

        if ($nominalId <= 0 || !$isActive) {
            return true;
        }

        if ($accountType === CompanyAccountService::TYPE_BANK) {
            return $nominalType !== 'asset'
                || $nominalCode === '1000'
                || ($defaultBankNominalId > 0 && $nominalId === $defaultBankNominalId);
        }

        if ($accountType === CompanyAccountService::TYPE_TRADE) {
            return $nominalType !== 'liability'
                || !in_array($subtypeCode, ['trade_creditor', 'expense_payable'], true);
        }

        return true;
    }

    private function updateCompanyAccountNominal(int $accountId, int $nominalAccountId): void
    {
        InterfaceDB::prepareExecute(
            'UPDATE company_accounts
             SET nominal_account_id = ?
             WHERE id = ?',
            [$nominalAccountId, $accountId]
        );
    }

    private function fetchCompanyAccounts(int $companyId): array
    {
        return InterfaceDB::fetchAll(
            'SELECT ca.id,
                    ca.company_id,
                    ca.account_name,
                    ca.account_type,
                    ca.nominal_account_id,
                    COALESCE(na.code, \'\') AS nominal_code,
                    COALESCE(na.account_type, \'\') AS nominal_account_type,
                    COALESCE(nas.code, \'\') AS nominal_subtype_code,
                    COALESCE(na.is_active, 0) AS nominal_is_active
             FROM company_accounts ca
             LEFT JOIN nominal_accounts na ON na.id = ca.nominal_account_id
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE ca.company_id = :company_id
             ORDER BY ca.is_active DESC, ca.account_type ASC, ca.account_name ASC, ca.id ASC',
            ['company_id' => $companyId]
        );
    }

    private function fetchNominal(int $nominalAccountId): ?array
    {
        $row = InterfaceDB::fetchOne(
            'SELECT na.id,
                    na.code,
                    na.name,
                    na.account_type,
                    na.account_subtype_id,
                    na.tax_treatment,
                    na.is_active,
                    COALESCE(nas.code, \'\') AS subtype_code
             FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.id = :id
             LIMIT 1',
            ['id' => $nominalAccountId]
        );

        return is_array($row) ? $row : null;
    }

    private function fetchNominalByCode(string $code): ?array
    {
        $row = InterfaceDB::fetchOne(
            'SELECT id, code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order
             FROM nominal_accounts
             WHERE code = :code
             LIMIT 1',
            ['code' => $code]
        );

        return is_array($row) ? $row : null;
    }

    private function nextFreeCode(int $start, int $end): ?string
    {
        $rows = InterfaceDB::fetchAll(
            'SELECT code
             FROM nominal_accounts
             WHERE code BETWEEN :start_code AND :end_code',
            [
                'start_code' => (string)$start,
                'end_code' => (string)$end,
            ]
        );
        $used = [];
        foreach ($rows as $row) {
            $code = trim((string)($row['code'] ?? ''));
            if (ctype_digit($code)) {
                $used[(int)$code] = true;
            }
        }

        for ($code = $start; $code <= $end; $code++) {
            if (!isset($used[$code])) {
                return (string)$code;
            }
        }

        return null;
    }

    private function findOrCreateSubtype(string $code, string $name, string $parentAccountType, int $sortOrder): int
    {
        $subtype = $this->fetchSubtypeByCode($code);
        if ($subtype !== null) {
            return (int)$subtype['id'];
        }

        InterfaceDB::prepareExecute(
            'INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?)',
            [$code, $name, $parentAccountType, $sortOrder, 1]
        );

        $subtype = $this->fetchSubtypeByCode($code);

        return $subtype !== null ? (int)$subtype['id'] : 0;
    }

    private function fetchSubtypeByCode(string $code): ?array
    {
        $row = InterfaceDB::fetchOne(
            'SELECT id, code, name, parent_account_type, sort_order, is_active
             FROM nominal_account_subtypes
             WHERE code = :code
             LIMIT 1',
            ['code' => strtolower(trim($code))]
        );

        return is_array($row) ? $row : null;
    }

    private function definitionForAccountType(string $accountType): ?array
    {
        return match ($accountType) {
            CompanyAccountService::TYPE_BANK => [
                'start' => self::BANK_CODE_START,
                'end' => self::BANK_CODE_END,
                'account_type' => 'asset',
                'subtype_code' => 'bank',
                'subtype_name' => 'Bank',
                'subtype_sort_order' => 10,
            ],
            CompanyAccountService::TYPE_TRADE => [
                'start' => self::TRADE_CODE_START,
                'end' => self::TRADE_CODE_END,
                'account_type' => 'liability',
                'subtype_code' => 'trade_creditor',
                'subtype_name' => 'Trade Creditor',
                'subtype_sort_order' => 45,
            ],
            default => null,
        };
    }

    private function generatedNominalName(string $accountName, string $accountType): string
    {
        $name = trim($accountName) !== '' ? trim($accountName) : 'Company Account';

        if ($accountType === CompanyAccountService::TYPE_TRADE) {
            $lower = strtolower($name);
            if (!str_contains($lower, 'creditor') && !str_contains($lower, 'payable')) {
                $name .= ' Trade Creditor';
            }
        }

        return mb_substr($name, 0, 255);
    }
}
