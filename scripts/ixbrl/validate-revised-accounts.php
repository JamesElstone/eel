<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

use eel_accounts\Scripts\Ixbrl\RevisedAccountsPreSubmissionValidator;
use eel_accounts\Service\IxbrlExternalValidationService;
use eel_accounts\Service\IxbrlTaxonomyProfileService;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'RevisedAccountsPreSubmissionValidator.php';

/**
 * @param list<string> $arguments
 * @return array{input:string,output_dir:string,companies_house_result:?string,skip_arelle:bool}
 */
function revised_accounts_validation_arguments(array $arguments): array
{
    $input = '';
    $outputDirectory = '';
    $companiesHouseResult = null;
    $skipArelle = false;

    for ($index = 1, $count = count($arguments); $index < $count; $index++) {
        $argument = (string)$arguments[$index];
        if ($argument === '--skip-arelle') {
            $skipArelle = true;
            continue;
        }
        foreach ([
            '--output-dir' => 'output',
            '--companies-house-result' => 'companies_house',
        ] as $flag => $target) {
            if ($argument === $flag) {
                $index++;
                if ($index >= $count) {
                    throw new InvalidArgumentException($flag . ' requires a path.');
                }
                $value = (string)$arguments[$index];
                if ($target === 'output') {
                    $outputDirectory = $value;
                } else {
                    $companiesHouseResult = $value;
                }
                continue 2;
            }
            if (str_starts_with($argument, $flag . '=')) {
                $value = substr($argument, strlen($flag) + 1);
                if ($target === 'output') {
                    $outputDirectory = $value;
                } else {
                    $companiesHouseResult = $value;
                }
                continue 2;
            }
        }
        if (str_starts_with($argument, '--')) {
            throw new InvalidArgumentException('Unknown option: ' . $argument);
        }
        if ($input !== '') {
            throw new InvalidArgumentException('Supply exactly one XHTML artifact.');
        }
        $input = $argument;
    }

    if ($input === '') {
        throw new InvalidArgumentException('Supply the revised-accounts XHTML artifact.');
    }
    if ($outputDirectory === '') {
        $root = dirname(__DIR__, 2);
        $outputDirectory = $root . DIRECTORY_SEPARATOR . 'output'
            . DIRECTORY_SEPARATOR . 'ixbrl' . DIRECTORY_SEPARATOR . 'validation';
    }

    return [
        'input' => $input,
        'output_dir' => $outputDirectory,
        'companies_house_result' => $companiesHouseResult,
        'skip_arelle' => $skipArelle,
    ];
}

/** @return array<string, mixed>|null */
function revised_accounts_companies_house_result(?string $path): ?array
{
    if ($path === null) {
        return null;
    }
    $resolved = realpath(trim($path));
    if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
        throw new InvalidArgumentException(
            'The Companies House result JSON could not be read: ' . $path
        );
    }
    $json = file_get_contents($resolved);
    if (!is_string($json)) {
        throw new RuntimeException('The Companies House result JSON could not be loaded.');
    }
    $result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result)) {
        throw new RuntimeException('The Companies House result JSON must contain an object.');
    }
    $result['_source_path'] = $resolved;
    $result['_source_sha256'] = hash('sha256', $json);
    return $result;
}

/** @return array<string, mixed>|null */
function revised_accounts_arelle_result(string $path, bool $skip): ?array
{
    if ($skip) {
        return null;
    }
    if (!defined('PROJECT_ROOT')) {
        require_once dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'web_root'
            . DIRECTORY_SEPARATOR . 'classes'
            . DIRECTORY_SEPARATOR . 'bootstrap.php';
    }
    try {
        return (new IxbrlExternalValidationService())->validateArtifact($path);
    } catch (Throwable $exception) {
        return [
            'ok' => false,
            'status' => 'error',
            'validator' => 'arelle',
            'version' => '',
            'errors' => [
                'Arelle validation could not run: ' . $exception->getMessage(),
            ],
            'warnings' => [],
            'log_path' => '',
            'duration_ms' => 0,
        ];
    }
}

/** @param array<string, mixed> $report */
function revised_accounts_write_reports(
    array $report,
    string $outputDirectory
): array {
    if (!is_dir($outputDirectory)
        && !mkdir($outputDirectory, 0775, true)
        && !is_dir($outputDirectory)) {
        throw new RuntimeException(
            'The validation output directory could not be created: ' . $outputDirectory
        );
    }
    $resolvedOutput = realpath($outputDirectory);
    if ($resolvedOutput === false) {
        throw new RuntimeException('The validation output directory could not be resolved.');
    }
    $base = preg_replace(
        '/[^A-Za-z0-9._-]+/',
        '-',
        pathinfo((string)$report['artifact']['filename'], PATHINFO_FILENAME)
    ) ?: 'revised-accounts';
    $artifactPrefix = substr(
        strtolower((string)($report['artifact']['sha256'] ?? 'unknown')),
        0,
        12
    );
    $reportJson = json_encode(
        $report,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    $generated = preg_replace(
        '/[^0-9A-Za-z]+/',
        '',
        (string)($report['generated_at_utc'] ?? gmdate('c'))
    ) ?: gmdate('YmdHis');
    $bundleName = $base . '.' . $artifactPrefix . '.' . $generated . '.'
        . substr(hash('sha256', $reportJson), 0, 10) . '.'
        . bin2hex(random_bytes(3));
    $finalBundle = $resolvedOutput . DIRECTORY_SEPARATOR . $bundleName;
    $stagingBundle = $resolvedOutput . DIRECTORY_SEPARATOR
        . '.' . $bundleName . '.staging';
    if (!mkdir($stagingBundle, 0775)) {
        throw new RuntimeException(
            'The validation report staging directory could not be created.'
        );
    }
    $stagedFiles = [
        'pre_submission_report' => $stagingBundle . DIRECTORY_SEPARATOR
            . 'pre-submission.json',
        'fact_context_unit_report' => $stagingBundle . DIRECTORY_SEPARATOR
            . 'fact-context-unit.json',
        'visible_tagged_reconciliation' => $stagingBundle . DIRECTORY_SEPARATOR
            . 'visible-tagged-reconciliation.json',
    ];
    $documents = [
        'pre_submission_report' => $report,
        'fact_context_unit_report' => [
            'report_version' => $report['report_version'],
            'artifact' => $report['artifact'],
            'taxonomy_layer' => $report['layers']['taxonomy'],
            'validation' => $report['fact_context_unit_validation'],
        ],
        'visible_tagged_reconciliation' => [
            'report_version' => $report['report_version'],
            'artifact' => $report['artifact'],
            'validation' => $report['visible_tagged_reconciliation'],
        ],
    ];
    try {
        foreach ($stagedFiles as $key => $path) {
            $json = json_encode(
                $documents[$key],
                JSON_THROW_ON_ERROR
                    | JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            ) . PHP_EOL;
            if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) {
                throw new RuntimeException(
                    'Validation report could not be written: ' . $path
                );
            }
        }
        if (!rename($stagingBundle, $finalBundle)) {
            throw new RuntimeException(
                'The complete validation report bundle could not be published.'
            );
        }
    } catch (Throwable $exception) {
        foreach ($stagedFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($stagingBundle)) {
            rmdir($stagingBundle);
        }
        throw $exception;
    }
    $files = [];
    foreach ($stagedFiles as $key => $path) {
        $files[$key] = $finalBundle . DIRECTORY_SEPARATOR . basename($path);
    }
    return $files;
}

/** @param array<string, mixed> $report */
function revised_accounts_print_summary(array $report, array $files): void
{
    $labels = [
        'xml' => 'XML',
        'xhtml_inline_xbrl' => 'XHTML / Inline XBRL',
        'taxonomy' => 'Taxonomy (Arelle)',
        'context_units' => 'Contexts / units / facts',
        'arithmetic' => 'Arithmetic',
        'duplicates' => 'Duplicate / conflicting facts',
        'visible_tagged_reconciliation' => 'Visible / tagged reconciliation',
        'companies_house' => 'Companies House validation',
    ];
    fwrite(STDOUT, 'Revised accounts pre-submission validation' . PHP_EOL);
    fwrite(STDOUT, 'Artifact: ' . $report['artifact']['path'] . PHP_EOL);
    fwrite(STDOUT, 'SHA-256:  ' . $report['artifact']['sha256'] . PHP_EOL . PHP_EOL);
    foreach ($labels as $key => $label) {
        $layer = (array)$report['layers'][$key];
        fwrite(STDOUT, str_pad($label . ':', 35) . (string)$layer['status'] . PHP_EOL);
        foreach ((array)$layer['errors'] as $error) {
            fwrite(STDOUT, '  ERROR: ' . $error . PHP_EOL);
        }
        foreach ((array)$layer['warnings'] as $warning) {
            fwrite(STDOUT, '  WARNING: ' . $warning . PHP_EOL);
        }
    }
    fwrite(STDOUT, PHP_EOL . 'Overall filing readiness: ' . $report['overall_status'] . PHP_EOL);
    if ($report['overall_status'] === 'FAIL') {
        fwrite(
            STDOUT,
            'The artifact is not release-ready. A mandatory failed or unavailable layer remains.'
                . PHP_EOL
        );
    }
    fwrite(STDOUT, PHP_EOL . 'Reports:' . PHP_EOL);
    foreach ($files as $label => $path) {
        fwrite(STDOUT, '  ' . $label . ': ' . $path . PHP_EOL);
    }
}

/** @param array<string, mixed> $report */
function revised_accounts_validation_exit_code(array $report): int
{
    return (string)($report['overall_status'] ?? 'FAIL') === 'FAIL' ? 1 : 0;
}

function revised_accounts_validation_main(array $arguments): int
{
    try {
        $options = revised_accounts_validation_arguments($arguments);
        $input = (string)$options['input'];
        $mappings = [];
        if (!defined('PROJECT_ROOT')) {
            require_once dirname(__DIR__, 2)
                . DIRECTORY_SEPARATOR . 'web_root'
                . DIRECTORY_SEPARATOR . 'classes'
                . DIRECTORY_SEPARATOR . 'bootstrap.php';
        }
        $mappings = (new IxbrlTaxonomyProfileService())->mappings();
        $taxonomyResult = revised_accounts_arelle_result(
            $input,
            (bool)$options['skip_arelle']
        );
        $companiesHouseResult = revised_accounts_companies_house_result(
            $options['companies_house_result']
        );
        $validator = new RevisedAccountsPreSubmissionValidator($mappings);
        $report = $validator->validate($input, $taxonomyResult, $companiesHouseResult);
        $files = revised_accounts_write_reports(
            $report,
            (string)$options['output_dir']
        );
        revised_accounts_print_summary($report, $files);
        return revised_accounts_validation_exit_code($report);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Revised accounts validation failed: '
            . $exception->getMessage() . PHP_EOL);
        fwrite(
            STDERR,
            'Usage: php scripts/ixbrl/validate-revised-accounts.php <artifact.xhtml>'
                . ' [--output-dir <directory>]'
                . ' [--companies-house-result <result.json>] [--skip-arelle]'
                . PHP_EOL
        );
        return $exception instanceof InvalidArgumentException ? 2 : 1;
    }
}

if (PHP_SAPI === 'cli'
    && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(revised_accounts_validation_main($argv));
}
