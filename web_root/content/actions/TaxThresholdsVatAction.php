<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class TaxThresholdsVatAction implements ActionInterfaceFramework
{
    private ?Closure $refresh;

    public function __construct(?callable $refresh = null)
    {
        $this->refresh = $refresh === null ? null : Closure::fromCallable($refresh);
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        if (trim((string)$request->input('intent', '')) !== 'refresh_hmrc_vat_thresholds') {
            return new ActionResultFramework(false, ['vat.threshold.rules'], [[
                'type' => 'error',
                'message' => 'Unknown VAT thresholds action.',
            ]]);
        }

        try {
            $result = $this->refresh instanceof Closure
                ? ($this->refresh)()
                : (new \eel_accounts\Service\VatThresholdRuleService())->refreshFromHmrc();
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
                    ? 'HMRC VAT thresholds are already up to date.'
                    : 'HMRC VAT thresholds refreshed: ' . (int)($result['refreshed_count'] ?? 0) . ' sourced rule(s) updated.',
            ];
        } else {
            foreach ((array)($result['errors'] ?? ['HMRC VAT threshold refresh failed.']) as $error) {
                $messages[] = ['type' => 'error', 'message' => (string)$error];
            }
        }
        foreach ((array)($result['warnings'] ?? []) as $warning) {
            $messages[] = ['type' => 'warning', 'message' => (string)$warning];
        }

        return new ActionResultFramework(!empty($result['success']), ['vat.threshold.rules', 'page.context'], $messages);
    }
}
