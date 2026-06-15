<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class WebEnvironmentAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();

        if (!$this->canUpdate($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['web.environment'], [[
                'type' => 'error',
                'message' => 'You do not have permission to update web environment settings, or your security token expired.',
            ]]);
        }

        $trustedProxyIps = $this->ipList((string)$request->input('reverse_proxy_trusted_proxy_ips', ''));
        $clientIpHeaders = $this->headerList((string)$request->input('reverse_proxy_client_ip_headers', ''));

        if ($trustedProxyIps === null) {
            return $this->error('Trusted reverse proxy IPs must contain valid IP addresses.');
        }

        if ($clientIpHeaders === null) {
            return $this->error('Client IP headers must contain valid HTTP header names.');
        }

        $addedCurrentProxy = false;
        if ((string)$request->input('add_current_reverse_proxy', '') === '1') {
            $currentProxyIp = $this->currentReverseProxyIp($request);
            if ($currentProxyIp === '') {
                return $this->error('The current reverse proxy IP address could not be detected.');
            }

            $alreadyTrusted = in_array($currentProxyIp, $trustedProxyIps, true);
            $trustedProxyIps = $this->withCurrentReverseProxyIp($trustedProxyIps, $currentProxyIp);
            $addedCurrentProxy = !$alreadyTrusted;
        }

        AppConfigurationStore::setWebEnvironmentSettings([
            'base_url_override' => rtrim(trim((string)$request->input('web_base_url_override', '')), '/'),
            'trusted_proxy_ips' => $trustedProxyIps,
            'client_ip_headers' => $clientIpHeaders,
        ]);

        return ActionResultFramework::success(['web.environment', 'invitation.settings'], [[
            'type' => 'success',
            'message' => $addedCurrentProxy
                ? 'Current reverse proxy added. Web environment settings updated.'
                : 'Web environment settings updated.',
        ]]);
    }

    private function canUpdate(SessionAuthenticationService $session): bool
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $session->authenticatedUserId($deviceId);

        return $userId > 0 && in_array('web_environment', (new CardAccessFramework())->allowedCardsForUser($userId, ['web_environment']), true);
    }

    private function error(string $message): ActionResultFramework
    {
        return new ActionResultFramework(false, ['web.environment'], [[
            'type' => 'error',
            'message' => $message,
        ]]);
    }

    private function ipList(string $value): ?array
    {
        $ips = [];
        foreach ($this->listValues($value) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return null;
            }
            $ips[] = $ip;
        }

        return array_values(array_unique($ips));
    }

    private function headerList(string $value): ?array
    {
        $headers = [];
        foreach ($this->listValues($value) as $header) {
            if (preg_match('/^[A-Za-z0-9-]+$/', $header) !== 1) {
                return null;
            }
            $headers[] = HelperFramework::httpHeaderLabelFromServerKey(str_replace('-', '_', $header));
        }

        return array_values(array_unique($headers));
    }

    private function currentReverseProxyIp(RequestFramework $request): string
    {
        $remoteAddress = trim((string)($request->remoteAddress() ?? ''));

        return filter_var($remoteAddress, FILTER_VALIDATE_IP) === false ? '' : mb_substr($remoteAddress, 0, 45);
    }

    private function withCurrentReverseProxyIp(array $trustedProxyIps, string $currentProxyIp): array
    {
        $trustedProxyIps[] = $currentProxyIp;

        return array_values(array_unique($trustedProxyIps));
    }

    private function listValues(string $value): array
    {
        $values = preg_split('/[\r\n,]+/', $value) ?: [];
        $normalised = [];

        foreach ($values as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $normalised[] = $item;
            }
        }

        return $normalised;
    }
}
