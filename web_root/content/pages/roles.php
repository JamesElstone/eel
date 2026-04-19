<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _roles extends BasePageFramework
{
    public function id(): string
    {
        return 'roles';
    }

    public function title(): string
    {
        return 'Roles';
    }

    public function subtitle(): string
    {
        return 'Define role-based access and keep editing permissions organised in one place.';
    }

    public function showsTaxYearSelector(): bool
    {
        return false;
    }

    public function services(): array
    {
        return [];
    }

    public function cards(): array
    {
        return ['role_assignment'];
    }

    protected function handlePageAction(
        RequestFramework $request,
        PageServiceFramework $services
    ): ActionResultFramework {
        $roleAssignmentService = new RoleAssignmentService();
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $currentUserId = $this->currentUserIdFromSession($sessionAuthenticationService);

        if ($currentUserId <= 0) {
            return $this->forbiddenResult('A signed-in administrator is required before managing roles.');
        }

        if (!$roleAssignmentService->isAdminUser($currentUserId)) {
            return $this->forbiddenResult('Only administrators can access role management.');
        }

        if (!$sessionAuthenticationService->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'Your security token expired. Please refresh the page and try again.',
                ]],
                []
            );
        }

        return match ($request->action()) {
            'roles-create-role' => $this->resultFromArray(
                $roleAssignmentService->createRole(
                    $currentUserId,
                    (string)$request->input('new_role_name', '')
                ),
                'Role created.',
                static fn(array $result): array => ['role_id' => (int)($result['role_id'] ?? 0)]
            ),
            'roles-set-card-permission' => $this->resultFromArray(
                $roleAssignmentService->setCardAllowedForRole(
                    $currentUserId,
                    (int)$request->input('role_id', 0),
                    (string)$request->input('card_key', ''),
                    (string)$request->input('permission_state', 'denied') === 'allowed'
                ),
                'Update '
                    . $roleAssignmentService->displayRoleName((int)$request->input('role_id', 0))
                    . ' role '
                    . ((string)$request->input('permission_state', 'denied') === 'allowed' ? 'Enabled' : 'Disabled')
                    . ' access to '
                    . $roleAssignmentService->displayCardLabel((string)$request->input('card_key', '')),
                static function (array $result) use ($request): array {
                    return ['role_id' => (int)$request->input('role_id', 0)];
                }
            ),
            default => ActionResultFramework::none(),
        };
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        $roleAssignmentService = new RoleAssignmentService();
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $currentUserId = $this->currentUserIdFromSession($sessionAuthenticationService);

        if ($currentUserId <= 0 || !$roleAssignmentService->isAdminUser($currentUserId)) {
            return [
                'page_id' => 'roles',
                'page_cards' => [],
                'csrf_token' => $sessionAuthenticationService->csrfToken(),
                'roles_dashboard' => [
                    'roles' => [],
                    'selected_role_id' => 0,
                    'matrix_rows' => [],
                ],
            ];
        }

        $selectedRoleId = (int)$request->input(
            'role_id',
            (int)($actionResult->query()['role_id'] ?? 0)
        );

        return [
            'page_id' => 'roles',
            'page_cards' => $this->cards(),
            'csrf_token' => $sessionAuthenticationService->csrfToken(),
            'roles_dashboard' => $roleAssignmentService->dashboardData($selectedRoleId),
        ];
    }

    private function currentUserIdFromSession(SessionAuthenticationService $sessionAuthenticationService): int
    {
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
    }

    private function forbiddenResult(string $message): ActionResultFramework
    {
        return new ActionResultFramework(
            false,
            ['page.context'],
            [[
                'type' => 'error',
                'message' => $message,
            ]],
            []
        );
    }

    private function resultFromArray(array $result, string $successMessage, ?callable $queryResolver = null): ActionResultFramework
    {
        $success = !empty($result['success']) || (!array_key_exists('success', $result) && ($result['errors'] ?? []) === []);
        $flashMessages = [];
        $query = $queryResolver !== null ? (array)$queryResolver($result) : [];

        if ($success) {
            $flashMessages[] = [
                'type' => 'success',
                'message' => $successMessage,
            ];
        } else {
            foreach ((array)($result['errors'] ?? ['The requested action could not be completed.']) as $error) {
                $flashMessages[] = [
                    'type' => 'error',
                    'message' => (string)$error,
                ];
            }
        }

        return new ActionResultFramework($success, ['page.context'], $flashMessages, $query);
    }
}
