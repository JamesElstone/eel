<?php
/** eelKit Framework */
declare(strict_types=1);

final class ApiKeysEditorAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();
        if (!$this->canEdit($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['api.keys.editor'], [['type' => 'error', 'message' => 'You do not have permission to update API credentials, or your security token expired.']]);
        }
        try {
            if ((string)$request->input('api_keys_editor_operation', '') === 'repair_file') {
                if (!(bool)AppConfigurationStore::get('developer_options', false)) {
                    throw new RuntimeException('Developer Options must be enabled to repair the API key file.');
                }
                $result = (new ApiKeysEditorService())->repairFile();
                return ActionResultFramework::success(
                    ['api.keys.editor'],
                    [['type' => 'success', 'message' => !empty($result['created'])
                        ? 'API key file created with the required header.'
                        : 'API key file permissions repaired.']]
                );
            }

            $credential = $request->input('credential', []);
            if (!is_array($credential)) {
                throw new RuntimeException('Credential data is invalid.');
            }
            $result = (new ApiKeysEditorService())->save(trim((string)$request->input('edit_credential_id', '')), $credential);
        } catch (Throwable $exception) {
            return new ActionResultFramework(false, ['api.keys.editor'], [['type' => 'error', 'message' => 'API credential update failed: ' . $this->safeMessage($exception->getMessage())]]);
        }
        return ActionResultFramework::success(['api.keys.editor'], [['type' => 'success', 'message' => !empty($result['changed']) ? 'API credential metadata updated. A private backup was created.' : 'No API credential changes were needed.']]);
    }

    private function canEdit(SessionAuthenticationService $session): bool
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $session->authenticatedUserId($deviceId);
        return $userId > 0 && in_array('api_keys_editor', (new CardAccessFramework())->allowedCardsForUser($userId, ['api_keys_editor']), true);
    }

    private function safeMessage(string $message): string
    {
        return preg_replace('/[\r\n]+/', ' ', trim($message)) ?: 'The credential file could not be updated.';
    }
}
