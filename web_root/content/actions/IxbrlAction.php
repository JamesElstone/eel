<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class IxbrlAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', $request->input('global_action', '')));
        $companyId = (int)$request->input('company_id', 0);
        $accountingPeriodId = (int)$request->input('accounting_period_id', 0);
        $changedFacts = ['ixbrl.readiness', 'ixbrl.trial.balance', 'ixbrl.accounts.mapping', 'ixbrl.facts.preview', 'ixbrl.generation', 'page.context'];

        try {
            $readiness = (new IxbrlReadinessService())->getReadiness($companyId, $accountingPeriodId);
            if (empty($readiness['can_build_facts'])) {
                return $this->result(false, (array)($readiness['blocking_errors'] ?? ['iXBRL readiness checks failed.']), $changedFacts);
            }

            $result = match ($intent) {
                'build_ixbrl_facts' => $this->buildFacts($companyId, $accountingPeriodId),
                'generate_ixbrl_preview' => $this->generatePreview($companyId, $accountingPeriodId),
                default => ['success' => false, 'errors' => ['Unknown iXBRL builder action.']],
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        return $this->result(!empty($result['success']), (array)($result['errors'] ?? []), $changedFacts, (array)($result['messages'] ?? []));
    }

    private function buildFacts(int $companyId, int $accountingPeriodId): array
    {
        $runId = (new IxbrlFactBuilderService())->buildFacts($companyId, $accountingPeriodId);

        return ['success' => true, 'errors' => [], 'messages' => ['iXBRL facts built for run #' . $runId . '.']];
    }

    private function generatePreview(int $companyId, int $accountingPeriodId): array
    {
        $readiness = (new IxbrlReadinessService())->getReadiness($companyId, $accountingPeriodId);
        if (empty($readiness['can_generate'])) {
            (new IxbrlFactBuilderService())->buildFacts($companyId, $accountingPeriodId);
        }

        $result = (new IxbrlRenderService())->generatePreview($companyId, $accountingPeriodId);
        if (!empty($result['success'])) {
            $result['messages'] = ['iXBRL preview generated.'];
        }

        return $result;
    }

    private function result(bool $success, array $errors, array $changedFacts, array $messages = []): ActionResultFramework
    {
        $flash = [];
        if ($success) {
            foreach ($messages !== [] ? $messages : ['iXBRL builder updated.'] as $message) {
                $flash[] = ['type' => 'success', 'message' => (string)$message];
            }
        } else {
            foreach ($errors !== [] ? $errors : ['iXBRL builder action failed.'] as $error) {
                $flash[] = ['type' => 'error', 'message' => (string)$error];
            }
        }

        return new ActionResultFramework($success, $changedFacts, $flash);
    }
}
