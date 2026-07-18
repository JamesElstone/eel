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
            'hmrc_ct_rim_refresh' => $this->refreshHmrcCtRim(),
            'hmrc_ct_rim_download' => $this->downloadHmrcCtRim($request),
            'toggle_tax_treatment_rule' => $this->toggleTreatmentRule($request),
            'update_tax_treatment_rule_review_status' => $this->updateTreatmentRuleReviewStatus($request),
            default => new ActionResultFramework(false, ['tax.rates'], [[
                'type' => 'error',
                'message' => 'Unknown tax rates action.',
            ]]),
        };
    }

    private function refreshHmrcCtRim(): ActionResultFramework
    {
        try {
            $result = (new \eel_accounts\Service\HmrcCtRimCatalogueService())->refresh();
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        if (!empty($result['success'])) {
            return new ActionResultFramework(true, ['hmrc_ct_rim.refresh', 'hmrc_ct_rim.state', 'page.context'], [[
                'type' => 'success',
                'message' => 'HMRC CT600 RIM metadata refreshed: ' . (int)($result['updated_count'] ?? 0) . ' package(s) checked.',
            ]]);
        }

        return new ActionResultFramework(false, ['hmrc_ct_rim.state'], [[
            'type' => 'error',
            'message' => (string)(($result['errors'] ?? ['HMRC CT600 RIM refresh failed.'])[0] ?? 'HMRC CT600 RIM refresh failed.'),
        ]]);
    }

    private function downloadHmrcCtRim(RequestFramework $request): never
    {
        $packageId = max(0, (int)$request->input('package_id', 0));
        $result = (new \eel_accounts\Service\HmrcCtRimDownloadService())->download($packageId);
        if (empty($result['success'])) {
            header('Content-Type: text/plain; charset=utf-8', true, 409);
            echo (string)(($result['errors'] ?? ['The HMRC CT600 RIM package could not be downloaded.'])[0] ?? 'The HMRC CT600 RIM package could not be downloaded.');
            exit;
        }

        $path = (string)($result['path'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            header('Content-Type: text/plain; charset=utf-8', true, 404);
            echo 'The verified HMRC CT600 RIM package was not found.';
            exit;
        }
        $filename = basename((string)($result['filename'] ?? 'hmrc-ct600-rim.zip'));
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . (string)(filesize($path) ?: 0));
        readfile($path);
        exit;
    }

    private function refreshHmrcRates(): ActionResultFramework
    {
        try {
            $result = (new \eel_accounts\Service\TaxRateRuleService())->refreshFromHmrc();
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()], 'warnings' => []];
        }

        $messages = [];
        if (!empty($result['success'])) {
            $messages[] = [
                'type' => 'success',
                'message' => 'HMRC tax and allowance rates refreshed: ' . (int)($result['refreshed_count'] ?? 0) . ' sourced rule(s) updated.',
            ];
            foreach ((array)($result['warnings'] ?? []) as $warning) {
                $messages[] = ['type' => 'warning', 'message' => (string)$warning];
            }
        } else {
            foreach ((array)($result['errors'] ?? ['HMRC rate refresh failed.']) as $error) {
                $messages[] = ['type' => 'error', 'message' => (string)$error];
            }
        }

        return new ActionResultFramework(!empty($result['success']), [
            'tax.rates',
            'page.context',
            'ixbrl.readiness',
            'ixbrl.disclosures',
            'ixbrl.facts.preview',
            'ixbrl.generation',
        ], $messages);
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

        if ((new \eel_accounts\Service\CorporationTaxTreatmentRuleService())->setRuleActive($ruleId, $targetActive)) {
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

        if ((new \eel_accounts\Service\CorporationTaxTreatmentRuleService())->setRuleReviewStatus($ruleId, $reviewStatus)) {
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
