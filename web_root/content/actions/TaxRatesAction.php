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
            $downloaded = 0;
            $expanded = 0;
            $downloadErrors = [];
            $zipService = new \eel_accounts\Service\HmrcCtRimZipService();
            $schemaService = new \eel_accounts\Service\HmrcCtRimSchemaService();
            foreach ((array)($result['packages'] ?? []) as $package) {
                if (!is_array($package) || !in_array((string)($package['package_state'] ?? ''), ['not_downloaded', 'failed'], true)) {
                    continue;
                }
                $download = (new \eel_accounts\Service\HmrcCtRimDownloadService())->download((int)($package['id'] ?? 0));
                if (!empty($download['success'])) {
                    $downloaded++;
                    continue;
                }
                foreach ((array)($download['errors'] ?? ['The HMRC CT600 RIM package could not be downloaded.']) as $error) {
                    $downloadErrors[] = (string)$error;
                }
            }
            foreach ((array)($result['packages'] ?? []) as $package) {
                if (!is_array($package) || in_array((string)($package['package_state'] ?? ''), ['not_downloaded', 'failed'], true)) {
                    continue;
                }
                $path = trim((string)($package['local_path'] ?? ''));
                if ($path === '') { continue; }
                try {
                    if ($zipService->ensureExtracted($path)) { $expanded++; }
                    $analysis = $schemaService->applyApplicability((int)($package['id'] ?? 0), $zipService->extractionDirectory($path), (string)($package['form_version'] ?? ''));
                    if (in_array((string)($analysis['status'] ?? ''), ['failed', 'ambiguous'], true)) {
                        $error = (string)($analysis['error'] ?? 'The HMRC CT600 applicability could not be determined.');
                        \InterfaceDB::prepareExecute('UPDATE hmrc_ct_rim_packages SET package_state = \'failed\', verification_error = :error WHERE id = :id', ['error' => $error, 'id' => (int)($package['id'] ?? 0)]);
                        $downloadErrors[] = $error;
                    }
                } catch (Throwable $exception) {
                    $downloadErrors[] = $exception->getMessage();
                }
            }
            $schemaService->recalculateWindows();

            $messages = [[
                'type' => 'success',
                'message' => 'HMRC CT600 RIM catalogue refreshed: ' . (int)($result['updated_count'] ?? 0) . ' package(s) checked, ' . $downloaded . ' package(s) downloaded and verified, and ' . $expanded . ' package(s) expanded.',
            ]];
            foreach ($downloadErrors as $error) {
                $messages[] = ['type' => 'error', 'message' => $error];
            }
            return new ActionResultFramework(true, ['hmrc_ct_rim.refresh', 'hmrc_ct_rim.state', 'page.context'], $messages);
        }

        return new ActionResultFramework(false, ['hmrc_ct_rim.state'], [[
            'type' => 'error',
            'message' => (string)(($result['errors'] ?? ['HMRC CT600 RIM refresh failed.'])[0] ?? 'HMRC CT600 RIM refresh failed.'),
        ]]);
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
