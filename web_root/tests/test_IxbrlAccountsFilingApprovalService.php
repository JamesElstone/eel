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
    }
);
