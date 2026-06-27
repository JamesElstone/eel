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
    private const DEFAULT_MOBILE_COUNTRY_CODE = MobileNumberService::DEFAULT_COUNTRY_CODE;

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

        $users = $this->userAuthenticationService->listUsers();
        $latestInvites = (new AccountInviteService())->latestInviteForUsers(array_map(
            static fn(array $user): int => (int)($user['id'] ?? 0),
            $users
        ));

        return [
            'current_user' => $this->currentUserDetails($currentUserId),
            'current_users' => $users,
            'roles' => $this->roleAssignmentService->listRolesForSelect(),
            'latest_invites' => $latestInvites,
        ];
    }

    public function invitedUsersDashboard(int $currentUserId = 0): array
    {
        $currentUserId = $this->resolveCurrentUserId($currentUserId);
        if ($currentUserId <= 0 || !$this->canManageUsers($currentUserId)) {
            return [
                'current_user' => [],
                'invites' => [],
            ];
        }

        return [
            'current_user' => $this->currentUserDetails($currentUserId),
            'invites' => (new AccountInviteService())->listInvites(),
        ];
    }

    public function restorableArchivedUsersDashboard(int $currentUserId = 0): array
    {
        $currentUserId = $this->resolveCurrentUserId($currentUserId);
        if ($currentUserId <= 0 || !$this->canManageUsers($currentUserId)) {
            return ['users' => []];
        }

        $users = InterfaceDB::fetchAll(
            'SELECT id,
                    display_name,
                    email_address,
                    mobile_number,
                    account_status,
                    is_active,
                    role_id
             FROM users
             WHERE account_status = :account_status
             ORDER BY display_name ASC, id ASC',
            ['account_status' => 'archived']
        );

        $restorableUsers = [];
        foreach ($users as $user) {
            $contactMethods = $this->validStoredInviteContactMethods($user);
            if ($contactMethods === []) {
                continue;
            }

            $displayName = trim((string)($user['display_name'] ?? ''));
            $displayName = $displayName !== '' ? $displayName : 'User #' . (int)($user['id'] ?? 0);
            $contactLabel = implode(' + ', array_map(
                static fn(string $method): string => $method === 'sms' ? 'mobile' : 'email',
                $contactMethods
            ));

            $user['contact_methods'] = $contactMethods;
            $user['contact_label'] = $contactLabel;
            $user['option_label'] = $displayName . ' (' . $contactLabel . ')';
            $restorableUsers[] = $user;
        }

        return ['users' => $restorableUsers];
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

    public static function mobileCountryCodeOptions(): array
    {
        return MobileNumberService::countryCodeOptions();
    }

    public static function defaultMobileCountryCode(): string
    {
        return self::DEFAULT_MOBILE_COUNTRY_CODE;
    }

    public static function normaliseMobileNumberFromParts(string $countryCode, string $mobileNumber): string
    {
        return MobileNumberService::normaliseFromParts($countryCode, $mobileNumber);
    }

    public static function mobileNumberParts(string $mobileNumber): array
    {
        return MobileNumberService::parts($mobileNumber);
    }

    public static function formattedMobileNumber(string $mobileNumber): string
    {
        return MobileNumberService::formatted($mobileNumber);
    }

    public function updateCurrentUser(
        int $actorUserId,
        string $displayName,
        string $emailAddress,
        string $currentPassword,
        string $newPassword,
        string $mobileCountryCode = self::DEFAULT_MOBILE_COUNTRY_CODE,
        string $mobileNumber = ''
    ): array {
        $user = $this->userAuthenticationService->userById($actorUserId);
        if ($user === null) {
            return ['success' => false, 'errors' => ['Your user record could not be found.']];
        }

        $displayName = trim($displayName);
        $emailAddress = trim($emailAddress);
        $currentPassword = (string)$currentPassword;
        $newPassword = (string)$newPassword;
        $normalisedMobileNumber = self::normaliseMobileNumberFromParts($mobileCountryCode, $mobileNumber);

        $existingDisplayName = (string)($user['display_name'] ?? '');
        $existingEmailAddress = (string)($user['email_address'] ?? '');
        $existingMobileNumber = (string)($user['mobile_number'] ?? '');
        $detailsChanged = $existingDisplayName !== $displayName
            || strtolower($existingEmailAddress) !== strtolower($emailAddress)
            || $existingMobileNumber !== $normalisedMobileNumber;
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
            $passwordToSet,
            null,
            $normalisedMobileNumber
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

        if ($existingMobileNumber !== $normalisedMobileNumber) {
            $this->userHistoryStore->recordAccountAudit(
                $actorUserId,
                $actorUserId,
                'mobile_number_changed',
                'The user updated their mobile number.',
                ['old_mobile_number' => $existingMobileNumber, 'new_mobile_number' => $normalisedMobileNumber],
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

    public function createUser(
        int $actorUserId,
        string $displayName,
        string $emailAddress,
        string $password,
        string $mobileCountryCode = self::DEFAULT_MOBILE_COUNTRY_CODE,
        string $mobileNumber = ''
    ): array
    {
        $authorisationError = $this->authoriseUserManagementActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        $normalisedMobileNumber = self::normaliseMobileNumberFromParts($mobileCountryCode, $mobileNumber);
        $result = $this->userAuthenticationService->createUser(
            $displayName,
            $emailAddress,
            $password,
            true,
            $normalisedMobileNumber
        );

        if (!empty($result['success']) && (int)($result['user_id'] ?? 0) > 0) {
            $this->userHistoryStore->recordAccountAudit(
                (int)$result['user_id'],
                $actorUserId,
                'user_created',
                'An administrator created a new user account.',
                [
                    'email_address' => strtolower(trim($emailAddress)),
                    'display_name' => trim($displayName),
                    'mobile_number' => $normalisedMobileNumber,
                ],
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

    public function createInvitedUser(
        int $actorUserId,
        string $displayName,
        string $emailAddress,
        string $mobileCountryCode = self::DEFAULT_MOBILE_COUNTRY_CODE,
        string $mobileNumber = '',
        int $roleId = 0
    ): array {
        $authorisationError = $this->authoriseUserManagementActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError], 'user_id' => 0];
        }

        $input = $this->normaliseInvitedUserInput($displayName, $emailAddress, $mobileCountryCode, $mobileNumber);
        $errors = $this->validateInvitedUserInput(0, $input);
        $roleId = $this->normaliseAssignableRoleId($roleId);

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors, 'user_id' => 0];
        }

        InterfaceDB::transaction(function () use ($input, $roleId): void {
            InterfaceDB::prepareExecute(
                'INSERT INTO users (
                    display_name,
                    email_address,
                    mobile_number,
                    password_hash,
                    is_active,
                    account_status,
                    role_id
                ) VALUES (
                    :display_name,
                    :email_address,
                    :mobile_number,
                    NULL,
                    0,
                    :account_status,
                    :role_id
                )',
                [
                    'display_name' => $input['display_name'],
                    'email_address' => $input['email_address'] !== '' ? $input['email_address'] : null,
                    'mobile_number' => $input['mobile_number'] !== '' ? $input['mobile_number'] : null,
                    'account_status' => 'pending_invitation',
                    'role_id' => $roleId,
                ]
            );
        });

        $userId = (int)InterfaceDB::fetchColumn('SELECT COALESCE(MAX(id), 0) FROM users');
        if ($userId <= 0) {
            return ['success' => false, 'errors' => ['The invited user was created but could not be resolved.'], 'user_id' => 0];
        }
        UserAuthenticationService::forgetUserByIdCache($userId);

        $this->userHistoryStore->recordAccountAudit(
            $userId,
            $actorUserId,
            'user_created',
            'An administrator created a pending invited user account.',
            [
                'email_address' => $input['email_address'],
                'display_name' => $input['display_name'],
                'mobile_number' => $input['mobile_number'],
                'account_status' => 'pending_invitation',
            ],
            $this->userSessionService->buildRequestMetadata()
        );

        return [
            'success' => true,
            'errors' => [],
            'user_id' => $userId,
            'user' => $this->userAuthenticationService->userById($userId),
        ];
    }

    public function updateInvitedUser(
        int $actorUserId,
        int $targetUserId,
        string $displayName,
        string $emailAddress,
        string $mobileCountryCode = self::DEFAULT_MOBILE_COUNTRY_CODE,
        string $mobileNumber = '',
        int $roleId = 0
    ): array {
        $authorisationError = $this->authoriseUserManagementActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        $targetUser = $this->userAuthenticationService->userById($targetUserId);
        if ($targetUser === null || (string)($targetUser['account_status'] ?? '') !== 'pending_invitation') {
            return ['success' => false, 'errors' => ['The selected pending invited user could not be found.']];
        }

        $input = $this->normaliseInvitedUserInput($displayName, $emailAddress, $mobileCountryCode, $mobileNumber);
        $errors = $this->validateInvitedUserInput($targetUserId, $input);
        $roleId = $this->normaliseAssignableRoleId($roleId);

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        InterfaceDB::prepareExecute(
            'UPDATE users
             SET display_name = :display_name,
                 email_address = :email_address,
                 mobile_number = :mobile_number,
                 role_id = :role_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND account_status = :account_status',
            [
                'id' => $targetUserId,
                'display_name' => $input['display_name'],
                'email_address' => $input['email_address'] !== '' ? $input['email_address'] : null,
                'mobile_number' => $input['mobile_number'] !== '' ? $input['mobile_number'] : null,
                'role_id' => $roleId,
                'account_status' => 'pending_invitation',
            ]
        );
        UserAuthenticationService::forgetUserByIdCache($targetUserId);

        $this->userHistoryStore->recordAccountAudit(
            $targetUserId,
            $actorUserId,
            'display_name_changed',
            'An administrator updated a pending invited user account.',
            [
                'email_address' => $input['email_address'],
                'display_name' => $input['display_name'],
                'mobile_number' => $input['mobile_number'],
                'account_status' => 'pending_invitation',
            ],
            $this->userSessionService->buildRequestMetadata()
        );

        return [
            'success' => true,
            'errors' => [],
            'user_id' => $targetUserId,
            'user' => $this->userAuthenticationService->userById($targetUserId),
        ];
    }

    public function createInvitedUserAndSendInvites(
        int $actorUserId,
        string $displayName,
        string $emailAddress,
        string $mobileCountryCode = self::DEFAULT_MOBILE_COUNTRY_CODE,
        string $mobileNumber = '',
        int $roleId = 0,
        string $baseUrl = ''
    ): array {
        $result = $this->createInvitedUser(
            $actorUserId,
            $displayName,
            $emailAddress,
            $mobileCountryCode,
            $mobileNumber,
            $roleId
        );

        if (empty($result['success'])) {
            return $result;
        }

        $userId = (int)($result['user_id'] ?? 0);
        $user = is_array($result['user'] ?? null) ? $result['user'] : [];
        $sendResults = [];
        $errors = [];

        if (trim((string)($user['email_address'] ?? '')) !== '') {
            $emailResult = $this->sendInviteForUser($actorUserId, $userId, 'email', $baseUrl);
            $sendResults['email'] = $emailResult;
            if (empty($emailResult['success'])) {
                foreach ((array)($emailResult['errors'] ?? ['The invite email could not be sent.']) as $error) {
                    $errors[] = 'Pending invited user was created, but ' . lcfirst((string)$error);
                }
            }
        }

        if (trim((string)($user['mobile_number'] ?? '')) !== '') {
            $smsResult = $this->sendInviteForUser($actorUserId, $userId, 'sms', $baseUrl);
            $sendResults['sms'] = $smsResult;
            if (empty($smsResult['success'])) {
                foreach ((array)($smsResult['errors'] ?? ['The invite SMS could not be sent.']) as $error) {
                    $errors[] = 'Pending invited user was created, but ' . lcfirst((string)$error);
                }
            }
        }

        if ($sendResults === []) {
            return [
                'success' => false,
                'errors' => ['At least one contact method is required.'],
                'user_id' => $userId,
                'user' => $user,
            ];
        }

        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
                'user_id' => $userId,
                'user' => $user,
                'invite_results' => $sendResults,
            ];
        }

        return array_replace($result, [
            'invite_results' => $sendResults,
            'sent_invite_count' => count($sendResults),
        ]);
    }

    public function restoreArchivedUserAndSendInvites(int $actorUserId, int $targetUserId, string $baseUrl = ''): array
    {
        $authorisationError = $this->authoriseUserManagementActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        $targetUser = $this->userAuthenticationService->userById($targetUserId);
        if ($targetUser === null || (string)($targetUser['account_status'] ?? '') !== 'archived') {
            return ['success' => false, 'errors' => ['The selected deleted user could not be found.']];
        }

        $contactMethods = $this->validStoredInviteContactMethods($targetUser);
        if ($contactMethods === []) {
            return ['success' => false, 'errors' => ['The selected deleted user does not have a valid email address or mobile number.']];
        }

        $statement = InterfaceDB::prepareExecute(
            'UPDATE users
             SET account_status = :pending_status,
                 is_active = 0,
                 password_hash = NULL,
                 must_change_password = 0,
                 account_completed_at = NULL,
                 current_session_token_hash = NULL,
                 current_session_started_at = NULL,
                 current_session_last_seen_at = NULL,
                 current_session_device_id = NULL,
                 current_session_ip_address = NULL,
                 current_session_user_agent = NULL,
                 current_session_browser_label = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND account_status = :archived_status',
            [
                'id' => $targetUserId,
                'pending_status' => 'pending_invitation',
                'archived_status' => 'archived',
            ]
        );

        if ($statement->rowCount() < 1) {
            UserAuthenticationService::forgetUserByIdCache($targetUserId);
            return ['success' => false, 'errors' => ['The selected deleted user could not be restored.']];
        }

        UserAuthenticationService::forgetUserByIdCache($targetUserId);

        $this->userHistoryStore->recordAccountAudit(
            $targetUserId,
            $actorUserId,
            'user_restored',
            'An administrator restored this deleted user to pending invitation.',
            [
                'account_status' => 'pending_invitation',
                'contact_methods' => $contactMethods,
            ],
            $this->userSessionService->buildRequestMetadata()
        );

        $sendResults = [];
        $errors = [];
        $failedChannels = [];

        foreach ($contactMethods as $contactMethod) {
            $sendResult = $this->sendInviteForUser($actorUserId, $targetUserId, $contactMethod, $baseUrl);
            $sendResults[$contactMethod] = $sendResult;

            if (empty($sendResult['success'])) {
                $failedChannels[] = $contactMethod;
                $defaultError = $contactMethod === 'sms'
                    ? 'The invite SMS could not be sent.'
                    : 'The invite email could not be sent.';
                foreach ((array)($sendResult['errors'] ?? [$defaultError]) as $error) {
                    $errors[] = 'Deleted user was restored, but ' . lcfirst((string)$error);
                }
            }
        }

        $restoredUser = $this->userAuthenticationService->userById($targetUserId);

        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
                'user_id' => $targetUserId,
                'user' => $restoredUser,
                'invite_results' => $sendResults,
                'failed_channels' => $failedChannels,
            ];
        }

        return [
            'success' => true,
            'errors' => [],
            'user_id' => $targetUserId,
            'user' => $restoredUser,
            'invite_results' => $sendResults,
            'sent_invite_count' => count($sendResults),
        ];
    }

    public function createInviteLinkForUser(int $actorUserId, int $targetUserId, string $contactMethod, string $baseUrl): array
    {
        $authorisationError = $this->authoriseUserManagementActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        return (new AccountInviteService())->createInviteLink($actorUserId, $targetUserId, $contactMethod, $baseUrl);
    }

    public function sendInviteForUser(int $actorUserId, int $targetUserId, string $contactMethod, string $baseUrl): array
    {
        $authorisationError = $this->authoriseUserManagementActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        $inviteService = new AccountInviteService();

        return strtolower(trim($contactMethod)) === 'sms'
            ? $inviteService->sendSmsInvite($actorUserId, $targetUserId, $baseUrl)
            : $inviteService->sendEmailInvite($actorUserId, $targetUserId, $baseUrl);
    }

    public function revokeInvite(int $actorUserId, int $inviteId): array
    {
        $authorisationError = $this->authoriseUserManagementActor($actorUserId);
        if ($authorisationError !== null) {
            return ['success' => false, 'errors' => [$authorisationError]];
        }

        return (new AccountInviteService())->revokeInvite($actorUserId, $inviteId);
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

    private function normaliseInvitedUserInput(string $displayName, string $emailAddress, string $mobileCountryCode, string $mobileNumber): array
    {
        return [
            'display_name' => trim($displayName),
            'email_address' => strtolower(trim($emailAddress)),
            'mobile_number' => self::normaliseMobileNumberFromParts($mobileCountryCode, $mobileNumber),
        ];
    }

    private function validateInvitedUserInput(int $userId, array $input): array
    {
        $errors = [];

        if ((string)$input['display_name'] === '') {
            $errors[] = 'Display name is required.';
        }

        $emailAddress = (string)$input['email_address'];
        $mobileNumber = (string)$input['mobile_number'];

        if ($emailAddress === '' && $mobileNumber === '') {
            $errors[] = 'At least one contact method is required.';
        }

        if ($emailAddress !== '' && !filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address must be valid.';
        }

        if ($emailAddress !== '' && $this->emailAddressUsedByAnotherUser($userId, $emailAddress)) {
            $errors[] = 'A user with that email address already exists.';
        }

        if ($mobileNumber !== '' && preg_match('/^\+[1-9][0-9]{6,14}$/', $mobileNumber) !== 1) {
            $errors[] = 'Mobile number must include a valid country code and 7 to 15 digits.';
        }

        return $errors;
    }

    private function validStoredInviteContactMethods(array $user): array
    {
        $methods = [];
        $emailAddress = strtolower(trim((string)($user['email_address'] ?? '')));
        $mobileNumber = trim((string)($user['mobile_number'] ?? ''));

        if ($emailAddress !== '' && filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            $methods[] = 'email';
        }

        if ($mobileNumber !== '' && preg_match('/^\+[1-9][0-9]{6,14}$/', $mobileNumber) === 1) {
            $methods[] = 'sms';
        }

        return $methods;
    }

    private function emailAddressUsedByAnotherUser(int $userId, string $emailAddress): bool
    {
        $row = InterfaceDB::fetchOne(
            'SELECT id
             FROM users
             WHERE email_address = :email_address
               AND id <> :id
             LIMIT 1',
            [
                'email_address' => strtolower(trim($emailAddress)),
                'id' => max(0, $userId),
            ]
        );

        return is_array($row);
    }

    private function normaliseAssignableRoleId(int $roleId): int
    {
        foreach ($this->roleAssignmentService->listRolesForSelect() as $role) {
            if ((int)($role['id'] ?? 0) === $roleId) {
                return $roleId;
            }
        }

        return RoleAssignmentService::ADMIN_ROLE_ID;
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
