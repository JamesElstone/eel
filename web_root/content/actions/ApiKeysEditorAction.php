<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 */
declare(strict_types=1);

final class ApiKeysEditorAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();
        if (!$this->canEdit($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['api.keys.editor'], [[
                'type' => 'error',
                'message' => 'You do not have permission to update API credentials, or your security token expired.',
            ]]);
        }

        try {
            $editor = new \eel_accounts\Service\ApiKeysEditorService();
            $intent = trim((string)$request->input('intent', ''));
            $result = match ($intent) {
                'save' => $editor->save(
                    $this->arrayInput($request, 'credentials'),
                    $this->arrayInput($request, 'new_credential')
                ),
                'configure_companies_house_test' => $editor->configureCompaniesHouseTest(
                    $this->arrayInput($request, 'companies_house_test'),
                    (string)$request->input('generate_binding_key', '0') === '1'
                ),
                default => throw new RuntimeException('Unknown API credential action.'),
            };
        } catch (Throwable $exception) {
            return new ActionResultFramework(false, ['api.keys.editor'], [[
                'type' => 'error',
                'message' => 'API credential update failed: ' . $this->safeMessage($exception->getMessage()),
            ]]);
        }

        return ActionResultFramework::success(
            ['api.keys.editor', 'api.connectivity.test', 'companies.house.accounts.submission'],
            [[
                'type' => 'success',
                'message' => !empty($result['changed'])
                    ? 'API credential metadata updated. A private backup was created.'
                    : 'No API credential changes were needed.',
            ]]
        );
    }

    private function canEdit(SessionAuthenticationService $session): bool
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $session->authenticatedUserId($deviceId);
        return $userId > 0 && in_array(
            'api_keys_editor',
            (new CardAccessFramework())->allowedCardsForUser($userId, ['api_keys_editor']),
            true
        );
    }

    /** @return array<string, mixed> */
    private function arrayInput(RequestFramework $request, string $key): array
    {
        $value = $request->input($key, []);
        return is_array($value) ? $value : [];
    }

    private function safeMessage(string $message): string
    {
        return preg_replace('/[\r\n]+/', ' ', trim($message)) ?: 'The credential file could not be updated.';
    }
}
