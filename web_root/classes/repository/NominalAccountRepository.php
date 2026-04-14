<?php
declare(strict_types=1);

final class NominalAccountRepository
{
    public function fetchNominalAccounts(int $companyId = 0): array
    {
        return InterfaceDB::fetchAll($this->fetchNominalAccountsSql());
    }

    public function fetchNominalAccountCatalog(): array
    {
        return InterfaceDB::fetchAll($this->fetchNominalAccountCatalogSql());
    }

    public function findByCode(string $code): ?array
    {
        $row = InterfaceDB::fetchOne(
            'SELECT id, code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order
             FROM nominal_accounts
             WHERE code = :code
             LIMIT 1',
            ['code' => trim($code)]
        );

        return is_array($row) ? $row : null;
    }
    public function validateInput(array $input, array $subtypeIndex, ?int $ignoreId = null): array
    {
        $errors = [];
        $code = trim((string)($input['code'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $accountType = trim((string)($input['account_type'] ?? ''));
        $subtypeId = trim((string)($input['account_subtype_id'] ?? ''));
        $taxTreatment = strtolower(trim((string)($input['tax_treatment'] ?? 'allowable')));
        $sortOrder = trim((string)($input['sort_order'] ?? '100'));

        if ($code === '') {
            $errors[] = 'Nominal code is required.';
        }

        if ($name === '') {
            $errors[] = 'Nominal name is required.';
        }

        if (!in_array($accountType, $this->validAccountTypes(), true)) {
            $errors[] = 'Nominal account type is invalid.';
        }

        if (!in_array($taxTreatment, $this->validNominalTaxTreatments(), true)) {
            $errors[] = 'Nominal tax treatment is invalid.';
        }

        if ($sortOrder === '' || preg_match('/^-?\d+$/', $sortOrder) !== 1) {
            $errors[] = 'Nominal sort order must be a whole number.';
        }

        if ($subtypeId !== '') {
            if (!ctype_digit($subtypeId) || !isset($subtypeIndex[(int)$subtypeId])) {
                $errors[] = 'Nominal subtype selection is invalid.';
            } elseif ((string)$subtypeIndex[(int)$subtypeId]['parent_account_type'] !== $accountType) {
                $errors[] = 'Nominal account type must match the selected subtype parent account type.';
            }
        }

        if ($errors === [] && $code !== '') {
            $sql = 'SELECT id FROM nominal_accounts WHERE code = ?';
            $params = [$code];

            if ($ignoreId !== null) {
                $sql .= ' AND id <> ?';
                $params[] = $ignoreId;
            }

            $sql .= ' LIMIT 1';
            $stmt = InterfaceDB::prepareExecute($sql, $params);

            if ($stmt->fetch()) {
                $errors[] = 'Nominal code must be unique.';
            }
        }

        return $errors;
    }

    public function save(array $input, ?int $id = null): void
    {
        $subtypeId = trim((string)$input['account_subtype_id']) !== '' ? (int)$input['account_subtype_id'] : null;
        $payload = [
            trim((string)$input['code']),
            trim((string)$input['name']),
            trim((string)$input['account_type']),
            $subtypeId,
            strtolower(trim((string)($input['tax_treatment'] ?? 'allowable'))),
            (int)$input['is_active'],
            (int)$input['sort_order'],
        ];

        if ($id === null) {
            InterfaceDB::prepareExecute(
                'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                $payload
            );
            return;
        }

        InterfaceDB::prepareExecute(
            'UPDATE nominal_accounts
             SET code = ?, name = ?, account_type = ?, account_subtype_id = ?, tax_treatment = ?, is_active = ?, sort_order = ?
             WHERE id = ?',
            [...$payload, $id]
        );
    }

    /**
     * @return list<string>
     */
    private function validAccountTypes(): array
    {
        return ['asset', 'liability', 'equity', 'income', 'expense'];
    }

    /**
     * @return list<string>
     */
    private function validNominalTaxTreatments(): array
    {
        return ['allowable', 'disallowable', 'capital', 'other'];
    }

    private function fetchNominalAccountsSql(): string
    {
        return 'SELECT na.id, na.code, na.name, na.account_type, na.tax_treatment, nas.code AS subtype_code
                FROM nominal_accounts na
                LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
                WHERE na.is_active = 1
                ORDER BY na.sort_order, na.code, na.name, na.id';
    }

    private function fetchNominalAccountCatalogSql(): string
    {
        return 'SELECT na.id,
                       na.code,
                       na.name,
                       na.account_type,
                       na.account_subtype_id,
                       na.tax_treatment,
                       na.is_active,
                       na.sort_order,
                       nas.code AS subtype_code,
                       nas.name AS subtype_name,
                       nas.parent_account_type
                FROM nominal_accounts na
                LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
                ORDER BY na.sort_order, na.code, na.name, na.id';
    }
}
