<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class VehicleAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = new \eel_accounts\Service\AccountingContextService();
        $companyId = HelperFramework::sanitiseId($request->input('company_id', null), $context->companyId($request));
        $intent = trim((string)$request->input('intent', ''));
        $service = new \eel_accounts\Service\VehicleService();

        try {
            $result = match ($intent) {
                'save_vehicle_details' => $service->saveVehicleDetails(
                    $companyId,
                    (int)$request->input('asset_id', 0),
                    $request->postValues(),
                    (int)$request->input('default_bank_nominal_id', 0),
                    'vehicle_register'
                ),
                default => ['success' => false, 'errors' => ['Unknown vehicle action.']],
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        return new ActionResultFramework(
            !empty($result['success']),
            ['vehicle.register', 'asset.register', 'asset.tax', 'year.end.checklist', 'year.end.state'],
            $this->flashMessages($result),
            [],
            []
        );
    }

    private function flashMessages(array $result): array
    {
        if (empty($result['success'])) {
            return array_map(
                static fn(mixed $error): array => ['type' => 'error', 'message' => (string)$error],
                (array)($result['errors'] ?? ['The vehicle action could not be completed.'])
            );
        }

        return array_map(
            static fn(mixed $message): array => ['type' => 'success', 'message' => (string)$message],
            (array)($result['messages'] ?? ['Vehicle details saved.'])
        );
    }
}
