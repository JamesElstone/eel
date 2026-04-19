<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class UserManagementService
{
    public function __construct(
        private readonly UserAuthenticationService $userAuthenticationService = new UserAuthenticationService(),
        private readonly RoleAssignmentService $roleAssignmentService = new RoleAssignmentService(),
        private readonly OtpService $otpService = new OtpService('EEL Accounts'),
        private readonly QrCodeService $qrCodeService = new QrCodeService(),
        private readonly UserHistoryStore $userHistoryStore = new UserHistoryStore(),
        private readonly UserSessionService $userSessionService = new UserSessionService(),
    ) {
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

        $result = $this->userAuthenticationService->createUser($displayName, $emailAddress, $password, false, true);

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
            $this->userHistoryStore->recordAccountAudit(
                $targetUserId,
                $actorUserId,
                'password_set_admin',
                'An administrator set a new password for this user.',
                [],
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
}
