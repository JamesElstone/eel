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
        if (!in_array($intent, ['declare_dividend', 'declare_dividend_from_transaction'], true)) {
            return ActionResultFramework::none();
        }

        try {
            $service = new \eel_accounts\Service\DividendService();
            $result = $intent === 'declare_dividend_from_transaction'
                ? $service->declareDividendFromTransaction(
                    (int)$request->input('transaction_id', 0),
                    (int)$request->input('company_id', 0),
                    (int)$request->input('accounting_period_id', 0)
                )
                : $service->declareDividend([
                    'company_id' => (int)$request->input('company_id', 0),
                    'accounting_period_id' => (int)$request->input('accounting_period_id', 0),
                    'declaration_date' => (string)$request->input('declaration_date', ''),
                    'amount' => (string)$request->input('amount', ''),
                    'reconciliation_transaction_id' => (int)$request->input('reconciliation_transaction_id', 0),
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
                'message' => !empty($result['already_exists'])
                    ? 'Dividend declaration already exists for this transaction.'
                    : (!empty($result['posted'])
                        ? 'Dividend declaration posted.'
                        : 'Dividend declaration saved as draft pending reconciliation.'),
            ];
        } else {
            foreach ((array)($result['errors'] ?? ['Dividend declaration could not be posted.']) as $error) {
                $flashMessages[] = [
                    'type' => 'error',
                    'message' => (string)$error,
                ];
            }
        }

        $context = [
            'month_key' => (string)$request->input('month_key', ''),
            'category_filter' => (string)$request->input('category_filter', ''),
        ];
        $query = array_filter($context, static fn(string $value): bool => trim($value) !== '');
        $query['company_id'] = (int)$request->input('company_id', 0);
        $query['accounting_period_id'] = (int)$request->input('accounting_period_id', 0);

        return new ActionResultFramework(
            $success,
            ['transactions.imported', 'page.context', 'dividend.capacity', 'dividend.declare', 'dividend.history', 'dividend.warnings', 'trial.balance.state'],
            $flashMessages,
            $query,
            $context
        );
    }
}
