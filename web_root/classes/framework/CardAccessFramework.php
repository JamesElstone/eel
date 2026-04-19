<?php
declare(strict_types=1);

final class CardAccessFramework
{
    private const IMPLIED_CARD_ACCESS = [
        'current_users' => ['add_user'],
    ];

    public function __construct(
        private readonly RoleRepository $roleRepository = new RoleRepository(),
        private readonly UserAuthenticationService $userAuthenticationService = new UserAuthenticationService(),
    ) {
    }

    public function roleIdForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $user = $this->userAuthenticationService->userById($userId);

        return $user === null ? 0 : (int)($user['role_id'] ?? 0);
    }

    public function allowedCardsForUser(int $userId, array $cardKeys): array
    {
        return $this->allowedCardsForRole($this->roleIdForUser($userId), $cardKeys);
    }

    public function allowedCardsForRole(int $roleId, array $cardKeys): array
    {
        $normalised = [];

        foreach ($cardKeys as $cardKey) {
            $normalised[] = HelperFramework::normaliseCardKey((string)$cardKey);
        }

        $normalised = array_values(array_unique($normalised));

        if ($roleId === RoleAssignmentService::ADMIN_ROLE_ID) {
            return $normalised;
        }

        if ($roleId <= 0 || $normalised === []) {
            return [];
        }

        $allowedLookup = array_fill_keys($this->expandAllowedCardKeys($this->roleRepository->allowedCardKeysForRole($roleId)), true);

        return array_values(array_filter(
            $normalised,
            static fn(string $cardKey): bool => isset($allowedLookup[$cardKey])
        ));
    }

    private function expandAllowedCardKeys(array $cardKeys): array
    {
        $expanded = [];

        foreach ($cardKeys as $cardKey) {
            $normalisedKey = HelperFramework::normaliseCardKey((string)$cardKey);
            if ($normalisedKey === '') {
                continue;
            }

            $expanded[] = $normalisedKey;

            foreach (self::IMPLIED_CARD_ACCESS[$normalisedKey] ?? [] as $impliedKey) {
                $expanded[] = HelperFramework::normaliseCardKey((string)$impliedKey);
            }
        }

        return array_values(array_unique($expanded));
    }
}
