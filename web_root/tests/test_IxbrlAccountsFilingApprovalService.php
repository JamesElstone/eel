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

        $h->check($service::class, 'removes superseded unsubmitted history after verifying a replacement approval', static function () use ($h): void {
            $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes'
                . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                . DIRECTORY_SEPARATOR . 'IxbrlAccountsFilingApprovalService.php');
            $verify = strpos($source, '$this->verifyCurrentCandidate');
            $cleanup = strpos($source, 'IxbrlUntransmittedHistoryCleanupService');

            $h->assertTrue($verify !== false);
            $h->assertTrue($cleanup !== false);
            $h->assertTrue((int)$cleanup > (int)$verify);
            $h->assertTrue(str_contains($source, "'history_cleanup' => \$historyCleanup"));
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
                'The Accounts Report generation basis changed. Reload the PHP web runtime if this is a deployment, then approve the current statutory-accounts basis again.',
            ], $errors);
        });

        $h->check($service::class, 'versions the separated accounts and CT filing contracts', static function () use ($h, $service): void {
            $h->assertSame('accounts-filing-approval-v9', $service::BASIS_VERSION);
            $h->assertSame('ct-period-filing-model-v11', $service::CT_BASIS_VERSION);
        });

        $h->check($service::class, 'adapts only a verified legacy CH-dependent report onto the neutral accounts basis', static function () use ($h, $service): void {
            $canonical = new ReflectionMethod($service, 'canonicalJson');
            $canonical->setAccessible(true);
            $projection = new ReflectionMethod($service, 'accountsBasisProjection');
            $projection->setAccessible(true);
            $legacyVerifier = new ReflectionMethod($service, 'verifiedLegacyAccountsBasisProjection');
            $legacyVerifier->setAccessible(true);

            $legacyCompaniesHouseFiling = [
                'filing_kind' => 'original',
                'filing_reason' => 'No previous accepted filing.',
                'filing_evidence' => ['accepted_submission_count' => 0],
                'correction_required' => false,
                'check_code' => 'companies_house_revised_accounts_readiness',
                'approval_basis_version' => 'year-end-approval-v2',
                'approval_basis_hash' => str_repeat('b', 64),
                'approved_at' => '2026-07-31 10:00:00',
                'approved_by' => 'user:7',
            ];
            $neutralReportBasis = [
                'basis_version' => 'ixbrl-taxonomy-profile-v1',
                'company' => ['id' => 49, 'company_number' => '01234567'],
                'period' => ['id' => 79, 'period_end' => '2025-12-31'],
                'disclosures' => ['average_number_employees' => 1],
            ];
            $neutralReportHash = hash('sha256', (string)$canonical->invoke(
                $service,
                $neutralReportBasis
            ));
            $legacyReportBasis = $neutralReportBasis;
            $legacyReportBasis['companies_house_filing'] = $legacyCompaniesHouseFiling;
            $legacyReportHash = hash('sha256', (string)$canonical->invoke(
                $service,
                $legacyReportBasis
            ));
            $legacyBasis = [
                'basis_version' => 'accounts-filing-approval-v8',
                'company' => ['id' => 49],
                'accounts_facts' => ['turnover' => 100.0],
                'accounting_period' => ['id' => 79],
                'year_end_lock' => ['id' => 5],
                'disclosures' => ['id' => 7],
                'accounts_report' => [
                    'basis_version' => 'ixbrl-accounts-report-v8',
                    'basis_hash' => $legacyReportHash,
                ],
                'corporation_tax_return_authorisation' => ['declarant_status' => 'Director'],
                'corporation_tax_filing_scope' => ['answers' => ['ct600b' => 'no']],
                'ct_periods' => [['id' => 6]],
                'filing_identity' => ['utr' => '1234567890'],
            ];
            $currentReportReference = [
                'basis_version' => \eel_accounts\Service\IxbrlAccountsReportService::BASIS_VERSION,
                'basis_hash' => $neutralReportHash,
            ];
            $candidate = [
                'basis' => array_replace(
                    (array)$projection->invoke($service, $legacyBasis),
                    ['accounts_report' => $currentReportReference]
                ),
                'report' => [
                    'basis' => $neutralReportBasis,
                    'basis_hash' => $neutralReportHash,
                ],
            ];

            $projected = $legacyVerifier->invoke(
                $service,
                $legacyBasis,
                $candidate,
                $legacyCompaniesHouseFiling
            );

            $h->assertTrue(is_array($projected));
            $h->assertSame('accounts-filing-approval-v9', (string)$projected['basis_version']);
            $h->assertSame($currentReportReference, (array)$projected['accounts_report']);
            $h->assertSame(false, array_key_exists('corporation_tax_return_authorisation', $projected));
            $h->assertSame(false, array_key_exists('corporation_tax_filing_scope', $projected));
            $h->assertSame(false, array_key_exists('ct_periods', $projected));
            $h->assertSame(false, array_key_exists('filing_identity', $projected));

            $tamperedClassification = $legacyCompaniesHouseFiling;
            $tamperedClassification['filing_kind'] = 'revised';
            $h->assertSame(null, $legacyVerifier->invoke(
                $service,
                $legacyBasis,
                $candidate,
                $tamperedClassification
            ));
            $tamperedBasis = $legacyBasis;
            $tamperedBasis['accounts_report']['basis_hash'] = str_repeat('f', 64);
            $h->assertSame(null, $legacyVerifier->invoke(
                $service,
                $tamperedBasis,
                $candidate,
                $legacyCompaniesHouseFiling
            ));
            foreach (['ixbrl-accounts-report-v4', 'ixbrl-accounts-report-v7', 'ixbrl-accounts-report-v9'] as $version) {
                $unsupportedLegacyBasis = $legacyBasis;
                $unsupportedLegacyBasis['accounts_report']['basis_version'] = $version;
                $h->assertSame(null, $legacyVerifier->invoke(
                    $service,
                    $unsupportedLegacyBasis,
                    $candidate,
                    $legacyCompaniesHouseFiling
                ));
            }
        });

        $h->check($service::class, 'prepares versioned CT bases separately from accounts approval', static function () use ($h): void {
            $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes'
                . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                . DIRECTORY_SEPARATOR . 'IxbrlAccountsFilingApprovalService.php');
            $accountsCandidate = strstr($source, 'private function candidate(');
            $accountsCandidate = strstr((string)$accountsCandidate, 'private function calculationPeriods', true);
            $h->assertFalse(str_contains((string)$accountsCandidate, 'Ct600ReturnAuthorisationService'));
            $h->assertFalse(str_contains((string)$accountsCandidate, 'CorporationTaxFilingScopeService'));
            $h->assertFalse(str_contains((string)$accountsCandidate, "'ct_periods'"));
            $h->assertTrue(str_contains($source, 'public function prepareHmrcCtPeriodFilingBases('));
            $h->assertTrue(str_contains($source, 'AND basis_hash = :basis_hash'));
            $h->assertFalse(str_contains($source, 'UPDATE ct_period_filing_bases SET basis_json'));
            $schemaReady = strstr($source, 'private function schemaReady(): bool');
            $schemaReady = strstr((string)$schemaReady, 'private function assertSchemaReady', true);
            $h->assertFalse(str_contains((string)$schemaReady, "tableExists('ct_period_filing_bases')"));
        });

        $h->check($service::class, 'keeps approval stages atomic while allowing an outer workflow transaction', static function () use ($h): void {
            $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes'
                . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                . DIRECTORY_SEPARATOR . 'IxbrlAccountsFilingApprovalService.php');
            $h->assertTrue(substr_count($source, '\\InterfaceDB::transaction') >= 3);
            $h->assertFalse(str_contains($source, 'must own its transaction'));
            $h->assertFalse(str_contains($source, 'HMRC filing-basis preparation must own'));
            $h->assertTrue(str_contains(
                $source,
                "(string)(\$matching['basis_version'] ?? '') === self::BASIS_VERSION"
            ));
        });

    }
);
