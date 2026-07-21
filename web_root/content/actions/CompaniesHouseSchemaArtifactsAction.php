<?php
declare(strict_types=1);

final class CompaniesHouseSchemaArtifactsAction implements ActionInterfaceFramework
{
    private ?Closure $securityCheck;
    private ?Closure $refresh;

    public function __construct(?callable $refresh = null, ?callable $securityCheck = null)
    {
        $this->refresh = $refresh === null ? null : Closure::fromCallable($refresh);
        $this->securityCheck = $securityCheck === null ? null : Closure::fromCallable($securityCheck);
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        if ((string)$request->input('intent', '') !== 'refresh_companies_house_accounts_schemas') {
            return $this->result(false, 'Unknown Companies House schema action.');
        }
        $securityError = $this->securityError($request);
        if ($securityError !== null) { return $this->result(false, $securityError); }
        try {
            $result = $this->refresh instanceof Closure
                ? ($this->refresh)($services->actionProgress())
                : (new \eel_accounts\Service\CompaniesHouseAccountsSchemaService())->ensureCurrent($services->actionProgress());
            $message = !empty($result['changed'])
                ? 'A new Companies House accounts schema snapshot was downloaded, verified and activated.'
                : 'The Companies House accounts schema snapshot is current and verified.';
            return $this->result(true, $message);
        } catch (Throwable $exception) {
            return $this->result(false, 'Companies House schema refresh failed: ' . $exception->getMessage());
        }
    }

    private function securityError(RequestFramework $request): ?string
    {
        if ($this->securityCheck instanceof Closure) { return ($this->securityCheck)($request); }
        $token = trim((string)$request->input('csrf_token', ''));
        if ($token === '') { return 'A valid security token is required.'; }
        try {
            $session = new SessionAuthenticationService(); $session->startSession();
            if (!$session->isValidCsrfToken($token)) { return 'The security token expired. Refresh the page before trying again.'; }
            $deviceId = trim((string)AntiFraudService::instance($request)->requestValue('Client-Device-ID'));
            $userId = $session->authenticatedUserId($deviceId !== '' ? $deviceId : null);
            if ($userId <= 0 || (new CardAccessFramework())->roleIdForUser($userId) !== RoleAssignmentService::ADMIN_ROLE_ID) { return 'Only administrators can refresh Companies House filing schemas.'; }
        } catch (Throwable) { return 'Companies House schema refresh authorisation could not be verified.'; }
        return null;
    }

    private function result(bool $success, string $message): ActionResultFramework
    {
        return new ActionResultFramework($success, ['companies.house.accounts.schemas','page.context'], [[
            'type' => $success ? 'success' : 'error', 'message' => $message,
        ]]);
    }
}
