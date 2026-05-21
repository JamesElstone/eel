<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class HmrcSubmissionAuthorisationService
{
    public function validate(RequestFramework $request, int $companyId, string $intent): array
    {
        if (!in_array($intent, ['hmrc_submit_test', 'hmrc_submit_live'], true)) {
            return ['success' => true, 'errors' => []];
        }

        $confirmed = in_array((string)$request->input('hmrc_authority_confirmed', ''), ['1', 'yes', 'on'], true);
        if (!$confirmed) {
            return [
                'success' => false,
                'errors' => ['Confirm that you are authorised to submit on behalf of the selected company.'],
            ];
        }

        if ($companyId <= 0) {
            return [
                'success' => false,
                'errors' => ['Select a company before confirming HMRC submission authority.'],
            ];
        }

        return ['success' => true, 'errors' => []];
    }

    public function recordConfirmation(int $submissionId, int $companyId): void
    {
        if ($submissionId <= 0) {
            return;
        }

        $userId = (new SessionAuthenticationService())->authenticatedUserId();
        (new HmrcCorporationTaxSubmissionService())->event(
            $submissionId,
            'info',
            'HMRC submission authority confirmed.',
            [
                'company_id' => $companyId,
                'user_id' => $userId > 0 ? $userId : null,
                'confirmed_at' => gmdate('Y-m-d H:i:s') . 'Z',
            ]
        );
    }
}
