<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class CsrfGuardFramework
{
    public const MODE_SUPPLIED = 'supplied';
    public const MODE_REQUIRED = 'required';
    public const MODE_OFF = 'off';

    private const TOKEN_FIELD = 'csrf_token';
    private const FAILURE_MESSAGE = 'Your security token expired. Please refresh the page and try again.';

    public function __construct(
        private readonly ?SessionAuthenticationService $sessionAuthenticationService = null,
        private readonly ?string $mode = null
    ) {
    }

    public static function configuredMode(): string
    {
        return self::normaliseMode((string)AppConfigurationStore::get('security.csrf_mode', self::MODE_SUPPLIED));
    }

    public static function normaliseMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, [self::MODE_SUPPLIED, self::MODE_REQUIRED, self::MODE_OFF], true)
            ? $mode
            : self::MODE_SUPPLIED;
    }

    public static function tokenField(): string
    {
        return self::TOKEN_FIELD;
    }

    public function validateActionRequest(RequestFramework $request, ?string $policy = null): ActionResultFramework
    {
        $mode = self::normaliseMode((string)($policy ?? $this->mode ?? self::configuredMode()));
        if ($mode === self::MODE_OFF || !$request->isPost() || !$this->requestHasAction($request)) {
            return ActionResultFramework::none();
        }

        $submittedToken = trim((string)$request->input(self::TOKEN_FIELD, ''));
        if ($submittedToken === '' && $mode === self::MODE_SUPPLIED) {
            return ActionResultFramework::none();
        }

        if ($submittedToken !== '' && $this->session()->isValidCsrfToken($submittedToken)) {
            return ActionResultFramework::none();
        }

        return $this->failureResult();
    }

    public function hiddenInput(?string $token = null): string
    {
        $token = $token ?? $this->session()->csrfToken();

        return '<input type="hidden" name="' . HelperFramework::escape(self::TOKEN_FIELD) . '" value="'
            . HelperFramework::escape($token) . '">';
    }

    public function failureResult(): ActionResultFramework
    {
        return new ActionResultFramework(false, ['page.context'], [[
            'type' => 'error',
            'message' => self::FAILURE_MESSAGE,
        ]]);
    }

    private function session(): SessionAuthenticationService
    {
        return $this->sessionAuthenticationService ?? new SessionAuthenticationService();
    }

    private function requestHasAction(RequestFramework $request): bool
    {
        return trim($request->action()) !== ''
            || trim($request->cardAction()) !== '';
    }
}
