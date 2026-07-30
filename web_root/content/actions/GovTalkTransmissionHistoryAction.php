<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class GovTalkTransmissionHistoryAction implements ActionInterfaceFramework
{
    public function handle(
        RequestFramework $request,
        PageServiceFramework $services
    ): ActionResultFramework {
        if (trim((string)$request->input('intent', '')) !== 'download_protocol_evidence') {
            return $this->error('Unknown GovTalk transmission-history action.');
        }
        $security = $this->security($request);
        if (isset($security['error'])) {
            return $this->error((string)$security['error']);
        }
        $companyId = (int)$request->input('company_id', 0);
        $authorisedCompanyId = (new \eel_accounts\Service\AccountingContextService())
            ->authCompanyId();
        if ($companyId <= 0
            || $authorisedCompanyId <= 0
            || $companyId !== $authorisedCompanyId) {
            return $this->error(
                'The selected GovTalk exchange does not belong to the authenticated company.'
            );
        }
        $exchangeId = (int)$request->input('exchange_id', 0);
        $direction = strtolower(trim((string)$request->input('direction', '')));
        try {
            /** @var \eel_accounts\Service\GovTalkTransmissionHistoryService $history */
            $history = $services->get(
                \eel_accounts\Service\GovTalkTransmissionHistoryService::class
            );
            $file = $history->evidenceFileForCompany(
                $companyId,
                $exchangeId,
                $direction
            );
            $history->recordEvidenceDownload(
                $companyId,
                $exchangeId,
                $direction,
                (string)$security['user_id']
            );
        } catch (Throwable $exception) {
            header('Content-Type: text/plain; charset=utf-8', true, 404);
            echo $exception->getMessage();
            exit;
        }
        header('Content-Type: application/xml; charset=utf-8');
        header(
            'Content-Disposition: attachment; filename="'
                . str_replace('"', '', (string)$file['filename']) . '"'
        );
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        $size = filesize((string)$file['path']);
        if (is_int($size)) {
            header('Content-Length: ' . $size);
        }
        readfile((string)$file['path']);
        exit;
    }

    /** @return array{user_id?:int,error?:string} */
    private function security(RequestFramework $request): array
    {
        if (!$request->isPost()) {
            return ['error' => 'GovTalk evidence downloads require a POST request.'];
        }
        $csrfToken = trim((string)$request->input('csrf_token', ''));
        if ($csrfToken === '') {
            return ['error' => 'A valid security token is required for GovTalk evidence.'];
        }
        try {
            $session = new SessionAuthenticationService();
            $session->startSession();
            if (!$session->isValidCsrfToken($csrfToken)) {
                return ['error' => 'The security token expired. Refresh the page before trying again.'];
            }
            $deviceId = trim((string)AntiFraudService::instance($request)
                ->requestValue('Client-Device-ID'));
            $userId = $session->authenticatedUserId($deviceId !== '' ? $deviceId : null);
            if ($userId <= 0) {
                return ['error' => 'Sign in before downloading GovTalk evidence.'];
            }
            if ((new CardAccessFramework())->roleIdForUser($userId)
                !== RoleAssignmentService::ADMIN_ROLE_ID) {
                return ['error' => 'Only administrators can download GovTalk evidence.'];
            }

            return ['user_id' => $userId];
        } catch (Throwable) {
            return ['error' => 'GovTalk evidence authorisation could not be verified.'];
        }
    }

    private function error(string $message): ActionResultFramework
    {
        return new ActionResultFramework(false, [
            'companies.house.accounts.submission',
            'hmrc.ct600.submissions',
            'page.context',
        ], [[
            'type' => 'error',
            'message' => $message,
        ]]);
    }
}
