<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

use eel_accounts\Service\IxbrlAccountingService;
use eel_accounts\Service\CompaniesHouseAccountsSubmissionService;
use eel_accounts\Service\IxbrlFactBuilderService;
use eel_accounts\Service\IxbrlAccountsReportService;
use eel_accounts\Service\IxbrlRevisedAccountsArtifactService;
use eel_accounts\Service\IxbrlSupersededFactsService;
use eel_accounts\Service\YearEndCompaniesHouseComparisonService;
use eel_accounts\Tests\Support\Ixbrl\IxbrlFactSnapshot;

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'IxbrlFactSnapshot.php';

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This regression helper is CLI-only.');
}

$output = trim((string)($argv[1] ?? ''));
$companyId = (int)($argv[2] ?? 0);
$accountingPeriodId = (int)($argv[3] ?? 0);
$submissionId = (int)($argv[4] ?? 0);
$enhanceCreditorDisclosure = (string)($argv[5] ?? '') === 'enhance-creditor-disclosure';
if ($output === '' || $companyId <= 0 || $accountingPeriodId <= 0 || $submissionId <= 0) {
    fwrite(
        STDERR,
        "Usage: php capture_runtime_revised_accounts.php OUTPUT COMPANY_ID PERIOD_ID SUBMISSION_ID"
            . " [enhance-creditor-disclosure]\n"
    );
    exit(2);
}

$submission = InterfaceDB::fetchOne(
    'SELECT s.original_document_id, s.revision_declarations_json,
            COALESCE(e.variance_explanation, \'\') AS variance_explanation
     FROM companies_house_accounts_submissions s
     LEFT JOIN companies_house_accounts_eligibility e ON e.id = s.eligibility_id
     WHERE s.id = :id AND s.company_id = :company_id
       AND s.accounting_period_id = :accounting_period_id
     LIMIT 1',
    [
        'id' => $submissionId,
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
    ]
);
if (!is_array($submission)) {
    throw new RuntimeException('The requested revised-accounts submission was not found.');
}
$period = InterfaceDB::fetchOne(
    'SELECT period_end FROM accounting_periods
     WHERE id = :id AND company_id = :company_id LIMIT 1',
    ['id' => $accountingPeriodId, 'company_id' => $companyId]
);
if (!is_array($period)) {
    throw new RuntimeException('The requested accounting period was not found.');
}

$builder = new IxbrlFactBuilderService();
$run = $builder->getLatestRun($companyId, $accountingPeriodId);
if (!is_array($run) || (int)($run['id'] ?? 0) <= 0) {
    throw new RuntimeException('No iXBRL fact run is available.');
}
$render = new ReflectionMethod(IxbrlAccountingService::class, 'renderXhtml');
$render->setAccessible(true);
$ordinary = (string)$render->invoke(
    new IxbrlAccountingService(),
    $builder->getFacts((int)$run['id']),
    false,
    ''
);
$declarations = json_decode(
    (string)$submission['revision_declarations_json'],
    true,
    512,
    JSON_THROW_ON_ERROR
);
if (!is_array($declarations)) {
    throw new RuntimeException('The saved revised-accounts declarations are invalid.');
}
$superseded = (new IxbrlSupersededFactsService())->facts(
    $companyId,
    (int)$submission['original_document_id'],
    (string)$period['period_end']
);
if ($enhanceCreditorDisclosure) {
    $model = (new IxbrlAccountsReportService())->build($companyId, $accountingPeriodId);
    $comparison = (new YearEndCompaniesHouseComparisonService())
        ->fetchComparison($companyId, $accountingPeriodId);
    $disclosureMethod = new ReflectionMethod(
        CompaniesHouseAccountsSubmissionService::class,
        'revisionDisclosureTexts'
    );
    $disclosureMethod->setAccessible(true);
    $texts = (array)$disclosureMethod->invoke(
        new CompaniesHouseAccountsSubmissionService(),
        ['variance_explanation' => (string)$submission['variance_explanation']],
        [],
        $comparison,
        $model,
        $superseded
    );
    $declarations['non_compliance_explanation'] =
        (string)($texts['non_compliance_explanation'] ?? '');
    $declarations['significant_amendments'] =
        (string)($texts['significant_amendments'] ?? '');
}
$result = (new IxbrlRevisedAccountsArtifactService())->transform(
    $ordinary,
    $declarations,
    '',
    $superseded
);
if (empty($result['success'])) {
    throw new RuntimeException((string)(($result['errors'] ?? [])[0] ?? 'Capture failed.'));
}
$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException('The capture output directory could not be created.');
}
$xhtml = (string)$result['xhtml'];
if (file_put_contents($output, $xhtml) === false) {
    throw new RuntimeException('The captured revised accounts could not be written.');
}
$snapshot = (new IxbrlFactSnapshot())->inspect($xhtml);
fwrite(STDOUT, json_encode([
    'path' => realpath($output) ?: $output,
    'sha256' => hash('sha256', $xhtml),
    'source_run_id' => (int)$run['id'],
    'creditor_disclosure_enhanced' => $enhanceCreditorDisclosure,
    'counts' => $snapshot['counts'],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
