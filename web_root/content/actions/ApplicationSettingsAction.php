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
            $previousConfig = AppConfigurationStore::config();
            $lookedUpVendorPublicIp = (string)$request->input('lookup_vendor_public_ip', '') === '1';
            $vendorPublicIp = $lookedUpVendorPublicIp
                ? (new ExternalIpLookupOutbound())->lookupPublicIp()
                : trim((string)$request->input('antifraud_vendor_public_ip', ''));
            $developerOptions = $this->checkboxValue($request, 'developer_options');
            $navigationOrder = $this->navigationOrderFromRequest($request);
            $settings = [
                'app_name' => $appName,
                'app_strapline' => trim((string)$request->input('app_strapline', '')),
                'app_footer' => trim((string)$request->input('app_footer', '')),
                'brand-mark' => $brandMark,
                'developer_options' => $developerOptions,
                'navigation' => array_replace($this->configArray($previousConfig, 'navigation'), [
                    'default_order' => $navigationOrder,
                    'topbar_disabled_pages' => $this->topbarDisabledPagesFromRequest($request, array_keys($navigationOrder)),
                    'hide_collapsed_link_initials' => $this->checkboxValue($request, 'hide_collapsed_link_initials'),
                ]),
                'antifraud' => array_replace($this->configArray($previousConfig, 'antifraud'), [
                    'vendor_license_ids' => trim((string)$request->input('antifraud_vendor_license_ids', '')),
                    'vendor_product_name' => trim((string)$request->input('antifraud_vendor_product_name', '')),
                    'vendor_public_ip' => $vendorPublicIp,
                    'vendor_version' => trim((string)$request->input('antifraud_vendor_version', '')),
                ]),
                'session' => array_replace($this->configArray($previousConfig, 'session'), [
                    'cookie_secure' => $this->cookieSecureValue((string)$request->input('session_cookie_secure', 'auto')),
                    'cookie_samesite' => $this->cookieSameSiteValue((string)$request->input('session_cookie_samesite', 'Strict')),
                ]),
            ];
            $successMessage = $this->successFlashMessage($previousConfig, $settings, $lookedUpVendorPublicIp);

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
            ['application.settings', 'layout.sidebar', 'layout.topbar', 'layout.footer'],
            [[
                'type' => 'success',
                'message' => $successMessage,
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

    private function configArray(array $config, string $path): array
    {
        $value = $config[$path] ?? [];

        return is_array($value) ? $value : [];
    }

    private function checkboxValue(RequestFramework $request, string $name): bool
    {
        $value = $request->input($name, '0');
        if (is_array($value)) {
            $value = end($value);
        }

        return (string)$value === '1';
    }

    private function successFlashMessage(array $previousConfig, array $settings, bool $lookedUpVendorPublicIp): string
    {
        $changes = $this->settingChangeMessages($previousConfig, $settings);

        if ($lookedUpVendorPublicIp) {
            $changes[] = 'Vendor public IP looked up.';
        }

        if ($changes === []) {
            return 'No changes needed; application settings are already up to date.';
        }

        return implode(' ', $changes);
    }

    private function settingChangeMessages(array $previousConfig, array $settings): array
    {
        $changes = [];

        if ((string)($previousConfig['app_name'] ?? '') !== (string)($settings['app_name'] ?? '')
            || (string)($previousConfig['app_strapline'] ?? '') !== (string)($settings['app_strapline'] ?? '')
            || (string)($previousConfig['app_footer'] ?? '') !== (string)($settings['app_footer'] ?? '')
            || (string)($previousConfig['brand-mark'] ?? '') !== (string)($settings['brand-mark'] ?? '')) {
            $changes[] = 'Branding updated.';
        }

        if ((bool)($previousConfig['developer_options'] ?? false) !== (bool)($settings['developer_options'] ?? false)) {
            $changes[] = !empty($settings['developer_options'])
                ? 'Developer options are now on.'
                : 'Developer options are now off.';
        }

        $previousNavigation = $this->configArray($previousConfig, 'navigation');
        $currentNavigation = $this->configArray($settings, 'navigation');
        if ((bool)($previousNavigation['hide_collapsed_link_initials'] ?? false) !== (bool)($currentNavigation['hide_collapsed_link_initials'] ?? false)) {
            $changes[] = !empty($currentNavigation['hide_collapsed_link_initials'])
                ? 'Collapsed sidebar link initials are now hidden.'
                : 'Collapsed sidebar link initials are visible again.';
        }

        if (($previousNavigation['default_order'] ?? []) !== ($currentNavigation['default_order'] ?? [])) {
            $changes[] = 'Navigation order updated.';
        }

        if (($previousNavigation['topbar_disabled_pages'] ?? []) !== ($currentNavigation['topbar_disabled_pages'] ?? [])) {
            $changes[] = 'Page topbar visibility updated.';
        }

        if ($this->configArray($previousConfig, 'antifraud') !== $this->configArray($settings, 'antifraud')) {
            $changes[] = 'Anti-fraud header defaults updated.';
        }

        if ($this->configArray($previousConfig, 'session') !== $this->configArray($settings, 'session')) {
            $changes[] = 'Session cookie settings updated.';
        }

        return $changes;
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

    private function topbarDisabledPagesFromRequest(RequestFramework $request, array $orderedPageKeys): array
    {
        $enabledValues = $request->input('topbar_enabled_pages', []);
        $enabledValues = is_array($enabledValues) ? array_values($enabledValues) : [];
        $enabled = [];

        foreach ($enabledValues as $value) {
            $pageKey = $this->normalisePageKey((string)$value);
            if ($pageKey !== null) {
                $enabled[$pageKey] = true;
            }
        }

        $disabled = [];
        foreach ($orderedPageKeys as $pageKey) {
            $pageKey = $this->normalisePageKey((string)$pageKey);
            if ($pageKey !== null && !isset($enabled[$pageKey])) {
                $disabled[] = $pageKey;
            }
        }

        return $disabled;
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
