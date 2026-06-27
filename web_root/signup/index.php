<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

const EEL_SIGNUP_HTML_COPYRIGHT_HEADER = '<!-- eelKit Framework - Copyright (c) 2026 James Elstone - Licensed under the BSD 3-Clause License - See LICENSE file for details. -->';

function eel_signup_html_with_copyright_header(string $html): string
{
    if (str_contains($html, EEL_SIGNUP_HTML_COPYRIGHT_HEADER)) {
        return $html;
    }

    $htmlWithHeader = preg_replace(
        '/(<!DOCTYPE html>)/i',
        '$1' . PHP_EOL . EEL_SIGNUP_HTML_COPYRIGHT_HEADER,
        $html,
        1
    );

    if (is_string($htmlWithHeader) && $htmlWithHeader !== $html) {
        return $htmlWithHeader;
    }

    return EEL_SIGNUP_HTML_COPYRIGHT_HEADER . PHP_EOL . $html;
}

$appName = trim((string)AppConfigurationStore::get('app_name', 'eelKit Framework'));
if ($appName === '') {
    $appName = 'eelKit Framework';
}

$request = RequestFramework::fromGlobals();
AntiFraudService::instance($request);
(new SignupResponseHeaderService())->send();

$sessionAuthenticationService = new SessionAuthenticationService(request: $request);
$sessionAuthenticationService->startSession();

$completionSession = new AccountCompletionSessionService(sessionAuthenticationService: $sessionAuthenticationService);
$completionService = new AccountCompletionService();
$signupTokenRateLimitService = new SignupTokenRateLimitService();
$signupVerificationRateLimitService = new SignupVerificationRateLimitService();
$renderer = new SignupPageRenderer($appName, AppConfigurationStore::appStrapline());

if ($request->isPost()) {
    if (!$sessionAuthenticationService->isValidCsrfToken((string)$request->post('csrf_token', ''))) {
        echo eel_signup_html_with_copyright_header($renderer->errorPage($sessionAuthenticationService, [AccountCompletionService::PUBLIC_VERIFY_ERROR]));
        return;
    }

    $action = trim((string)$request->post('signup_action', ''));

    if ($action === 'verify_identity') {
        if ($signupVerificationRateLimitService->isBlocked($request, $sessionAuthenticationService)) {
            $invite = $completionService->verificationContext($completionSession);
            echo eel_signup_html_with_copyright_header($renderer->verificationPage($sessionAuthenticationService, [AccountCompletionService::PUBLIC_VERIFY_ERROR], $invite ?? []));
            return;
        }

        $result = $completionService->verifyIdentity(
            $completionSession,
            (string)$request->post('email_address', ''),
            (string)$request->post('mobile_country_code', MobileNumberService::DEFAULT_COUNTRY_CODE),
            (string)$request->post('mobile_number', '')
        );

        if (empty($result['success'])) {
            $signupVerificationRateLimitService->recordFailedVerification($request, $sessionAuthenticationService);
            $invite = $completionService->verificationContext($completionSession);
            echo eel_signup_html_with_copyright_header($renderer->verificationPage($sessionAuthenticationService, (array)($result['errors'] ?? []), $invite ?? []));
            return;
        }

        $signupVerificationRateLimitService->clearCurrent($request, $sessionAuthenticationService);
        $invite = $completionService->verificationContext($completionSession);
        echo eel_signup_html_with_copyright_header($invite === null
            ? $renderer->errorPage($sessionAuthenticationService, [AccountCompletionService::PUBLIC_TOKEN_ERROR])
            : $renderer->completionPage($sessionAuthenticationService, $invite));
        return;
    }

    if ($action === 'complete_account') {
        $result = $completionService->completeAccount(
            $completionSession,
            (string)$request->post('display_name', ''),
            (string)$request->post('email_address', ''),
            (string)$request->post('mobile_country_code', MobileNumberService::DEFAULT_COUNTRY_CODE),
            (string)$request->post('mobile_number', ''),
            (string)$request->post('password', ''),
            (string)$request->post('password_confirm', '')
        );

        if (!empty($result['success'])) {
            header('Location: /index.php');
            return;
        }

        $invite = $completionService->verificationContext($completionSession);
        echo eel_signup_html_with_copyright_header($invite === null
            ? $renderer->errorPage($sessionAuthenticationService, [AccountCompletionService::PUBLIC_TOKEN_ERROR])
            : $renderer->completionPage($sessionAuthenticationService, $invite, (array)($result['errors'] ?? [])));
        return;
    }
}

$token = trim((string)$request->query('token', ''));
if ($token !== '') {
    if ($signupTokenRateLimitService->isBlocked($request)) {
        echo eel_signup_html_with_copyright_header($renderer->errorPage($sessionAuthenticationService, [AccountCompletionService::PUBLIC_TOKEN_ERROR]));
        return;
    }

    $result = $completionService->beginFromToken($token, $completionSession);
    if (empty($result['success'])) {
        $signupTokenRateLimitService->recordFailedToken($request);
        echo eel_signup_html_with_copyright_header($renderer->errorPage($sessionAuthenticationService, (array)($result['errors'] ?? [])));
        return;
    }

    echo eel_signup_html_with_copyright_header($renderer->verificationPage($sessionAuthenticationService, [], (array)($result['invite'] ?? [])));
    return;
}

$invite = $completionService->verificationContext($completionSession);
if ($invite !== null && $completionSession->isVerified()) {
    echo eel_signup_html_with_copyright_header($renderer->completionPage($sessionAuthenticationService, $invite));
    return;
}

if ($completionSession->isValidated()) {
    $invite = $completionService->verificationContext($completionSession);
    echo eel_signup_html_with_copyright_header($renderer->verificationPage($sessionAuthenticationService, [], $invite ?? []));
    return;
}

echo eel_signup_html_with_copyright_header($renderer->errorPage($sessionAuthenticationService, [AccountCompletionService::PUBLIC_TOKEN_ERROR]));
