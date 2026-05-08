<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class PageRequestGuard
{
    public function __construct(
        private readonly string $appName,
        private readonly AuthPageRenderer $authPageRenderer,
    ) {
    }

    public function pageAccessResponse(
        RequestFramework $request,
        PageInterfaceFramework $page,
        SessionAuthenticationService $sessionAuthenticationService,
        string $currentDeviceId
    ): ?ResponseFramework {
        if ($sessionAuthenticationService->isAuthenticated($currentDeviceId)) {
            return null;
        }

        $message = 'Please sign in to access the requested page.';

        if ($request->isAjax()) {
            return ResponseFramework::json(
                [
                    'success' => false,
                    'errors' => [$message],
                    'requires_authentication' => true,
                ],
                401
            );
        }

        return ResponseFramework::html(
            $this->authPageRenderer->loginPage($sessionAuthenticationService, [$message]),
            401
        );
    }

    public function pagePolicyResponse(RequestFramework $request, string $pageKey): ?ResponseFramework
    {
        if (PageAccessFramework::isPageAvailable($pageKey)) {
            return null;
        }

        $message = 'The requested page was not found.';

        if ($request->isAjax()) {
            return ResponseFramework::json(
                [
                    'success' => false,
                    'errors' => [$message],
                ],
                404
            );
        }

        $escapedAppName = HelperFramework::escape($this->appName);

        return ResponseFramework::html(
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Page not found | '
            . $escapedAppName
            . '</title></head><body><h1>Page not found</h1><p>'
            . HelperFramework::escape($message)
            . '</p></body></html>',
            404
        );
    }

    public function ajaxNonceResponse(
        RequestFramework $request,
        SessionAuthenticationService $sessionAuthenticationService,
        string $currentDeviceId
    ): ?ResponseFramework {
        if (!$sessionAuthenticationService->isAuthenticated($currentDeviceId)) {
            return null;
        }

        if (!$this->requestHasAjaxAction($request)) {
            return null;
        }

        $submittedNonce = trim((string)$request->input('ajax_nonce', ''));
        if ($submittedNonce === '') {
            return $this->ajaxNonceErrorResponse('Your secure request token is missing. Please reload the page and try again.');
        }

        $nonceResult = $sessionAuthenticationService->consumeAjaxNonce($submittedNonce, $currentDeviceId);

        if (!empty($nonceResult['valid'])) {
            return null;
        }

        return $this->ajaxNonceErrorResponse('Your secure request token expired or was already used. Please reload the page and try again.');
    }

    public function requestHasAjaxAction(RequestFramework $request): bool
    {
        return $request->isPost()
            && $request->isAjax()
            && (trim($request->action()) !== '' || trim($request->cardAction()) !== '');
    }

    public function ajaxNonceErrorResponse(string $message): ResponseFramework
    {
        $escapedMessage = HelperFramework::escape($message);

        return ResponseFramework::json(
            [
                'success' => false,
                'errors' => [$message],
                'reload_required' => true,
                'flash_html' => '<div class="alert error">' . $escapedMessage . '</div>',
            ],
            409
        );
    }
}
