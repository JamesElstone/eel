<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class UserManagementService
{
    private readonly UserAuthenticationService $userAuthenticationService;
    private readonly RoleAssignmentService $roleAssignmentService;
    private readonly OtpService $otpService;
    private readonly QrCodeService $qrCodeService;
    private readonly UserHistoryStore $userHistoryStore;
    private readonly UserSessionService $userSessionService;

    public function __construct(
        ?UserAuthenticationService $userAuthenticationService = null,
        ?RoleAssignmentService $roleAssignmentService = null,
        ?OtpService $otpService = null,
        ?QrCodeService $qrCodeService = null,
        ?UserHistoryStore $userHistoryStore = null,
        ?UserSessionService $userSessionService = null,
    ) {
        global $appName;

        $this->userAuthenticationService = $userAuthenticationService ?? new UserAuthenticationService();
        $this->roleAssignmentService = $roleAssignmentService ?? new RoleAssignmentService();
        $this->otpService = $otpService ?? new OtpService((string)($appName ?? 'eelKit Framework'));
        $this->qrCodeService = $qrCodeService ?? new QrCodeService();
        $this->userHistoryStore = $userHistoryStore ?? new UserHistoryStore();
        $this->userSessionService = $userSessionService ?? new UserSessionService();
    }

    public function dashboardData(int $currentUserId): array
    {
        $currentUser = $this->userAuthenticationService->userById($currentUserId);
        if ($currentUser === null) {
            throw new RuntimeException('The current user could not be resolved.');
        }

        $canManageUsers = $this->canManageUsers($currentUserId);

        return [
            'current_user' => $currentUser,
            'can_manage_users' => $canManageUsers,
            'current_user_otp' => [
                'has_secret' => $this->otpService->hasOTPsecret($currentUserId),
                'is_enabled' => $this->otpService->isOTPenabled($currentUserId),
                'has_pending' => $this->otpService->hasPendingOtpSecret($currentUserId),
            ],
            'logon_history' => $this->userHistoryStore->fetchLogonHistoryForUser($currentUserId, 100),
            'current_users' => $canManageUsers ? $this->userAuthenticationService->listUsers() : [],
            'roles' => $canManageUsers ? $this->roleAssignmentService->listRolesForSelect() : [],
            'otp_setup' => $this->pendingOtpSetupData($currentUserId),
        ];
    }

    public function currentUserDetails(int $currentUserId = 0): array
    {
        $currentUserId = $this->resolveCurrentUserId($currentUserId);
        if ($currentUserId <= 0) {
            return [];
        }

        $currentUser = $this->userAuthenticationService->userById($currentUserId);

        return $currentUser ?? [];
    }

    public function currentUserOtpDashboard(int $currentUserId = 0): array
    {
        $currentUserId = $this->resolveCurrentUserId($currentUserId);
        if ($currentUserId <= 0) {
            return [
                'current_user_otp' => [
                    'has_secret' => false,
                    'is_enabled' => false,
                    'has_pending' => false,
                ],
                'otp_setup' => [
                    'has_pending' => false,
                    'qr_svg' => '',
                    'otpauth_uri' => '',
                    'manual_secret' => '',
                ],
            ];
        }

        return [
            'current_user_otp' => [
                'has_secret' => $this->otpService->hasOTPsecret($currentUserId),
                'is_enabled' => $this->otpService->isOTPenabled($currentUserId),
                'has_pending' => $this->otpService->hasPendingOtpSecret($currentUserId),
            ],
            'otp_setup' => $this->pendingOtpSetupData($currentUserId),
        ];
    }

    public function currentUsersDashboard(int $currentUserId = 0): array
    {
        $currentUserId = $this->resolveCurrentUserId($currentUserId);
        if ($currentUserId <= 0 || !$this->canManageUsers($currentUserId)) {
            return [
                'current_user' => [],
                'current_users' => [],
                'roles' => [],
            ];
        }

        return [
            'current_user' => $this->currentUserDetails($currentUserId),
            'current_users' => $this->userAuthenticationService->listUsers(),
            'roles' => $this->roleAssignmentService->listRolesForSelect(),
        ];
    }

    public function loginLockoutsDashboard(int $currentUserId = 0): array
    {
        $currentUserId = $this->resolveCurrentUserId($currentUserId);
        if ($currentUserId <= 0 || !$this->canManageUsers($currentUserId)) {
            return [
                'current_user' => [],
                'locked_users' => [],
            ];
        }

        return [
            'current_user' => $this->currentUserDetails($currentUserId),
            'locked_users' => $this->userAuthenticationService->listLockedOutUsers(),
        ];
    }

    public function passwordPolicyDescription(): string
    {
        return UserAuthenticationService::passwordPolicyDescription();
    }

    public function updateCurrentUser(
        int $actorUserId,
        string $displayName,
        string $emailAddress,
        string $currentPassword,
        string $newPassword
    ): array {
        $user = $this->userAuthenticationService->userById($actorUserId);
        if ($user === null) {
            return ['success' => false, 'errors' => ['Your user record could not be found.']];
        }

        $displayName = trim($displayName);
        $emailAddress = trim($emailAddress);
        $currentPassword = (string)$currentPassword;
        $newPassword = (string)$newPassword;

        $existingDisplayName = (string)($user['display_name'] ?? '');
        $existingEmailAddress = (string)($user['email_address'] ?? '');
        $detailsChanged = $existingDisplayName !== $displayName
            || strtolower($existingEmailAddress) !== strtolower($emailAddress);
        $passwordChanged = trim($newPassword) !== '';

        if ($detailsChanged || $passwordChanged) {
            if ($currentPassword === '') {
                return ['success' => false, 'errors' => ['Current password is required to update your account details.']];
            }

            if (!$this->userAuthenticationService->authenticateByUserId($actorUserId, $currentPassword)) {
                return ['success' => false, 'errors' => ['The current password entered is not correct.']];
            }
        }

        $passwordToSet = $passwordChanged ? $newPassword : null;
        $result = $this->userAuthenticationService->updateUser(
            $actorUserId,
            $displayName,
            $emailAddress,
            $passwordToSet
        );

        if (empty($result['success'])) {
            return $result;
        }

        $metadata = $this->userSessionService->buildRequestMetadata();

        if ($existingDisplayName !== trim($displayName)) {
            $this->userHistoryStore->recordAccountAudit(
                $actorUserId,
                $actorUserId,
                'display_name_changed',
                'The user updated their display name.',
                ['old_display_name' => $existingDisplayName, 'new_display_name' => trim($displayName)],
                $metadata
            );
        }

        if (strtolower($existingEmailAddress) !== strtolower(trim($emailAddress))) {
            $this->userHistoryStore->recordAccountAudit(
                $actorUserId,
                $actorUserId,
                'email_changed',
                'The user updated their email address.',
                ['old_email_address' => $existingEmailAddress, 'new_email_address' => strtolower(trim($emailAddress))],
                $metadata
            );
        }

        if ($passwordToSet !== null) {
            $this->userHistoryStore->recordAccountAudit(
                $actorUserId,
                $actorUserId,
                'password_changed_self',
                'The user changed their own password.',
                [],
                $metadata
            );
        }

        return $result;
    }

    public function createUser(int $actorUserId, string $displayName, string $emailAddress, string $password): array
    {
        $authorisationError = $this->authoriseUserManagementActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        $result = $this->userAuthenticationService->createUser($displayName, $emailAddress, $password, true);

        if (!empty($result['success']) && (int)($result['user_id'] ?? 0) > 0) {
            $this->userHistoryStore->recordAccountAudit(
                (int)$result['user_id'],
                $actorUserId,
                'user_created',
                'An administrator created a new user account.',
                ['email_address' => strtolower(trim($emailAddress)), 'display_name' => trim($displayName)],
                $this->userSessionService->buildRequestMetadata()
            );
        }

        return $result;
    }

    public function setUserEnabled(int $actorUserId, int $targetUserId, bool $isEnabled): array
    {
        $authorisationError = $this->authoriseUserManagementActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        if ($actorUserId > 0 && $actorUserId === $targetUserId && !$isEnabled) {
            return [
                'success' => false,
                'errors' => ['You cannot disable the account you are currently signed in with.'],
            ];
        }

        $result = $this->userAuthenticationService->setUserActive($targetUserId, $isEnabled);

        if (!empty($result['success'])) {
            $this->userHistoryStore->recordAccountAudit(
                $targetUserId,
                $actorUserId,
                $isEnabled ? 'user_enabled' : 'user_disabled',
                $isEnabled ? 'An administrator enabled this user.' : 'An administrator disabled this user.',
                [],
                $this->userSessionService->buildRequestMetadata()
            );
        }

        return $result;
    }

    public function setPasswordForUser(int $actorUserId, int $targetUserId, string $password): array
    {
        $authorisationError = $this->authoriseUserManagementActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        if ($actorUserId > 0 && $actorUserId === $targetUserId) {
            return [
                'success' => false,
                'errors' => ['Use your own account settings to change your password.'],
            ];
        }

        $result = $this->userAuthenticationService->setPasswordDirectly($targetUserId, $password);

        if (!empty($result['success'])) {
            $requireChangeResult = $this->userAuthenticationService->requirePasswordChange($targetUserId);
            if (empty($requireChangeResult['success'])) {
                return $requireChangeResult;
            }

            $clearedRows = $this->userAuthenticationService->clearLoginRateLimitForUser($targetUserId);
            $this->userHistoryStore->recordAccountAudit(
                $targetUserId,
                $actorUserId,
                'password_set_admin',
                'An administrator set a new password for this user.',
                [
                    'cleared_rate_limit_rows' => $clearedRows,
                    'must_change_password' => true,
                ],
                $this->userSessionService->buildRequestMetadata()
            );
            $result['cleared_rate_limit_rows'] = $clearedRows;
            $result['must_change_password'] = true;
        }

        return $result;
    }

    public function requirePasswordChangeForUser(int $actorUserId, int $targetUserId): array
    {
        $authorisationError = $this->authoriseUserManagementActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        $result = $this->userAuthenticationService->requirePasswordChange($targetUserId);

        if (!empty($result['success'])) {
            $this->userHistoryStore->recordAccountAudit(
                $targetUserId,
                $actorUserId,
                'password_change_required_admin',
                'An administrator required this user to change their password at next sign-in.',
                [],
                $this->userSessionService->buildRequestMetadata()
            );
        }

        return $result;
    }

    public function setUserOtpRequired(int $actorUserId, int $targetUserId, bool $otpRequired): array
    {
        $authorisationError = $this->authoriseUserManagementActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        $targetUser = $this->userAuthenticationService->userById($targetUserId);
        $previousOtpRequired = (int)($targetUser['otp_required'] ?? 1) === 1;
        $result = $this->userAuthenticationService->setOtpRequired($targetUserId, $otpRequired);

        if (!empty($result['success']) && $previousOtpRequired !== $otpRequired) {
            $this->userHistoryStore->recordAccountAudit(
                $targetUserId,
                $actorUserId,
                'otp_requirement_changed',
                $otpRequired
                    ? 'An administrator required OTP setup for this user.'
                    : 'An administrator allowed this user to skip OTP setup.',
                ['otp_required' => $otpRequired],
                $this->userSessionService->buildRequestMetadata()
            );
        }

        return $result;
    }

    public function resetUserOtp(int $actorUserId, int $targetUserId): array
    {
        $authorisationError = $this->authoriseUserManagementActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        $reset = $this->otpService->resetOtp($targetUserId);

        if (!$reset) {
            return ['success' => false, 'errors' => ['No OTP record existed for that user.']];
        }

        $this->userHistoryStore->recordAccountAudit(
            $targetUserId,
            $actorUserId,
            'otp_reset_admin',
            'An administrator reset this user OTP configuration.',
            [],
            $this->userSessionService->buildRequestMetadata()
        );

        return ['success' => true, 'errors' => []];
    }

    public function resetUserLoginLockout(int $actorUserId, int $targetUserId): array
    {
        $authorisationError = $this->authoriseUserManagementActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        $targetUser = $this->userAuthenticationService->userById($targetUserId);
        if ($targetUser === null) {
            return ['success' => false, 'errors' => ['The selected user could not be found.']];
        }

        $clearedRows = $this->userAuthenticationService->clearLoginRateLimitForUser($targetUserId);

        $this->userHistoryStore->recordAccountAudit(
            $targetUserId,
            $actorUserId,
            'login_lockout_reset_admin',
            'An administrator reset this user login lockout.',
            ['cleared_rate_limit_rows' => $clearedRows],
            $this->userSessionService->buildRequestMetadata()
        );

        return ['success' => true, 'errors' => [], 'cleared_rate_limit_rows' => $clearedRows];
    }

    public function assignRoleToUser(int $actorUserId, int $targetUserId, int $roleId): array
    {
        $authorisationError = $this->authoriseUserManagementActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        return $this->roleAssignmentService->assignRoleToUser($actorUserId, $targetUserId, $roleId);
    }

    public function canManageUsers(int $actorUserId): bool
    {
        if ($actorUserId <= 0) {
            return false;
        }

        if ($this->roleAssignmentService->isAdminUser($actorUserId)) {
            return true;
        }

        $cardAccess = new CardAccessFramework();

        return in_array(
            'current_users',
            $cardAccess->allowedCardsForUser($actorUserId, ['current_users']),
            true
        );
    }

    public function beginOtpRotation(int $actorUserId): array
    {
        $this->otpService->beginPendingOtpEnrollment($actorUserId);
        $this->userHistoryStore->recordAccountAudit(
            $actorUserId,
            $actorUserId,
            'otp_rotation_started',
            'The user started rotating their OTP secret.',
            [],
            $this->userSessionService->buildRequestMetadata()
        );

        return $this->pendingOtpSetupData($actorUserId);
    }

    public function completeOtpRotation(int $actorUserId, string $code): array
    {
        if (!$this->otpService->completePendingOtpEnrollment($actorUserId, $code)) {
            return [
                'success' => false,
                'errors' => ['The OTP code did not match the pending QR setup.'],
            ];
        }

        $this->userHistoryStore->recordAccountAudit(
            $actorUserId,
            $actorUserId,
            'otp_rotation_completed',
            'The user completed rotating their OTP secret.',
            [],
            $this->userSessionService->buildRequestMetadata()
        );
        $user = $this->userAuthenticationService->userById($actorUserId);
        $this->userHistoryStore->recordLogonEvent(
            $actorUserId,
            (string)($user['email_address'] ?? ''),
            'otp_setup_completed',
            true,
            'A new OTP secret was confirmed for this account.',
            null,
            $this->userSessionService->buildRequestMetadata()
        );

        return [
            'success' => true,
            'errors' => [],
        ];
    }

    private function pendingOtpSetupData(int $userId): array
    {
        if (!$this->otpService->hasPendingOtpSecret($userId)) {
            return [
                'has_pending' => false,
                'qr_svg' => '',
                'otpauth_uri' => '',
                'manual_secret' => '',
            ];
        }

        $otpauthUri = $this->otpService->generatePendingOtpString($userId);

        return [
            'has_pending' => true,
            'qr_svg' => $this->qrCodeService->generateSvg($otpauthUri, [
                'error_correction_level' => 'auto',
                'module_size' => 'auto',
            ]),
            'otpauth_uri' => $otpauthUri,
            'manual_secret' => $this->otpService->pendingManualEntrySecret($userId),
        ];
    }

    private function authoriseUserManagementActor(int $actorUserId): ?string
    {
        if ($actorUserId <= 0) {
            return 'A signed-in user is required before changing user settings.';
        }

        if (!$this->canManageUsers($actorUserId)) {
            return 'You do not have permission to manage users.';
        }

        return null;
    }

    private function resolveCurrentUserId(int $currentUserId): int
    {
        if ($currentUserId > 0) {
            return $currentUserId;
        }

        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
    }
}
