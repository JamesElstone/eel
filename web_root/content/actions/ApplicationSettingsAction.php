<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ApplicationSettingsAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $currentUserId = $this->currentUserIdFromSession($sessionAuthenticationService);

        if ($currentUserId <= 0 || !$this->userCanAccessApplicationSettingsCard($currentUserId)) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'You do not have permission to update application settings.',
                ]],
                []
            );
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

        $appName = trim((string)$request->input('app_name', ''));
        $brandMark = trim((string)$request->input('brand_mark', ''));

        if ($appName === '' || $brandMark === '') {
            return new ActionResultFramework(
                false,
                ['application.settings'],
                [[
                    'type' => 'error',
                    'message' => 'Application name and brand mark are required.',
                ]],
                []
            );
        }

        try {
            $lookedUpVendorPublicIp = (string)$request->input('lookup_vendor_public_ip', '') === '1';
            $vendorPublicIp = $lookedUpVendorPublicIp
                ? (new ExternalIpLookupOutbound())->lookupPublicIp()
                : trim((string)$request->input('antifraud_vendor_public_ip', ''));
            $settings = [
                'app_name' => $appName,
                'app_strapline' => trim((string)$request->input('app_strapline', '')),
                'brand-mark' => $brandMark,
                'developer_options' => (string)$request->input('developer_options', '') === '1',
                'navigation' => array_replace($this->configArray('navigation'), [
                    'default_order' => $this->navigationOrderFromRequest($request),
                ]),
                'antifraud' => array_replace($this->configArray('antifraud'), [
                    'vendor_license_ids' => trim((string)$request->input('antifraud_vendor_license_ids', '')),
                    'vendor_product_name' => trim((string)$request->input('antifraud_vendor_product_name', '')),
                    'vendor_public_ip' => $vendorPublicIp,
                    'vendor_version' => trim((string)$request->input('antifraud_vendor_version', '')),
                ]),
                'session' => array_replace($this->configArray('session'), [
                    'cookie_secure' => $this->cookieSecureValue((string)$request->input('session_cookie_secure', 'auto')),
                    'cookie_samesite' => $this->cookieSameSiteValue((string)$request->input('session_cookie_samesite', 'Strict')),
                ]),
            ];

            AppConfigurationStore::setEditableApplicationSettings($settings);
            $GLOBALS['appName'] = $appName;
        } catch (Throwable $exception) {
            return new ActionResultFramework(
                false,
                ['application.settings'],
                [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]],
                []
            );
        }

        return ActionResultFramework::success(
            ['application.settings', 'layout.sidebar'],
            [[
                'type' => 'success',
                'message' => $lookedUpVendorPublicIp
                    ? 'Application settings saved. Vendor public IP looked up.'
                    : 'Application settings saved.',
            ]]
        );
    }

    private function userCanAccessApplicationSettingsCard(int $userId): bool
    {
        return in_array(
            'application_settings',
            (new CardAccessFramework())->allowedCardsForUser($userId, ['application_settings']),
            true
        );
    }

    private function configArray(string $path): array
    {
        $value = AppConfigurationStore::get($path, []);

        return is_array($value) ? $value : [];
    }

    private function navigationOrderFromRequest(RequestFramework $request): array
    {
        $keys = $request->input('navigation_order_keys', []);
        $keys = is_array($keys) ? array_values($keys) : [];
        $orderedKeys = [];

        foreach ($keys as $key) {
            $pageKey = $this->normalisePageKey((string)$key);
            if ($pageKey === null) {
                continue;
            }

            $orderedKeys[] = $pageKey;
        }

        $order = $this->renumberNavigationOrder(array_values(array_unique($orderedKeys)));
        $action = $this->navigationOrderAction((string)$request->input('navigation_order_action', ''));
        if ($action !== null) {
            $order = $this->applyNavigationOrderAction($order, $action['verb'], $action['page_key']);
        }

        return $order;
    }

    private function navigationOrderAction(string $value): ?array
    {
        $parts = explode(':', trim($value), 2);
        if (count($parts) !== 2) {
            return null;
        }

        $verb = strtolower(trim($parts[0]));
        $pageKey = $this->normalisePageKey($parts[1]);

        if (!in_array($verb, ['up', 'down', 'remove'], true) || $pageKey === null) {
            return null;
        }

        return [
            'verb' => $verb,
            'page_key' => $pageKey,
        ];
    }

    private function applyNavigationOrderAction(array $order, string $verb, string $pageKey): array
    {
        if (!array_key_exists($pageKey, $order)) {
            return $order;
        }

        $keys = array_keys($order);
        $index = array_search($pageKey, $keys, true);
        if ($index === false) {
            return $order;
        }

        if ($verb === 'remove') {
            array_splice($keys, (int)$index, 1);
            return $this->renumberNavigationOrder($keys);
        }

        $swapIndex = $verb === 'up' ? (int)$index - 1 : (int)$index + 1;
        if (!array_key_exists($swapIndex, $keys)) {
            return $this->renumberNavigationOrder($keys);
        }

        [$keys[$index], $keys[$swapIndex]] = [$keys[$swapIndex], $keys[$index]];

        return $this->renumberNavigationOrder($keys);
    }

    private function renumberNavigationOrder(array $keys): array
    {
        $order = [];

        foreach (array_values($keys) as $index => $pageKey) {
            $order[(string)$pageKey] = ((int)$index + 1) * 10;
        }

        return $order;
    }

    private function normalisePageKey(string $pageKey): ?string
    {
        $pageKey = strtolower(trim($pageKey));
        $pageKey = str_replace('-', '_', $pageKey);

        return preg_match('/^[a-z][a-z0-9_]*$/', $pageKey) === 1 ? $pageKey : null;
    }

    private function cookieSecureValue(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['auto', 'true', 'false'], true) ? $value : 'auto';
    }

    private function cookieSameSiteValue(string $value): string
    {
        return match (strtolower(trim($value))) {
            'lax' => 'Lax',
            'none' => 'None',
            default => 'Strict',
        };
    }

    private function currentUserIdFromSession(SessionAuthenticationService $sessionAuthenticationService): int
    {
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
    }
}
