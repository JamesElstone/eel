<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class TaxRatesAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', ''));
        return match ($intent) {
            'refresh_hmrc_rates' => $this->refreshHmrcRates(),
            'toggle_tax_treatment_rule' => $this->toggleTreatmentRule($request),
            'update_tax_treatment_rule_review_status' => $this->updateTreatmentRuleReviewStatus($request),
            default => new ActionResultFramework(false, ['tax.rates'], [[
                'type' => 'error',
                'message' => 'Unknown tax rates action.',
            ]]),
        };
    }

    private function refreshHmrcRates(): ActionResultFramework
    {
        try {
            $result = (new CorporationTaxRateRuleService())->refreshFromHmrc();
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()], 'warnings' => []];
        }

        $messages = [];
        if (!empty($result['success'])) {
            $messages[] = [
                'type' => 'success',
                'message' => 'HMRC Corporation Tax rates refreshed: ' . (int)($result['refreshed_count'] ?? 0) . ' financial year rule(s) updated.',
            ];
            foreach ((array)($result['warnings'] ?? []) as $warning) {
                $messages[] = ['type' => 'warning', 'message' => (string)$warning];
            }
        } else {
            foreach ((array)($result['errors'] ?? ['HMRC rate refresh failed.']) as $error) {
                $messages[] = ['type' => 'error', 'message' => (string)$error];
            }
        }

        return new ActionResultFramework(!empty($result['success']), ['tax.rates', 'page.context'], $messages);
    }

    private function toggleTreatmentRule(RequestFramework $request): ActionResultFramework
    {
        $ruleId = max(0, (int)$request->input('rule_id', 0));
        $targetActive = (string)$request->input('target_is_active', '0') === '1';

        if ($ruleId <= 0) {
            return new ActionResultFramework(false, ['tax.treatment.rules'], [[
                'type' => 'error',
                'message' => 'Select a tax treatment rule before changing its status.',
            ]]);
        }

        if ((new CorporationTaxTreatmentRuleService())->setRuleActive($ruleId, $targetActive)) {
            return new ActionResultFramework(true, ['tax.treatment.rules', 'page.context'], [[
                'type' => 'success',
                'message' => $targetActive ? 'Tax treatment rule enabled.' : 'Tax treatment rule disabled.',
            ]]);
        }

        return new ActionResultFramework(false, ['tax.treatment.rules'], [[
            'type' => 'error',
            'message' => 'The tax treatment rule could not be updated.',
        ]]);
    }

    private function updateTreatmentRuleReviewStatus(RequestFramework $request): ActionResultFramework
    {
        $ruleId = max(0, (int)$request->input('rule_id', 0));
        $reviewStatus = strtolower(trim((string)$request->input('review_status', '')));

        if ($ruleId <= 0) {
            return new ActionResultFramework(false, ['tax.treatment.rules'], [[
                'type' => 'error',
                'message' => 'Select a tax treatment rule before changing its review status.',
            ]]);
        }

        if ((new CorporationTaxTreatmentRuleService())->setRuleReviewStatus($ruleId, $reviewStatus)) {
            return new ActionResultFramework(true, ['tax.treatment.rules', 'page.context'], [[
                'type' => 'success',
                'message' => 'Tax treatment rule review status updated.',
            ]]);
        }

        return new ActionResultFramework(false, ['tax.treatment.rules'], [[
            'type' => 'error',
            'message' => 'The tax treatment rule review status could not be updated.',
        ]]);
    }
}
