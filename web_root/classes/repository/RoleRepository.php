<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class RoleRepository
{
    public function listRoles(): array
    {
        return InterfaceDB::fetchAll(
            'SELECT r.id,
                    r.role_name,
                    COALESCE(u.assigned_user_count, 0) AS assigned_user_count,
                    COALESCE(rcp.allowed_card_count, 0) AS allowed_card_count
             FROM roles r
             LEFT JOIN (
                SELECT role_id,
                       COUNT(*) AS assigned_user_count
                FROM users
                GROUP BY role_id
             ) u
               ON u.role_id = r.id
             LEFT JOIN (
                SELECT role_id,
                       COUNT(*) AS allowed_card_count
                FROM role_card_permissions
                GROUP BY role_id
             ) rcp
               ON rcp.role_id = r.id
             ORDER BY r.role_name ASC, r.id ASC'
        );
    }

    public function roleById(int $roleId): ?array
    {
        if ($roleId <= 0) {
            return null;
        }

        $role = InterfaceDB::fetchOne(
            'SELECT id, role_name, created_at, updated_at
             FROM roles
             WHERE id = :id
             LIMIT 1',
            ['id' => $roleId]
        );

        return is_array($role) ? $role : null;
    }

    public function roleExistsByName(string $roleName): bool
    {
        return InterfaceDB::countWhere('roles', 'role_name', trim($roleName)) > 0;
    }

    public function createRole(string $roleName): int
    {
        InterfaceDB::prepareExecute(
            'INSERT INTO roles (
                role_name
            ) VALUES (
                :role_name
            )',
            ['role_name' => trim($roleName)]
        );

        $role = InterfaceDB::fetchOne(
            'SELECT id
             FROM roles
             WHERE role_name = :role_name
             ORDER BY id DESC
             LIMIT 1',
            ['role_name' => trim($roleName)]
        );

        return is_array($role) ? (int)($role['id'] ?? 0) : 0;
    }

    public function userRoleId(int $userId): int
    {
        $roleId = InterfaceDB::fetchColumn(
            'SELECT role_id
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $userId]
        );

        if ($roleId === false || $roleId === null || $roleId === '') {
            return 0;
        }

        return (int)$roleId;
    }

    public function assignRoleToUser(int $userId, int $roleId): void
    {
        InterfaceDB::prepareExecute(
            'UPDATE users
             SET role_id = :role_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'id' => $userId,
                'role_id' => $roleId,
            ]
        );

        UserAuthenticationService::forgetUserByIdCache($userId);
    }

    public function allowedCardKeysForRole(int $roleId): array
    {
        if ($roleId <= 0) {
            return [];
        }

        $keys = [];

        foreach (InterfaceDB::fetchAll(
            'SELECT card_key
             FROM role_card_permissions
             WHERE role_id = :role_id
             ORDER BY card_key ASC',
            ['role_id' => $roleId]
        ) as $row) {
            if (!is_array($row) || trim((string)($row['card_key'] ?? '')) === '') {
                continue;
            }

            $keys[] = HelperFramework::normaliseCardKey((string)$row['card_key']);
        }

        return array_values(array_unique($keys));
    }

    public function isCardAllowedForRole(int $roleId, string $cardKey): bool
    {
        return InterfaceDB::countWhere('role_card_permissions', [
            'role_id' => $roleId,
            'card_key' => HelperFramework::normaliseCardKey($cardKey),
        ]) > 0;
    }

    public function allowCardForRole(int $roleId, string $cardKey): void
    {
        if ($this->isCardAllowedForRole($roleId, $cardKey)) {
            return;
        }

        InterfaceDB::prepareExecute(
            'INSERT INTO role_card_permissions (
                role_id,
                card_key
            ) VALUES (
                :role_id,
                :card_key
            )',
            [
                'role_id' => $roleId,
                'card_key' => HelperFramework::normaliseCardKey($cardKey),
            ]
        );
    }

    public function denyCardForRole(int $roleId, string $cardKey): void
    {
        InterfaceDB::prepareExecute(
            'DELETE FROM role_card_permissions
             WHERE role_id = :role_id
               AND card_key = :card_key',
            [
                'role_id' => $roleId,
                'card_key' => HelperFramework::normaliseCardKey($cardKey),
            ]
        );
    }
}
