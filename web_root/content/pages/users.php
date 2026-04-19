<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _users extends BasePageFramework
{
    public function id(): string
    {
        return 'users';
    }

    public function title(): string
    {
        return 'Users';
    }

    public function subtitle(): string
    {
        return 'Review access history, manage user accounts, and maintain OTP security in one place.';
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
        return [
            'current_user_details',
            'set_new_otp_secret',
            'account_logon_history',
        ];
    }

    protected function handlePageAction(
        RequestFramework $request,
        PageServiceFramework $services
    ): ActionResultFramework {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();

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

        $userManagementService = new UserManagementService();
        $currentUserId = $this->currentUserIdFromSession($sessionAuthenticationService);

        if ($currentUserId <= 0) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'A signed-in user is required before changing user settings.',
                ]],
                []
            );
        }

        $canManageUsers = $userManagementService->canManageUsers($currentUserId);

        return match ($request->action()) {
            'users-update-current-user' => $this->resultFromArray(
                $userManagementService->updateCurrentUser(
                    $currentUserId,
                    (string)$request->input('display_name', ''),
                    (string)$request->input('email_address', ''),
                    (string)$request->input('current_password', ''),
                    (string)$request->input('new_password', '')
                ),
                'Current user details updated.'
            ),
            'users-begin-otp-rotation' => $this->resultFromArray(
                $userManagementService->beginOtpRotation($currentUserId),
                'OTP rotation started. Scan the new QR code and confirm it below.'
            ),
            'users-complete-otp-rotation' => $this->resultFromArray(
                $userManagementService->completeOtpRotation(
                    $currentUserId,
                    (string)$request->input('otp_code', '')
                ),
                'A new OTP secret is now active for your account.'
            ),
            'users-create-user' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->createUser(
                        $currentUserId,
                        (string)$request->input('new_display_name', ''),
                        (string)$request->input('new_email_address', ''),
                        (string)$request->input('new_password', '')
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'New user created successfully.'
            ),
            'users-toggle-user' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->setUserEnabled(
                        $currentUserId,
                        max(0, (int)$request->input('target_user_id', 0)),
                        (string)$request->input('target_state', '0') === '1'
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'User status updated.'
            ),
            'users-reset-otp' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->resetUserOtp(
                        $currentUserId,
                        max(0, (int)$request->input('target_user_id', 0))
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'OTP reset. The user will be required to enroll OTP again on next sign-in.'
            ),
            'users-set-password' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->setPasswordForUser(
                        $currentUserId,
                        max(0, (int)$request->input('target_user_id', 0)),
                        (string)$request->input('target_password', '')
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'Password updated for the selected user.'
            ),
            'users-set-role' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->assignRoleToUser(
                        $currentUserId,
                        max(0, (int)$request->input('target_user_id', 0)),
                        (int)$request->input('target_role_id', 0)
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'User role updated.'
            ),
            default => ActionResultFramework::none(),
        };
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $currentUserId = $this->currentUserIdFromSession($sessionAuthenticationService);
        $dashboardData = $currentUserId > 0
            ? (new UserManagementService())->dashboardData($currentUserId)
            : [
                'current_user' => [],
                'can_manage_users' => false,
                'current_user_otp' => [
                    'has_secret' => false,
                    'is_enabled' => false,
                    'has_pending' => false,
                ],
                'logon_history' => [],
                'current_users' => [],
                'otp_setup' => [
                    'has_pending' => false,
                    'qr_svg' => '',
                    'otpauth_uri' => '',
                    'manual_secret' => '',
                ],
            ];
        $pageCards = [
            'current_user_details',
            'set_new_otp_secret',
            'account_logon_history',
        ];

        if (!empty($dashboardData['can_manage_users'])) {
            $pageCards[] = 'add_user';
            $pageCards[] = 'current_users';
        }

        return [
            'page_id' => 'users',
            'page_cards' => $pageCards,
            'csrf_token' => $sessionAuthenticationService->csrfToken(),
            'users_dashboard' => $dashboardData,
        ];
    }

    private function currentUserIdFromSession(SessionAuthenticationService $sessionAuthenticationService): int
    {
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        return $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
    }

    private function resultFromArray(array $result, string $successMessage): ActionResultFramework
    {
        $success = !empty($result['success']) || (!array_key_exists('success', $result) && ($result['errors'] ?? []) === []);
        $flashMessages = [];
        $changedFacts = ['page.context'];

        if ($success) {
            $flashMessages[] = [
                'type' => 'success',
                'message' => $successMessage,
            ];

            if ($successMessage === 'Current user details updated.') {
                $changedFacts[] = 'layout.sidebar';
            }
        } else {
            foreach ($this->normaliseErrorFlashMessages((array)($result['errors'] ?? ['The requested action could not be completed.'])) as $message) {
                $flashMessages[] = $message;
            }
        }

        return new ActionResultFramework(
            $success,
            $changedFacts,
            $flashMessages,
            []
        );
    }

    private function normaliseErrorFlashMessages(array $errors): array
    {
        $messages = [];
        $passwordPolicyItems = [];

        foreach ($errors as $error) {
            $errorMessage = trim((string)$error);
            $passwordPolicyItem = $this->passwordPolicyListItem($errorMessage);

            if ($passwordPolicyItem !== null) {
                $passwordPolicyItems[] = $passwordPolicyItem;
                continue;
            }

            $messages[] = [
                'type' => 'error',
                'message' => $errorMessage,
            ];
        }

        if ($passwordPolicyItems !== []) {
            $listHtml = '';

            foreach ($passwordPolicyItems as $item) {
                $listHtml .= '<li>' . HelperFramework::escape($item) . '</li>';
            }

            array_unshift($messages, [
                'type' => 'error',
                'message_html' => 'Password must include at least:<ul>' . $listHtml . '</ul>',
            ]);
        }

        return $messages;
    }

    private function passwordPolicyListItem(string $errorMessage): ?string
    {
        $prefix = 'Password must include at least one ';

        if (str_starts_with($errorMessage, 'Password must be at least ') && str_ends_with($errorMessage, ' characters long.')) {
            return preg_replace('/^Password must be at least (\d+) characters long\.$/', '$1 characters', $errorMessage) ?: null;
        }

        if (str_starts_with($errorMessage, $prefix) && str_ends_with($errorMessage, '.')) {
            return substr($errorMessage, strlen($prefix), -1);
        }

        return null;
    }
}
