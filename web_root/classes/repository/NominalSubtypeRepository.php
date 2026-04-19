<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class NominalSubtypeRepository
{
    public function fetchNominalSubtypes(): array
    {
        return InterfaceDB::fetchAll($this->fetchNominalSubtypesSql());
    }

    public function findByCode(string $code): ?array
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
    public function validateInput(array $input, ?int $ignoreId = null): array
    {
        $errors = [];
        $code = strtolower(trim((string)($input['code'] ?? '')));
        $name = trim((string)($input['name'] ?? ''));
        $parentType = trim((string)($input['parent_account_type'] ?? ''));
        $sortOrder = trim((string)($input['sort_order'] ?? '100'));

        if ($code === '' || preg_match('/^[a-z0-9_]+$/', $code) !== 1) {
            $errors[] = 'Subtype code is required and must use lowercase letters, numbers, or underscores.';
        }

        if ($name === '') {
            $errors[] = 'Subtype name is required.';
        }

        if (!in_array($parentType, $this->validAccountTypes(), true)) {
            $errors[] = 'Subtype parent account type is invalid.';
        }

        if ($sortOrder === '' || preg_match('/^-?\d+$/', $sortOrder) !== 1) {
            $errors[] = 'Subtype sort order must be a whole number.';
        }

        if ($errors === [] && $code !== '') {
            $sql = 'SELECT id FROM nominal_account_subtypes WHERE code = ?';
            $params = [$code];

            if ($ignoreId !== null) {
                $sql .= ' AND id <> ?';
                $params[] = $ignoreId;
            }

            $sql .= ' LIMIT 1';
            $stmt = InterfaceDB::prepareExecute($sql, $params);

            if ($stmt->fetch()) {
                $errors[] = 'Subtype code must be unique.';
            }
        }

        return $errors;
    }

    public function save(array $input, ?int $id = null): void
    {
        $payload = [
            strtolower(trim((string)$input['code'])),
            trim((string)$input['name']),
            trim((string)$input['parent_account_type']),
            (int)$input['sort_order'],
            (int)$input['is_active'],
        ];

        if ($id === null) {
            InterfaceDB::prepareExecute(
                'INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?)',
                $payload
            );
            return;
        }

        InterfaceDB::prepareExecute(
            'UPDATE nominal_account_subtypes
             SET code = ?, name = ?, parent_account_type = ?, sort_order = ?, is_active = ?
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

    private function fetchNominalSubtypesSql(): string
    {
        return 'SELECT id, code, name, parent_account_type, sort_order, is_active
                FROM nominal_account_subtypes
                ORDER BY sort_order, name, id';
    }
}
