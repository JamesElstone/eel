<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

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
function revised_accounts_validation_exit_code(array $report): int
{
    return (string)($report['overall_status'] ?? 'FAIL') === 'FAIL' ? 1 : 0;
}
