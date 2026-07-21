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
    /** @var list<string> */
    private const HMRC_CT_ARTIFACT_FACTS = [
        'hmrc_ct_rim.refresh',
        'hmrc_ct_rim.state',
        'hmrc.ct.computation.taxonomy',
        'ct.filing.mappings',
        'page.context',
    ];

    private ?Closure $hmrcCtRimRefresh;
    private ?Closure $hmrcCtComputationInstall;
    private ?Closure $hmrcCtComputationDelete;

    public function __construct(
        ?callable $hmrcCtRimRefresh = null,
        ?callable $hmrcCtComputationInstall = null,
        ?callable $hmrcCtComputationDelete = null,
    ) {
        $this->hmrcCtRimRefresh = $hmrcCtRimRefresh === null ? null : Closure::fromCallable($hmrcCtRimRefresh);
        $this->hmrcCtComputationInstall = $hmrcCtComputationInstall === null ? null : Closure::fromCallable($hmrcCtComputationInstall);
        $this->hmrcCtComputationDelete = $hmrcCtComputationDelete === null ? null : Closure::fromCallable($hmrcCtComputationDelete);
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', ''));
        return match ($intent) {
            'refresh_hmrc_rates' => $this->refreshHmrcRates(),
            'hmrc_ct_artifacts_refresh', 'hmrc_ct_rim_refresh' => $this->refreshHmrcCtArtifacts(),
            'hmrc_ct_rim_delete' => $this->deleteHmrcCtRim($request),
            'hmrc_ct_computation_delete' => $this->deleteHmrcCtComputation($request),
            'toggle_tax_treatment_rule' => $this->toggleTreatmentRule($request),
            'update_tax_treatment_rule_review_status' => $this->updateTreatmentRuleReviewStatus($request),
            default => new ActionResultFramework(false, ['tax.rates'], [[
                'type' => 'error',
                'message' => 'Unknown tax rates action.',
            ]]),
        };
    }

    private function refreshHmrcCtArtifacts(): ActionResultFramework
    {
        // Run the two independent artefact pipelines even when the first one fails.
        $rimResult = $this->runHmrcCtRimRefresh();
        $computationResult = $this->runHmrcCtComputationInstall();

        return new ActionResultFramework(
            !empty($rimResult['success']) && !empty($computationResult['success']),
            self::HMRC_CT_ARTIFACT_FACTS,
            array_merge(
                $this->hmrcCtRimMessages($rimResult),
                $this->hmrcCtComputationMessages($computationResult),
            ),
        );
    }

    private function runHmrcCtRimRefresh(): array
    {
        try {
            $result = $this->hmrcCtRimRefresh instanceof Closure
                ? ($this->hmrcCtRimRefresh)()
                : $this->performHmrcCtRimRefresh();
            if (!is_array($result)) {
                throw new RuntimeException('The HMRC CT600 RIM refresh returned an invalid response.');
            }
            return $result;
        } catch (Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    private function performHmrcCtRimRefresh(): array
    {
        $result = (new \eel_accounts\Service\HmrcCtRimCatalogueService())->refresh();
        if (empty($result['success'])) {
            return [
                'success' => false,
                'errors' => (array)($result['errors'] ?? ['HMRC CT600 RIM refresh failed.']),
            ];
        }

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
            if ($path === '') {
                continue;
            }
            try {
                if ($zipService->ensureExtracted($path)) {
                    $expanded++;
                }
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

        return [
            'success' => $downloadErrors === [],
            'catalogue_refreshed' => true,
            'updated_count' => (int)($result['updated_count'] ?? 0),
            'downloaded_count' => $downloaded,
            'expanded_count' => $expanded,
            'errors' => $downloadErrors,
        ];
    }

    private function hmrcCtRimMessages(array $result): array
    {
        $messages = [];
        if (!empty($result['success']) || !empty($result['catalogue_refreshed'])) {
            $messages[] = [
                'type' => !empty($result['success']) ? 'success' : 'warning',
                'message' => 'HMRC CT600 RIM catalogue refreshed: ' . (int)($result['updated_count'] ?? 0) . ' package(s) checked, ' . (int)($result['downloaded_count'] ?? 0) . ' package(s) downloaded and verified, and ' . (int)($result['expanded_count'] ?? 0) . ' package(s) expanded.',
            ];
        }
        foreach ((array)($result['errors'] ?? []) as $error) {
            $messages[] = ['type' => 'error', 'message' => (string)$error];
        }
        if (empty($result['success']) && $messages === []) {
            $messages[] = ['type' => 'error', 'message' => 'HMRC CT600 RIM refresh failed.'];
        }

        return $messages;
    }

    private function runHmrcCtComputationInstall(): array
    {
        try {
            $result = $this->hmrcCtComputationInstall instanceof Closure
                ? ($this->hmrcCtComputationInstall)()
                : (new \eel_accounts\Service\HmrcCtComputationDownloadService())->install();
            if (!is_array($result)) {
                throw new RuntimeException('The HMRC computation-taxonomy installer returned an invalid response.');
            }
            return $result;
        } catch (Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    private function hmrcCtComputationMessages(array $result): array
    {
        if (!empty($result['success'])) {
            $message = !empty($result['already_installed'])
                ? 'HMRC CT2024 computation taxonomy is already installed and verified'
                : 'HMRC CT2024 computation taxonomy installed and verified';
            $message .= ': ' . (int)($result['file_count'] ?? 0) . ' file(s) and ' . (int)($result['concept_count'] ?? 0) . ' concept(s) catalogued';
            $profileId = (int)($result['profile_id'] ?? 0);
            if ($profileId > 0) {
                $message .= '; mapping profile #' . $profileId . ' prepared';
            }

            return [['type' => 'success', 'message' => $message . '.']];
        }

        $messages = [];
        foreach ((array)($result['errors'] ?? ['HMRC CT2024 computation-taxonomy installation failed.']) as $error) {
            $messages[] = ['type' => 'error', 'message' => (string)$error];
        }
        if ($messages === []) {
            $messages[] = ['type' => 'error', 'message' => 'HMRC CT2024 computation-taxonomy installation failed.'];
        }
        return $messages;
    }

    private function deleteHmrcCtRim(RequestFramework $request): ActionResultFramework
    {
        try {
            $result = (new \eel_accounts\Service\HmrcCtRimPackageDeleteService())->delete(max(0, (int)$request->input('package_id', 0)));
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }
        if (!empty($result['success'])) {
            return new ActionResultFramework(true, ['hmrc_ct_rim.refresh', 'hmrc_ct_rim.state', 'page.context'], [[
                'type' => 'success',
                'message' => 'HMRC CT600 RIM package and local files deleted.',
            ]]);
        }
        return new ActionResultFramework(false, ['hmrc_ct_rim.state'], [[
            'type' => 'error',
            'message' => (string)(($result['errors'] ?? ['The HMRC CT600 RIM package could not be deleted.'])[0] ?? 'The HMRC CT600 RIM package could not be deleted.'),
        ]]);
    }

    private function deleteHmrcCtComputation(RequestFramework $request): ActionResultFramework
    {
        try {
            $packageId = max(0, (int)$request->input('package_id', 0));
            $result = $this->hmrcCtComputationDelete instanceof Closure
                ? ($this->hmrcCtComputationDelete)($packageId)
                : (new \eel_accounts\Service\HmrcCtComputationPackageDeleteService())->delete($packageId);
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }
        if (!empty($result['success'])) {
            return new ActionResultFramework(true, ['hmrc.ct.computation.taxonomy', 'ct.filing.mappings', 'page.context'], [[
                'type' => 'success',
                'message' => 'HMRC computation-taxonomy package, catalogue records and local files deleted.',
            ]]);
        }
        return new ActionResultFramework(false, ['hmrc.ct.computation.taxonomy'], [[
            'type' => 'error',
            'message' => (string)(($result['errors'] ?? ['The HMRC computation-taxonomy package could not be deleted.'])[0] ?? 'The HMRC computation-taxonomy package could not be deleted.'),
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
