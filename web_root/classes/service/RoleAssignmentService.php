<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class RoleAssignmentService
{
    public const ADMIN_ROLE_ID = -1;
    public const ADMIN_ROLE_NAME = 'Admin';

    public function __construct(
        private readonly RoleRepository $roleRepository = new RoleRepository(),
        private readonly UserAuthenticationService $userAuthenticationService = new UserAuthenticationService(),
        private readonly UserHistoryStore $userHistoryStore = new UserHistoryStore(),
        private readonly UserSessionService $userSessionService = new UserSessionService(),
    ) {
    }

    public function listRolesForSelect(): array
    {
        $roles = [[
            'id' => self::ADMIN_ROLE_ID,
            'role_name' => self::ADMIN_ROLE_NAME,
            'assigned_user_count' => $this->adminAssignedUserCount(),
            'allowed_card_count' => count($this->allKnownCardKeys()),
        ]];

        foreach ($this->roleRepository->listRoles() as $role) {
            $roles[] = [
                'id' => (int)($role['id'] ?? 0),
                'role_name' => (string)($role['role_name'] ?? ''),
                'assigned_user_count' => (int)($role['assigned_user_count'] ?? 0),
                'allowed_card_count' => (int)($role['allowed_card_count'] ?? 0),
            ];
        }

        return $roles;
    }

    public function dashboardData(?int $selectedRoleId = null): array
    {
        $roles = $this->listRolesForSelect();
        $resolvedRoleId = $this->resolveSelectedRoleId($selectedRoleId, $roles);

        return [
            'roles' => $roles,
            'selected_role_id' => $resolvedRoleId,
            'matrix_rows' => $this->permissionMatrixForRole($resolvedRoleId),
        ];
    }

    public function createRole(int $actorUserId, string $roleName): array
    {
        $authorisationError = $this->authoriseAdminActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError], 'role_id' => 0];
        }

        $roleName = trim($roleName);

        if ($roleName === '') {
            return ['success' => false, 'errors' => ['Role name is required.'], 'role_id' => 0];
        }

        if ($this->isAdminRoleName($roleName)) {
            return ['success' => false, 'errors' => ['Admin is a built-in role and does not need to be created.'], 'role_id' => 0];
        }

        if ($this->roleRepository->roleExistsByName($roleName)) {
            return ['success' => false, 'errors' => ['A role with that name already exists.'], 'role_id' => 0];
        }

        $roleId = $this->roleRepository->createRole($roleName);

        if ($roleId <= 0) {
            return ['success' => false, 'errors' => ['The role could not be created.'], 'role_id' => 0];
        }

        return ['success' => true, 'errors' => [], 'role_id' => $roleId];
    }

    public function assignRoleToUser(int $actorUserId, int $targetUserId, int $roleId): array
    {
        $authorisationError = $this->authoriseAdminActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        if ($actorUserId > 0 && $actorUserId === $targetUserId) {
            return ['success' => false, 'errors' => ['You cannot change the role of the account you are currently signed in with.']];
        }

        $targetUser = $this->userAuthenticationService->userById($targetUserId);
        if ($targetUser === null) {
            return ['success' => false, 'errors' => ['The selected user could not be found.']];
        }

        if (!$this->roleExists($roleId)) {
            return ['success' => false, 'errors' => ['The selected role could not be found.']];
        }

        $currentRoleId = (int)($targetUser['role_id'] ?? self::ADMIN_ROLE_ID);
        if ($currentRoleId === $roleId) {
            return ['success' => true, 'errors' => []];
        }

        $this->roleRepository->assignRoleToUser($targetUserId, $roleId);
        $this->userHistoryStore->recordAccountAudit(
            $targetUserId,
            $actorUserId,
            'role_changed',
            'An administrator changed this user role.',
            [
                'old_role_id' => $currentRoleId,
                'new_role_id' => $roleId,
                'new_role_name' => $this->roleNameForId($roleId),
            ],
            $this->userSessionService->buildRequestMetadata()
        );

        return ['success' => true, 'errors' => []];
    }

    public function setCardAllowedForRole(int $actorUserId, int $roleId, string $cardKey, bool $allowed): array
    {
        $authorisationError = $this->authoriseAdminActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        if ($roleId === self::ADMIN_ROLE_ID) {
            return ['success' => false, 'errors' => ['Admin always has access to every card.']];
        }

        if (!$this->roleExists($roleId)) {
            return ['success' => false, 'errors' => ['The selected role could not be found.']];
        }

        $cardKey = HelperFramework::normaliseCardKey($cardKey);
        if (!in_array($cardKey, $this->allKnownCardKeys(), true)) {
            return ['success' => false, 'errors' => ['The selected card could not be found.']];
        }

        if ($allowed) {
            $this->roleRepository->allowCardForRole($roleId, $cardKey);
        } else {
            $this->roleRepository->denyCardForRole($roleId, $cardKey);
        }

        return ['success' => true, 'errors' => []];
    }

    public function permissionMatrixForRole(int $roleId): array
    {
        $allowedKeys = $roleId === self::ADMIN_ROLE_ID
            ? $this->allKnownCardKeys()
            : $this->roleRepository->allowedCardKeysForRole($roleId);
        $allowedLookup = array_fill_keys($allowedKeys, true);
        $rows = [];

        foreach ($this->allKnownCardKeys() as $cardKey) {
            $rows[] = [
                'card_key' => $cardKey,
                'card_label' => $this->labelFromCardKey($cardKey),
                'is_allowed' => isset($allowedLookup[$cardKey]),
                'is_forced' => $roleId === self::ADMIN_ROLE_ID,
            ];
        }

        return $rows;
    }

    public function displayRoleName(int $roleId): string
    {
        if ($roleId <= 0 && $roleId !== self::ADMIN_ROLE_ID) {
            return 'No Role Assigned';
        }

        return HelperFramework::titleCase($this->roleNameForId($roleId), 'No Role Assigned');
    }

    public function displayCardLabel(string $cardKey): string
    {
        return $this->labelFromCardKey(HelperFramework::normaliseCardKey($cardKey));
    }

    public function allKnownCardKeys(): array
    {
        $files = glob(APP_CARDS . '*.php');
        if ($files === false) {
            return [];
        }

        $keys = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $keys[] = HelperFramework::normaliseCardKey((string)pathinfo($file, PATHINFO_FILENAME));
        }

        sort($keys);

        return array_values(array_unique($keys));
    }

    private function resolveSelectedRoleId(?int $selectedRoleId, array $roles): int
    {
        $candidate = $selectedRoleId ?? self::ADMIN_ROLE_ID;
        foreach ($roles as $role) {
            if ((int)($role['id'] ?? 0) === $candidate) {
                return $candidate;
            }
        }

        return count($roles) > 0 ? (int)($roles[0]['id'] ?? self::ADMIN_ROLE_ID) : self::ADMIN_ROLE_ID;
    }

    private function roleExists(int $roleId): bool
    {
        return $roleId === self::ADMIN_ROLE_ID || ($roleId > 0 && $this->roleRepository->roleById($roleId) !== null);
    }

    private function roleNameForId(int $roleId): string
    {
        if ($roleId === self::ADMIN_ROLE_ID) {
            return self::ADMIN_ROLE_NAME;
        }

        if ($roleId <= 0) {
            return 'No Role Assigned';
        }

        $role = $this->roleRepository->roleById($roleId);

        return trim((string)($role['role_name'] ?? ''));
    }

    private function adminAssignedUserCount(): int
    {
        return InterfaceDB::countWhere('users', 'role_id', self::ADMIN_ROLE_ID);
    }

    private function labelFromCardKey(string $cardKey): string
    {
        return HelperFramework::labelFromKey($cardKey, '_');
    }

    private function isAdminRoleName(string $roleName): bool
    {
        return strcasecmp(trim($roleName), self::ADMIN_ROLE_NAME) === 0;
    }

    public function isAdminUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $user = $this->userAuthenticationService->userById($userId);
        if ($user === null) {
            return false;
        }

        if (!array_key_exists('role_id', $user) || $user['role_id'] === null || $user['role_id'] === '') {
            return false;
        }

        return (int)$user['role_id'] === self::ADMIN_ROLE_ID;
    }

    private function authoriseAdminActor(int $actorUserId): ?string
    {
        if ($actorUserId <= 0) {
            return 'An authenticated administrator is required.';
        }

        if (!$this->isAdminUser($actorUserId)) {
            return 'Only administrators can manage roles.';
        }

        return null;
    }
}
