<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class NominalAccountRepository
{
    public function fetchNominalAccounts(int $companyId = 0): array
    {
        return InterfaceDB::fetchAll($this->fetchNominalAccountsSql());
    }

    public function fetchNominalAccountCatalog(): array
    {
        return $this->withDeleteEligibility(InterfaceDB::fetchAll($this->fetchNominalAccountCatalogSql()));
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

    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            'SELECT id, code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order
             FROM nominal_accounts
             WHERE id = :id
             LIMIT 1',
            ['id' => $id]
        );

        return is_array($row) ? $row : null;
    }

    public function nominalReferenceCount(int $nominalId): int
    {
        if ($nominalId <= 0) {
            return 0;
        }

        $count = 0;
        foreach ($this->availableNominalReferenceQueries() as $sql) {
            $count += (int)InterfaceDB::fetchColumn($sql, ['nominal_id' => $nominalId]);
        }

        return $count;
    }

    public function canDeleteNominalAccount(int $nominalId): bool
    {
        return $nominalId > 0
            && $this->findById($nominalId) !== null
            && $this->nominalReferenceSchemaComplete()
            && $this->nominalReferenceCount($nominalId) === 0;
    }

    public function deleteNominalAccountIfUnused(int $nominalId): bool
    {
        if ($nominalId <= 0) {
            return false;
        }

        if (!$this->nominalReferenceSchemaComplete()) {
            return false;
        }

        $stmt = InterfaceDB::prepareExecute($this->deleteNominalAccountIfUnusedSql($this->availableNominalReferenceSources()), ['nominal_id' => $nominalId]);

        return $stmt->rowCount() > 0;
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
                'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order, origin_type, origin_company_id, origin_company_account_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [...$payload, 'manual', null, null]
            );
            return;
        }

        InterfaceDB::prepareExecute(
            'UPDATE nominal_accounts
             SET code = ?,
                 name = ?,
                 account_type = ?,
                 account_subtype_id = ?,
                 tax_treatment = ?,
                 is_active = ?,
                 sort_order = ?,
                 origin_type = ?,
                 origin_company_id = NULL,
                 origin_company_account_id = NULL
             WHERE id = ?',
            [...$payload, 'manual', $id]
        );
    }

    private function withDeleteEligibility(array $rows): array
    {
        $schemaComplete = $this->nominalReferenceSchemaComplete();

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[$index]['reference_count'] = null;
            $rows[$index]['can_delete'] = 0;

            if (!$schemaComplete) {
                continue;
            }

            try {
                $referenceCount = $this->nominalReferenceCount((int)($row['id'] ?? 0));
                $rows[$index]['reference_count'] = $referenceCount;
                $rows[$index]['can_delete'] = $referenceCount === 0 ? 1 : 0;
            } catch (Throwable) {
                $rows[$index]['reference_count'] = null;
                $rows[$index]['can_delete'] = 0;
            }
        }

        return $rows;
    }

    private function availableNominalReferenceQueries(): array
    {
        $queries = [];

        foreach ($this->availableNominalReferenceSources() as $source) {
            $queries[] = 'SELECT COUNT(*) FROM ' . $source['table'] . ' WHERE ' . $this->nominalReferenceWhereSql($source, ':nominal_id');
        }

        return $queries;
    }

    private function availableNominalReferenceSources(): array
    {
        return array_values(array_filter(
            $this->nominalReferenceSources(),
            fn(array $source): bool => $this->referenceSourceAvailable($source)
        ));
    }

    private function nominalReferenceSchemaComplete(): bool
    {
        return count($this->availableNominalReferenceSources()) === count($this->nominalReferenceSources());
    }

    private function referenceSourceAvailable(array $source): bool
    {
        $table = (string)($source['table'] ?? '');
        $columns = (array)($source['columns'] ?? []);

        if ($table === '' || $columns === []) {
            return false;
        }

        try {
            return InterfaceDB::tableExists($table) && InterfaceDB::columnsExists($table, $columns);
        } catch (Throwable) {
            return false;
        }
    }

    private function nominalReferenceSources(): array
    {
        return [
            [
                'table' => 'company_accounts',
                'columns' => ['nominal_account_id'],
                'where' => 'nominal_account_id = %s',
            ],
            [
                'table' => 'categorisation_rules',
                'columns' => ['nominal_account_id'],
                'where' => 'nominal_account_id = %s',
            ],
            [
                'table' => 'expense_claim_lines',
                'columns' => ['nominal_account_id'],
                'where' => 'nominal_account_id = %s',
            ],
            [
                'table' => 'journal_lines',
                'columns' => ['nominal_account_id'],
                'where' => 'nominal_account_id = %s',
            ],
            [
                'table' => 'corporation_tax_treatment_rules',
                'columns' => ['nominal_account_id'],
                'where' => 'nominal_account_id = %s',
            ],
            [
                'table' => 'transaction_category_audit',
                'columns' => ['old_nominal_account_id', 'new_nominal_account_id'],
                'where' => 'old_nominal_account_id = %s OR new_nominal_account_id = %s',
            ],
            [
                'table' => 'transactions',
                'columns' => ['nominal_account_id'],
                'where' => 'nominal_account_id = %s',
            ],
            [
                'table' => 'asset_register',
                'columns' => ['nominal_account_id', 'accum_dep_nominal_id'],
                'where' => 'nominal_account_id = %s OR accum_dep_nominal_id = %s',
            ],
            [
                'table' => 'company_settings',
                'columns' => ['setting', 'value'],
                'where' => $this->companySettingsNominalReferenceWhereSql('%s'),
            ],
        ];
    }

    private function nominalReferenceWhereSql(array $source, string $nominalExpression): string
    {
        $where = (string)($source['where'] ?? '');
        $placeholderCount = substr_count($where, '%s');

        if ($where === '' || $placeholderCount < 1) {
            throw new RuntimeException('Nominal reference source is invalid.');
        }

        return vsprintf($where, array_fill(0, $placeholderCount, $nominalExpression));
    }

    private function companySettingsNominalReferenceWhereSql(string $nominalExpression): string
    {
        $quotedSettings = array_map(
            static fn(string $setting): string => "'" . str_replace("'", "''", $setting) . "'",
            $this->nominalSettingKeys()
        );

        return 'setting IN (' . implode(', ', $quotedSettings) . ')
                AND TRIM(COALESCE(value, \'\')) = CAST(' . $nominalExpression . ' AS CHAR)';
    }

    private function deleteNominalAccountIfUnusedSql(array $referenceSources): string
    {
        $guards = [];
        foreach ($referenceSources as $source) {
            $guards[] = 'AND NOT EXISTS (SELECT 1 FROM '
                . $source['table']
                . ' WHERE '
                . $this->nominalReferenceWhereSql($source, 'nominal_accounts.id')
                . ')';
        }

        return 'DELETE FROM nominal_accounts
                WHERE id = :nominal_id
                  ' . implode("\n                  ", $guards);
    }

    private function nominalSettingKeys(): array
    {
        return [
            'default_bank_nominal_id',
            'default_trade_nominal_id',
            'default_expense_nominal_id',
            'director_loan_nominal_id',
            'vat_nominal_id',
            'uncategorised_nominal_id',
        ];
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
