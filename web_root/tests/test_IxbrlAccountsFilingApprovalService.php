<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlAccountsFilingApprovalService::class,
    static function (
        GeneratedServiceClassTestHarness $h,
        \eel_accounts\Service\IxbrlAccountsFilingApprovalService $service
    ): void {
        $h->check($service::class, 'fails closed without an accounting context', static function () use ($h, $service): void {
            $status = $service->status(0, 0);
            $h->assertSame('absent', (string)($status['state'] ?? ''));
            $h->assertSame(false, (bool)($status['can_approve'] ?? true));
            $h->assertTrue(str_contains(implode(' ', (array)($status['errors'] ?? [])), 'Select a company'));
        });

        $h->check($service::class, 'has the immutable approval persistence schema', static function () use ($h): void {
            $h->assertSame(true, \InterfaceDB::tableExists('ixbrl_accounts_filing_approvals'));
            $h->assertSame(true, \InterfaceDB::tableExists('ct_period_filing_bases'));
            $h->assertSame(true, \InterfaceDB::columnExists('ixbrl_generation_runs', 'filing_approval_id'));
            $h->assertSame(true, \InterfaceDB::columnExists('ixbrl_generation_runs', 'filing_approval_hash'));
        });

        $h->check($service::class, 'requires an approver before starting the atomic build', static function () use ($h, $service): void {
            try {
                $service->approveAndBuildFacts(1, 1, '');
                $h->assertTrue(false, 'Expected an approver validation exception.');
            } catch (RuntimeException $exception) {
                $h->assertTrue(str_contains($exception->getMessage(), 'identify its approver'));
            }
        });

        $h->check($service::class, 'explains a report-only approval mismatch as a report-generation change', static function () use ($h, $service): void {
            $method = new ReflectionMethod($service, 'staleApprovalErrors');
            $method->setAccessible(true);
            $stored = [
                'company' => ['id' => 1],
                'accounts_report' => ['basis_version' => 'ixbrl-accounts-report-v0', 'basis_hash' => 'old'],
            ];
            $current = [
                'company' => ['id' => 1],
                'accounts_report' => ['basis_version' => 'ixbrl-accounts-report-v3', 'basis_hash' => 'new'],
            ];
            $errors = $method->invoke($service, ['basis_json' => json_encode($stored)], $current);

            $h->assertSame([
                'The Accounts Report generation basis changed. Reload the PHP web runtime if this is a deployment, then approve the current filing basis again.',
            ], $errors);
        });

        $h->check($service::class, 'versions the filing contracts that freeze the selected approving director', static function () use ($h, $service): void {
            $h->assertSame('accounts-filing-approval-v6', $service::BASIS_VERSION);
            $h->assertSame('ct-period-filing-model-v10', $service::CT_BASIS_VERSION);
        });

        $h->check($service::class, 'preserves the legacy CT600 authorisation basis shape', static function () use ($h, $service): void {
            $method = new ReflectionMethod($service, 'authorisationBasis');
            $method->setAccessible(true);
            $includeMethod = new ReflectionMethod($service, 'shouldIncludeAuthorisationBasis');
            $includeMethod->setAccessible(true);

            $legacyAuthorisation = [
                'declarant_status' => 'Director',
                'original_unfiled_confirmed' => 1,
                'authority_confirmed' => 1,
                'declaration_confirmed' => 1,
                'saved_at' => '2026-07-30 11:02:45',
            ];
            $basis = $method->invoke($service, $legacyAuthorisation);

            $h->assertSame([
                'declarant_status' => 'Director',
                'original_unfiled_confirmed' => true,
                'authority_confirmed' => true,
                'declaration_confirmed' => true,
            ], $basis);
            $h->assertSame(false, (bool)$includeMethod->invoke(
                $service,
                $legacyAuthorisation,
                ['basis_json' => '{"basis_version":"accounts-filing-approval-v6"}']
            ));
            $h->assertSame(true, (bool)$includeMethod->invoke(
                $service,
                $legacyAuthorisation,
                ['basis_json' => '{"corporation_tax_return_authorisation":{"declarant_status":"Director"}}']
            ));
        });

        $h->check($service::class, 'adds the frozen person and capacity only for structured authorisations', static function () use ($h, $service): void {
            $method = new ReflectionMethod($service, 'authorisationBasis');
            $method->setAccessible(true);
            $includeMethod = new ReflectionMethod($service, 'shouldIncludeAuthorisationBasis');
            $includeMethod->setAccessible(true);

            $authorisation = [
                'declarant_name' => 'Jane Smith',
                'declarant_status' => 'Authorised Agent',
                'declarant_party_id' => 12,
                'declarant_director_id' => null,
                'declarant_role_id' => 34,
                'saved_at' => '2026-07-30 12:30:00',
            ];
            $basis = $method->invoke($service, $authorisation);

            $h->assertSame('Jane Smith', (string)($basis['declarant_name'] ?? ''));
            $h->assertSame('Authorised Agent', (string)($basis['declarant_status'] ?? ''));
            $h->assertSame(12, (int)($basis['declarant_party_id'] ?? 0));
            $h->assertSame(34, (int)($basis['declarant_role_id'] ?? 0));
            $h->assertSame('2026-07-30 12:30:00', (string)($basis['declaration_at'] ?? ''));
            $h->assertSame(true, (bool)$includeMethod->invoke($service, $authorisation, null));
        });
    }
);
