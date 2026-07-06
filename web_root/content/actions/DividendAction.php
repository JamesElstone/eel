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
        if (!in_array($intent, ['declare_dividend', 'declare_dividend_from_transaction', 'void_dividend', 'save_dividend_reserve_review'], true)) {
            return ActionResultFramework::none();
        }

        try {
            if ($intent === 'save_dividend_reserve_review') {
                (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
                    (int)$request->input('company_id', 0),
                    (int)$request->input('accounting_period_id', 0),
                    'save the dividend reserve review for this period'
                );
            }

            $service = new \eel_accounts\Service\DividendService();
            $actor = $this->actor();
            $result = match ($intent) {
                'save_dividend_reserve_review' => (new \eel_accounts\Service\DividendReserveClassificationService())->saveReview(
                    (int)$request->input('company_id', 0),
                    (int)$request->input('accounting_period_id', 0),
                    (array)$request->post('treatment', []),
                    $actor,
                    (string)$request->input('as_at_date', '')
                ),
                'declare_dividend_from_transaction' => $service->declareDividendFromTransaction(
                    (int)$request->input('transaction_id', 0),
                    (int)$request->input('company_id', 0),
                    (int)$request->input('accounting_period_id', 0),
                    $actor
                ),
                'void_dividend' => $service->voidDividend(
                    (int)$request->input('company_id', 0),
                    (int)$request->input('accounting_period_id', 0),
                    (int)$request->input('journal_id', 0),
                    $actor
                ),
                default => $service->declareDividend([
                    'company_id' => (int)$request->input('company_id', 0),
                    'accounting_period_id' => (int)$request->input('accounting_period_id', 0),
                    'declaration_date' => (string)$request->input('declaration_date', ''),
                    'amount' => (string)$request->input('amount', ''),
                    'reconciliation_transaction_id' => (int)$request->input('reconciliation_transaction_id', 0),
                    'description' => (string)$request->input('description', ''),
                    'settlement_target' => (string)$request->input('settlement_target', ''),
                    'changed_by' => $actor,
                ]),
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        $success = !empty($result['success']);
        $flashMessages = [];
        if ($success) {
            $flashMessages[] = [
                'type' => 'success',
                'message' => $intent === 'save_dividend_reserve_review'
                    ? 'Dividend reserve review saved.'
                    : ($intent === 'void_dividend'
                        ? 'Dividend declaration voided and reversal recorded.'
                        : (!empty($result['already_exists'])
                        ? 'Dividend declaration already exists for this transaction.'
                        : (!empty($result['posted'])
                            ? 'Dividend declaration posted.'
                            : 'Dividend declaration saved as draft pending reconciliation.'))),
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
            ['transactions.imported', 'page.context', 'dividend.capacity', 'dividend.reserve_review', 'dividend.declare', 'dividend.history', 'dividend.vouchers', 'dividend.warnings', 'trial.balance.state'],
            $flashMessages,
            $query,
            $context
        );
    }

    private function actor(): string
    {
        try {
            $session = new SessionAuthenticationService();
            $session->startSession();
            $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
            $userId = $session->authenticatedUserId($deviceId !== '' ? $deviceId : null);
            if ($userId > 0) {
                return 'user:' . $userId;
            }
        } catch (Throwable) {
        }

        return 'web_app';
    }
}
