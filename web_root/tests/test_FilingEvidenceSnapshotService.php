<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\FilingEvidenceSnapshotService::class,
    static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\FilingEvidenceSnapshotService $service): void {
        $h->check($service::class, 'defines every full-period source section and canonical hashes', static function () use ($h): void {
            $source = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR
                . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'FilingEvidenceSnapshotService.php');
            foreach (['transactions', 'expense_claims', 'loans', 'assets', 'prepayments', 'journals', 'profit_loss', 'corporation_tax', 'companies_house'] as $section) {
                $h->assertTrue(str_contains($source, "'$section'"));
            }
            $h->assertTrue(str_contains($source, 'hash(\'sha256\', $json)'));
            $h->assertTrue(str_contains($source, 'source_document_url'));
            $h->assertTrue(str_contains($source, 'manual_evidence_sha256'));
            $h->assertFalse(str_contains($source, 'copy('));
        });
    }
);
