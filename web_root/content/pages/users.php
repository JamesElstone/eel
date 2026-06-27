<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _users extends PageContextFramework
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
            'current_users',
            'user_login_lockouts',
            'invited_users',
            'invite_user',
            'add_user',
            'user_logon_history_log',
            'current_user_details',
            'set_new_otp_secret',
            'restore_deleted_user',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'System Users',
                'cards' => [
                    'current_users',
                    'user_login_lockouts',
                    'invited_users',
                    'invite_user',
                    'add_user',
                    'user_logon_history_log',
                ],
            ],
            [
                'tab' => 'Your Account',
                'layout' => 'split',
                'cards' => [
                    'current_user_details',
                    'set_new_otp_secret',
                    'restore_deleted_user',
                ],
            ],
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
                    (string)$request->input('new_password', ''),
                    (string)$request->input('mobile_country_code', UserManagementService::defaultMobileCountryCode()),
                    (string)$request->input('mobile_number', '')
                ),
                'Current user details updated.',
                ['current.user.details', 'current.users', 'layout.sidebar']
            ),
            'users-begin-otp-rotation' => $this->resultFromArray(
                $userManagementService->beginOtpRotation($currentUserId),
                'OTP rotation started. Scan the new QR code and confirm it below.',
                ['set.new.otp.secret']
            ),
            'users-complete-otp-rotation' => $this->resultFromArray(
                $userManagementService->completeOtpRotation(
                    $currentUserId,
                    (string)$request->input('otp_code', '')
                ),
                'A new OTP secret is now active for your account.',
                ['set.new.otp.secret', 'current.user.details']
            ),
            'users-create-user' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->createUser(
                        $currentUserId,
                        (string)$request->input('new_display_name', ''),
                        (string)$request->input('new_email_address', ''),
                        (string)$request->input('new_password', ''),
                        (string)$request->input('new_mobile_country_code', UserManagementService::defaultMobileCountryCode()),
                        (string)$request->input('new_mobile_number', '')
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'New user created successfully.',
                ['current.users', 'add.user']
            ),
            'users-create-invited-user' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->createInvitedUserAndSendInvites(
                        $currentUserId,
                        (string)$request->input('invite_display_name', ''),
                        (string)$request->input('invite_email_address', ''),
                        (string)$request->input('invite_mobile_country_code', UserManagementService::defaultMobileCountryCode()),
                        (string)$request->input('invite_mobile_number', ''),
                        (int)$request->input('invite_role_id', 0),
                        (new AccountInviteService())->buildBaseUrl($request)
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'Pending invited user created and invitation sent.',
                ['current.users', 'invited.users', 'invite.user']
            ),
            'users-update-invited-user' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->updateInvitedUser(
                        $currentUserId,
                        max(0, (int)$request->input('target_user_id', 0)),
                        (string)$request->input('invite_display_name', ''),
                        (string)$request->input('invite_email_address', ''),
                        (string)$request->input('invite_mobile_country_code', UserManagementService::defaultMobileCountryCode()),
                        (string)$request->input('invite_mobile_number', ''),
                        (int)$request->input('invite_role_id', 0)
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'Pending invited user updated.',
                ['current.users', 'invited.users']
            ),
            'users-copy-invite-link' => $this->resultFromInviteArray(
                $canManageUsers
                    ? $userManagementService->createInviteLinkForUser(
                        $currentUserId,
                        max(0, (int)$request->input('target_user_id', 0)),
                        (string)$request->input('contact_method', 'auto'),
                        (new AccountInviteService())->buildBaseUrl($request)
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'Invitation link generated.',
                ['current.users', 'invited.users']
            ),
            'users-send-invite' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->sendInviteForUser(
                        $currentUserId,
                        max(0, (int)$request->input('target_user_id', 0)),
                        (string)$request->input('contact_method', 'email'),
                        (new AccountInviteService())->buildBaseUrl($request)
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'Invitation sent.',
                ['current.users', 'invited.users']
            ),
            'users-revoke-invite' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->revokeInvite(
                        $currentUserId,
                        max(0, (int)$request->input('invite_id', 0))
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'Invitation cancelled.',
                ['current.users', 'invited.users']
            ),
            'users-restore-deleted-user' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->restoreArchivedUserAndSendInvites(
                        $currentUserId,
                        max(0, (int)$request->input('target_user_id', 0)),
                        (new AccountInviteService())->buildBaseUrl($request)
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'Deleted user restored and invitation sent.',
                ['current.users', 'invited.users', 'restore.deleted.user']
            ),
            'users-toggle-user' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->setUserEnabled(
                        $currentUserId,
                        max(0, (int)$request->input('target_user_id', 0)),
                        (string)$request->input('target_state', '0') === '1'
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'User status updated.',
                ['current.users']
            ),
            'users-reset-otp' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->resetUserOtp(
                        $currentUserId,
                        max(0, (int)$request->input('target_user_id', 0))
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'OTP reset. The user will be required to enroll OTP again on next sign-in.',
                ['current.users']
            ),
            'users-reset-login-lockout' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->resetUserLoginLockout(
                        $currentUserId,
                        max(0, (int)$request->input('target_user_id', 0))
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'Login lockout reset. The user can try signing in again.',
                ['current.users', 'user.login.lockouts', 'user.logon.history.log']
            ),
            'users-require-password-change' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->requirePasswordChangeForUser(
                        $currentUserId,
                        max(0, (int)$request->input('target_user_id', 0))
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'Password change required. The user will be prompted after their next successful password check.',
                ['current.users']
            ),
            'users-set-otp-required' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->setUserOtpRequired(
                        $currentUserId,
                        max(0, (int)$request->input('target_user_id', 0)),
                        (string)$request->input('otp_required', '1') === '1'
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'OTP requirement updated.',
                ['current.users']
            ),
            'users-set-password' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->setPasswordForUser(
                        $currentUserId,
                        max(0, (int)$request->input('target_user_id', 0)),
                        (string)$request->input('target_password', '')
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'Password updated for the selected user.',
                ['current.users']
            ),
            'users-set-role' => $this->resultFromArray(
                $canManageUsers
                    ? $userManagementService->assignRoleToUser(
                        $currentUserId,
                        max(0, (int)$request->input('target_user_id', 0)),
                        (int)$request->input('target_role_id', 0)
                    )
                    : ['success' => false, 'errors' => ['You do not have permission to manage users.']],
                'User role updated.',
                ['current.users']
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

        return [
            'page' => [
                'page_id' => 'users',
                'page_cards' => $this->cards(),
                'csrf_token' => $sessionAuthenticationService->csrfToken(),
            ],
        ];
    }

    private function currentUserIdFromSession(SessionAuthenticationService $sessionAuthenticationService): int
    {
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        return $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
    }

    private function resultFromArray(array $result, string $successMessage, array $changedFacts): ActionResultFramework
    {
        $success = !empty($result['success']) || (!array_key_exists('success', $result) && ($result['errors'] ?? []) === []);
        $flashMessages = [];

        if ($success) {
            $flashMessages[] = [
                'type' => 'success',
                'message' => $successMessage,
            ];
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

    private function resultFromInviteArray(array $result, string $successMessage, array $changedFacts): ActionResultFramework
    {
        if (empty($result['success'])) {
            return $this->resultFromArray($result, $successMessage, $changedFacts);
        }

        $link = trim((string)($result['link'] ?? ''));
        $message = $successMessage;
        $messageHtml = HelperFramework::escape($successMessage);

        if ($link !== '') {
            $message = $successMessage . ' Copy this link: ' . $link;
            $messageHtml = HelperFramework::escape($successMessage) . '<br><code>' . HelperFramework::escape($link) . '</code>';
        }

        return new ActionResultFramework(
            true,
            $changedFacts,
            [[
                'type' => 'success',
                'message' => $message,
                'message_html' => $messageHtml,
            ]],
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
