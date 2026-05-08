<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class AuthController
{
    public function __construct(
        private readonly LoginService $loginService,
        private readonly SessionAuthenticationService $sessionAuthenticationService,
        private readonly UserAuthenticationService $userAuthenticationService,
        private readonly FirstUserBootstrapService $firstUserBootstrapService,
        private readonly AuthPageRenderer $renderer,
    ) {
    }

    public function response(
        RequestFramework $request,
        string $currentDeviceId,
        bool $requiresInitialUserSetup,
        ?array $bootstrapState = null
    ): ?ResponseFramework {
        $authAction = trim((string)$request->input('auth_action', ''));

        if ($authAction === 'logout') {
            return $this->logoutResponse($request, $currentDeviceId, $requiresInitialUserSetup);
        }

        if ($this->sessionAuthenticationService->isAuthenticated($currentDeviceId)) {
            return null;
        }

        if ($request->isPost()) {
            if (!$this->sessionAuthenticationService->isValidCsrfToken((string)$request->post('csrf_token', ''))) {
                return ResponseFramework::html(
                    $this->renderer->authStatePage(
                        $this->sessionAuthenticationService,
                        $currentDeviceId,
                        $requiresInitialUserSetup,
                        $this->loginService,
                        ['Your security token expired. Please try again.']
                    ),
                    403
                );
            }

            try {
                return $this->postResponse(
                    $request,
                    $authAction,
                    $currentDeviceId,
                    $requiresInitialUserSetup,
                    $bootstrapState
                );
            } catch (Throwable $exception) {
                return ResponseFramework::html(
                    $this->renderer->authStatePage(
                        $this->sessionAuthenticationService,
                        $currentDeviceId,
                        $requiresInitialUserSetup,
                        $this->loginService,
                        [$exception->getMessage()]
                    ),
                    400
                );
            }
        }

        return $this->defaultResponse($currentDeviceId, $requiresInitialUserSetup);
    }

    private function logoutResponse(
        RequestFramework $request,
        string $currentDeviceId,
        bool $requiresInitialUserSetup
    ): ResponseFramework {
        if (!$request->isPost() || !$this->sessionAuthenticationService->isValidCsrfToken((string)$request->post('csrf_token', ''))) {
            return ResponseFramework::html(
                $this->renderer->authStatePage(
                    $this->sessionAuthenticationService,
                    $currentDeviceId,
                    $requiresInitialUserSetup,
                    $this->loginService,
                    ['Your security token expired. Please try again.']
                ),
                403
            );
        }

        $this->loginService->logout();

        return ResponseFramework::html(
            $this->renderer->authStatePage(
                $this->sessionAuthenticationService,
                $currentDeviceId,
                $requiresInitialUserSetup,
                $this->loginService
            )
        );
    }

    private function postResponse(
        RequestFramework $request,
        string $authAction,
        string $currentDeviceId,
        bool $requiresInitialUserSetup,
        ?array $bootstrapState
    ): ?ResponseFramework {
        if ($requiresInitialUserSetup && $authAction === 'create_initial_user') {
            return $this->createInitialUserResponse($request, $currentDeviceId, $bootstrapState);
        }

        if ($authAction === 'login') {
            return $this->loginResponse($request, $currentDeviceId);
        }

        if ($authAction === 'verify_otp') {
            return $this->otpLoginResponse($request, $currentDeviceId);
        }

        if ($authAction === 'change_required_password') {
            return $this->requiredPasswordChangeResponse($request, $currentDeviceId);
        }

        if ($authAction === 'verify_otp_setup') {
            return $this->otpSetupResponse($request, $currentDeviceId);
        }

        return $this->defaultResponse($currentDeviceId, $requiresInitialUserSetup);
    }

    private function createInitialUserResponse(
        RequestFramework $request,
        string $currentDeviceId,
        ?array $bootstrapState
    ): ResponseFramework {
        $enteredBootstrapCode = (string)$request->post('bootstrap_code', '');
        $bootstrapError = $this->firstUserBootstrapService->validateCode($enteredBootstrapCode, $bootstrapState);

        if ($bootstrapError !== null) {
            return ResponseFramework::html(
                $this->renderer->initialUserPage(
                    $this->sessionAuthenticationService,
                    [$bootstrapError],
                    $enteredBootstrapCode
                ),
                400
            );
        }

        $result = $this->userAuthenticationService->createInitialUser(
            (string)$request->post('display_name', ''),
            (string)$request->post('email_address', ''),
            (string)$request->post('password', '')
        );

        if (!empty($result['success']) && (int)($result['user_id'] ?? 0) > 0) {
            $this->firstUserBootstrapService->deleteCodeFile($bootstrapState);
            $setupData = $this->loginService->beginOtpSetup(
                (int)$result['user_id'],
                $currentDeviceId,
                true
            );

            return ResponseFramework::html(
                $this->renderer->otpSetupPage($this->sessionAuthenticationService, $setupData),
                200
            );
        }

        return ResponseFramework::html(
            $this->renderer->initialUserPage(
                $this->sessionAuthenticationService,
                (array)($result['errors'] ?? []),
                $enteredBootstrapCode
            ),
            !empty($result['errors']) ? 400 : 200
        );
    }

    private function loginResponse(RequestFramework $request, string $currentDeviceId): ResponseFramework
    {
        $result = $this->loginService->startLogin(
            (string)$request->post('email_address', ''),
            (string)$request->post('password', ''),
            $currentDeviceId
        );

        if (!empty($result['authenticated'])) {
            $this->redirectToIndex();
        }

        if (!empty($result['requires_otp'])) {
            return ResponseFramework::html($this->renderer->otpPage($this->sessionAuthenticationService));
        }

        if (!empty($result['requires_password_change'])) {
            return ResponseFramework::html($this->renderer->requiredPasswordChangePage($this->sessionAuthenticationService));
        }

        if (!empty($result['requires_otp_setup'])) {
            $setupData = $this->loginService->pendingOtpSetupViewData($currentDeviceId);

            return ResponseFramework::html(
                $setupData === null
                    ? $this->renderer->loginPage($this->sessionAuthenticationService, ['OTP setup could not be started. Please try again.'])
                    : $this->renderer->otpSetupPage($this->sessionAuthenticationService, $setupData),
                200
            );
        }

        return ResponseFramework::html(
            $this->renderer->loginPage(
                $this->sessionAuthenticationService,
                (array)($result['errors'] ?? []),
                (array)($result['rate_limit'] ?? [])
            ),
            !empty($result['errors']) ? 401 : 200
        );
    }

    private function requiredPasswordChangeResponse(RequestFramework $request, string $currentDeviceId): ResponseFramework
    {
        $result = $this->loginService->completeRequiredPasswordChange(
            (string)$request->post('password', ''),
            (string)$request->post('password_confirm', ''),
            $currentDeviceId
        );

        if (!empty($result['authenticated'])) {
            $this->redirectToIndex();
        }

        if (!empty($result['requires_otp'])) {
            return ResponseFramework::html($this->renderer->otpPage($this->sessionAuthenticationService));
        }

        if (!empty($result['requires_otp_setup'])) {
            $setupData = $this->loginService->pendingOtpSetupViewData($currentDeviceId);

            return ResponseFramework::html(
                $setupData === null
                    ? $this->renderer->loginPage($this->sessionAuthenticationService, ['OTP setup could not be started. Please try again.'])
                    : $this->renderer->otpSetupPage($this->sessionAuthenticationService, $setupData),
                200
            );
        }

        if (!empty($result['requires_password_change'])) {
            return ResponseFramework::html(
                $this->renderer->requiredPasswordChangePage($this->sessionAuthenticationService, (array)($result['errors'] ?? [])),
                400
            );
        }

        return ResponseFramework::html(
            $this->renderer->loginPage($this->sessionAuthenticationService, (array)($result['errors'] ?? [])),
            401
        );
    }

    private function otpLoginResponse(RequestFramework $request, string $currentDeviceId): ResponseFramework
    {
        $result = $this->loginService->completeOtpLogin(
            (string)$request->post('otp_code', ''),
            $currentDeviceId
        );

        if (!empty($result['authenticated'])) {
            $this->redirectToIndex();
        }

        if (!empty($result['requires_otp'])) {
            return ResponseFramework::html(
                $this->renderer->otpPage($this->sessionAuthenticationService, (array)($result['errors'] ?? [])),
                401
            );
        }

        return ResponseFramework::html(
            $this->renderer->loginPage($this->sessionAuthenticationService, (array)($result['errors'] ?? [])),
            401
        );
    }

    private function otpSetupResponse(RequestFramework $request, string $currentDeviceId): ResponseFramework
    {
        $result = $this->loginService->completeOtpSetup(
            (string)$request->post('otp_code', ''),
            $currentDeviceId
        );

        if (!empty($result['authenticated'])) {
            $this->redirectToIndex();
        }

        $setupData = $this->loginService->pendingOtpSetupViewData($currentDeviceId);

        return ResponseFramework::html(
            $setupData === null
                ? $this->renderer->loginPage($this->sessionAuthenticationService, (array)($result['errors'] ?? []))
                : $this->renderer->otpSetupPage($this->sessionAuthenticationService, $setupData, (array)($result['errors'] ?? [])),
            401
        );
    }

    private function defaultResponse(string $currentDeviceId, bool $requiresInitialUserSetup): ResponseFramework
    {
        if ($requiresInitialUserSetup) {
            return ResponseFramework::html($this->renderer->initialUserPage($this->sessionAuthenticationService));
        }

        if ($this->sessionAuthenticationService->hasPendingPasswordChange($currentDeviceId)) {
            return ResponseFramework::html($this->renderer->requiredPasswordChangePage($this->sessionAuthenticationService));
        }

        $setupData = $this->loginService->pendingOtpSetupViewData($currentDeviceId);
        if (is_array($setupData)) {
            return ResponseFramework::html($this->renderer->otpSetupPage($this->sessionAuthenticationService, $setupData));
        }

        if ($this->sessionAuthenticationService->hasPendingOtp($currentDeviceId)) {
            return ResponseFramework::html($this->renderer->otpPage($this->sessionAuthenticationService));
        }

        return ResponseFramework::html($this->renderer->loginPage($this->sessionAuthenticationService));
    }

    private function redirectToIndex(): never
    {
        header('Location: /');
        exit;
    }
}
