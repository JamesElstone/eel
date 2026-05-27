<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class DividendAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', $request->input('global_action', '')));
        if ($intent !== 'declare_dividend') {
            return ActionResultFramework::none();
        }

        try {
            $result = (new DividendService())->declareDividend([
                'company_id' => (int)$request->input('company_id', 0),
                'accounting_period_id' => (int)$request->input('accounting_period_id', 0),
                'declaration_date' => (string)$request->input('declaration_date', ''),
                'amount' => (string)$request->input('amount', ''),
                'description' => (string)$request->input('description', ''),
                'settlement_target' => (string)$request->input('settlement_target', ''),
            ]);
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        $success = !empty($result['success']);
        $flashMessages = [];
        if ($success) {
            $flashMessages[] = [
                'type' => 'success',
                'message' => 'Dividend declaration posted.',
            ];
        } else {
            foreach ((array)($result['errors'] ?? ['Dividend declaration could not be posted.']) as $error) {
                $flashMessages[] = [
                    'type' => 'error',
                    'message' => (string)$error,
                ];
            }
        }

        return new ActionResultFramework(
            $success,
            ['dividend.capacity', 'dividend.declare', 'dividend.history', 'dividend.warnings', 'trial.balance.state'],
            $flashMessages
        );
    }
}
