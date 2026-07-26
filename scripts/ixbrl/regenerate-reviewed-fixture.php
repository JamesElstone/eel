<?php
/**
 * Regenerate a review-only revised-accounts fixture from the current source
 * model without creating, approving or replacing a filing workflow record.
 *
 * Production artifacts must continue through the filing-approval and
 * Companies House preparation services. This command exists so generator and
 * validator changes can be exercised against a deterministic review artifact.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'web_root'
    . DIRECTORY_SEPARATOR . 'classes'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use eel_accounts\Service\CompaniesHouseAccountsSubmissionService;
use eel_accounts\Service\IxbrlAccountingService;
use eel_accounts\Service\IxbrlAccountsReportService;
use eel_accounts\Service\IxbrlFactBuilderService;
use eel_accounts\Service\IxbrlRevisedAccountsArtifactService;
use eel_accounts\Service\IxbrlSupersededFactsService;
use eel_accounts\Service\IxbrlTaxonomyProfileService;
use eel_accounts\Service\YearEndCompaniesHouseComparisonService;

$companyId = isset($argv[1]) ? (int)$argv[1] : 0;
$accountingPeriodId = isset($argv[2]) ? (int)$argv[2] : 0;
$requestedOutputPath = trim((string)($argv[3] ?? ''));
if ($companyId <= 0 || $accountingPeriodId <= 0 || $requestedOutputPath === '') {
    fwrite(
        STDERR,
        "Usage: php scripts/ixbrl/regenerate-reviewed-fixture.php "
            . "<company-id> <accounting-period-id> <output.xhtml>\n"
    );
    exit(2);
}

/**
 * Resolve against the repository rather than the caller's CWD, then verify
 * the real parent path. This rejects junction/symlink escapes as well as
 * lexical ../ traversal.
 */
function reviewed_fixture_output_path(string $requested): string
{
    $projectRoot = realpath(dirname(__DIR__, 2));
    if ($projectRoot === false || str_contains($requested, "\0")) {
        throw new InvalidArgumentException(
            'The repository root or requested output path is invalid.'
        );
    }
    if (strtolower((string)pathinfo($requested, PATHINFO_EXTENSION)) !== 'xhtml') {
        throw new InvalidArgumentException(
            'The review fixture output must use the .xhtml extension.'
        );
    }
    $absolute = preg_match('/^[A-Za-z]:[\\\\\\/]/', $requested) === 1
        ? $requested
        : $projectRoot . DIRECTORY_SEPARATOR . $requested;
    $allowed = [];
    foreach ([
        $projectRoot . DIRECTORY_SEPARATOR . 'output'
            . DIRECTORY_SEPARATOR . 'ixbrl',
        $projectRoot . DIRECTORY_SEPARATOR . 'web_root'
            . DIRECTORY_SEPARATOR . 'tests'
            . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'generated',
    ] as $base) {
        if (!is_dir($base)
            && !mkdir($base, 0775, true)
            && !is_dir($base)) {
            throw new RuntimeException(
                'The allowlisted review-fixture directory could not be created.'
            );
        }
        $resolvedBase = realpath($base);
        if ($resolvedBase === false) {
            throw new RuntimeException(
                'The allowlisted review-fixture directory could not be resolved.'
            );
        }
        $allowed[] = rtrim($resolvedBase, '\\/');
    }

    $insideAllowed = static function (string $path) use ($allowed): bool {
        $normalisedPath = strtolower(rtrim($path, '\\/'));
        foreach ($allowed as $base) {
            $normalisedBase = strtolower($base);
            if ($normalisedPath === $normalisedBase
                || str_starts_with(
                    $normalisedPath,
                    $normalisedBase . DIRECTORY_SEPARATOR
                )) {
                return true;
            }
        }
        return false;
    };

    $parent = dirname($absolute);
    $existingAncestor = $parent;
    while (!is_dir($existingAncestor)) {
        $next = dirname($existingAncestor);
        if ($next === $existingAncestor) {
            throw new InvalidArgumentException(
                'The review output path has no resolvable parent.'
            );
        }
        $existingAncestor = $next;
    }
    $resolvedAncestor = realpath($existingAncestor);
    if ($resolvedAncestor === false || !$insideAllowed($resolvedAncestor)) {
        throw new InvalidArgumentException(
            'The review fixture must be written below output/ixbrl/ '
                . 'or web_root/tests/fixtures/generated/.'
        );
    }
    if (!is_dir($parent)
        && !mkdir($parent, 0775, true)
        && !is_dir($parent)) {
        throw new RuntimeException(
            'The review output directory could not be created.'
        );
    }
    $resolvedParent = realpath($parent);
    if ($resolvedParent === false) {
        throw new RuntimeException(
            'The review output directory could not be resolved.'
        );
    }
    if (!$insideAllowed($resolvedParent)) {
        throw new InvalidArgumentException(
            'The review fixture must be written below output/ixbrl/ '
                . 'or web_root/tests/fixtures/generated/.'
        );
    }

    $candidate = $resolvedParent . DIRECTORY_SEPARATOR . basename($absolute);
    if (is_link($candidate)) {
        throw new InvalidArgumentException(
            'The review fixture output must not be a symbolic link.'
        );
    }
    return $candidate;
}

try {
    $outputPath = reviewed_fixture_output_path($requestedOutputPath);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(2);
}

try {
    $report = (new IxbrlAccountsReportService())->build(
        $companyId,
        $accountingPeriodId
    );

    $builder = new IxbrlFactBuilderService();
    $buildFact = new ReflectionMethod($builder, 'factFromMapping');
    $buildFact->setAccessible(true);
    $facts = [];
    foreach ((new IxbrlTaxonomyProfileService())->mappings() as $mapping) {
        $fact = $buildFact->invoke($builder, $mapping, $report, false);
        if (is_array($fact)) {
            $fact['source_json'] = json_encode(
                (array)($fact['source'] ?? []),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
            $facts[] = $fact;
        }
        if (is_array($report['comparative'] ?? null)
            && !empty($mapping['comparative_enabled'])) {
            $comparativeFact = $buildFact->invoke($builder, $mapping, $report, true);
            if (is_array($comparativeFact)) {
                $comparativeFact['source_json'] = json_encode(
                    (array)($comparativeFact['source'] ?? []),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                );
                $facts[] = $comparativeFact;
            }
        }
    }

    $accountingService = new IxbrlAccountingService();
    $render = new ReflectionMethod($accountingService, 'renderXhtml');
    $render->setAccessible(true);
    $ordinaryXhtml = (string)$render->invoke(
        $accountingService,
        $facts,
        is_array($report['comparative'] ?? null),
        ''
    );

    $submissionService = new CompaniesHouseAccountsSubmissionService();
    $submissionContext = $submissionService->fetchContext(
        $companyId,
        $accountingPeriodId
    );
    $eligibility = (array)($submissionContext['eligibility'] ?? []);
    $originalDocumentId = (int)($eligibility['original_document_id'] ?? 0);
    if ($originalDocumentId <= 0) {
        throw new RuntimeException(
            'The exact-period original Companies House filing is unavailable.'
        );
    }

    $comparison = (new YearEndCompaniesHouseComparisonService())
        ->fetchComparison($companyId, $accountingPeriodId);
    $revisionTexts = new ReflectionMethod(
        $submissionService,
        'revisionDisclosureTexts'
    );
    $revisionTexts->setAccessible(true);
    $disclosures = (array)$revisionTexts->invoke(
        $submissionService,
        $eligibility,
        [],
        $comparison,
        $report
    );

    $approvalDate = trim((string)(
        ((array)($report['disclosures'] ?? []))['accounts_approval_date'] ?? ''
    ));
    $artifactService = new IxbrlRevisedAccountsArtifactService();
    $declarationsMethod = new ReflectionMethod($artifactService, 'declarations');
    $declarationsMethod->setAccessible(true);
    $declarations = (array)$declarationsMethod->invoke(
        $artifactService,
        (string)$report['accounting_period']['period_end'],
        [
            'revision_approval_date' => $approvalDate,
            'non_compliance_explanation' =>
                (string)($disclosures['non_compliance_explanation'] ?? ''),
            'significant_amendments' =>
                (string)($disclosures['significant_amendments'] ?? ''),
        ]
    );
    $supersededFacts = (new IxbrlSupersededFactsService())->facts(
        $companyId,
        $originalDocumentId,
        (string)$report['accounting_period']['period_end']
    );
    $transformed = $artifactService->transform(
        $ordinaryXhtml,
        $declarations,
        '',
        $supersededFacts
    );
    if (empty($transformed['success'])) {
        throw new RuntimeException(
            implode(' ', (array)($transformed['errors'] ?? [
                'The revised review fixture could not be transformed.',
            ]))
        );
    }

    $validate = new ReflectionMethod($accountingService, 'validateInlineXbrl');
    $validate->setAccessible(true);
    $validationErrors = (array)$validate->invoke(
        $accountingService,
        (string)$transformed['xhtml']
    );
    if ($validationErrors !== []) {
        throw new RuntimeException(
            'The regenerated fixture failed generator validation: '
                . implode(' ', $validationErrors)
        );
    }

    if (file_put_contents($outputPath, (string)$transformed['xhtml']) === false) {
        throw new RuntimeException('The review fixture could not be written.');
    }

    echo json_encode([
        'status' => 'generated_review_fixture',
        'filing_approval_created_or_changed' => false,
        'path' => $outputPath,
        'sha256' => hash_file('sha256', $outputPath),
        'taxonomy_entry_point' => IxbrlTaxonomyProfileService::SCHEMA_REF,
        'fact_count_before_superseded_and_revision_facts' => count($facts),
        'original_document_id' => $originalDocumentId,
        'approval_date' => $approvalDate,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
