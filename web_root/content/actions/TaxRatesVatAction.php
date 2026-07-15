<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class TaxRatesVatAction implements ActionInterfaceFramework
{
    private ?Closure $refresh;

    public function __construct(?callable $refresh = null)
    {
        $this->refresh = $refresh === null ? null : Closure::fromCallable($refresh);
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        if (trim((string)$request->input('intent', '')) !== 'refresh_hmrc_vat_rates') {
            return new ActionResultFramework(false, ['vat.rate.rules'], [[
                'type' => 'error',
                'message' => 'Unknown VAT rates action.',
            ]]);
        }

        try {
            $result = $this->refresh instanceof Closure
                ? ($this->refresh)()
                : (new \eel_accounts\Service\VatRateRuleService())->refreshFromHmrc();
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()], 'warnings' => []];
        }

        return $this->result($result);
    }

    private function result(array $result): ActionResultFramework
    {
        $messages = [];
        if (!empty($result['success'])) {
            $messages[] = [
                'type' => 'success',
                'message' => !empty($result['unchanged'])
                    ? 'HMRC VAT rates are already up to date.'
                    : 'HMRC VAT rates refreshed: ' . (int)($result['refreshed_count'] ?? 0) . ' sourced rule(s) updated.',
            ];
        } else {
            foreach ((array)($result['errors'] ?? ['HMRC VAT rate refresh failed.']) as $error) {
                $messages[] = ['type' => 'error', 'message' => (string)$error];
            }
        }
        foreach ((array)($result['warnings'] ?? []) as $warning) {
            $messages[] = ['type' => 'warning', 'message' => (string)$warning];
        }

        return new ActionResultFramework(!empty($result['success']), ['vat.rate.rules', 'page.context'], $messages);
    }
}
