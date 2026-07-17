<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$root = dirname(__DIR__, 2);
$migration = (string)file_get_contents(
    $root
    . DIRECTORY_SEPARATOR . 'db_schema'
    . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_17_003_companies_house_accounts_filing.sql'
);
$masterSchema = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql'
);

$harness->check(
    'Companies House accounts filing schema',
    'fresh SQLite schema exposes the filing tables and critical columns',
    static function () use ($harness): void {
        foreach ([
            'companies_house_accounts_eligibility',
            'companies_house_accounts_submissions',
            'companies_house_accounts_submission_events',
        ] as $table) {
            $harness->assertTrue(InterfaceDB::tableExists($table));
        }

        foreach ([
            'environment',
            'lifecycle',
            'raw_gateway_status',
            'submission_number',
            'revised_artifact_path',
            'revised_artifact_sha256',
            'basis_hash',
            'idempotency_key',
            'rejection_code',
            'examiner_comments',
        ] as $column) {
            $harness->assertTrue(InterfaceDB::columnExists('companies_house_accounts_submissions', $column));
        }
    }
);

$harness->check(
    'Companies House accounts filing schema',
    'migration and master schema define the complete persistence boundary',
    static function () use ($harness, $migration, $masterSchema): void {
        foreach ([$migration, $masterSchema] as $schema) {
            foreach ([
                'companies_house_accounts_eligibility',
                'companies_house_accounts_submissions',
                'companies_house_accounts_submission_events',
                'original_document_id',
                'original_transaction_id',
                'evidence_text',
                'revised_artifact_sha256',
                'basis_hash',
                'idempotency_key',
                'submission_number',
                'raw_gateway_status',
                'rejection_code',
                'examiner_comments',
                'redacted_context_json',
            ] as $requiredToken) {
                $harness->assertTrue(str_contains($schema, $requiredToken));
            }
        }

        $harness->assertTrue(str_contains(
            $migration,
            "decision ENUM('pending', 'eligible', 'ineligible')"
        ));
        $harness->assertTrue(str_contains(
            $migration,
            "environment ENUM('TEST', 'LIVE')"
        ));
        $harness->assertTrue(str_contains(
            $migration,
            'CHECK (submission_number IS NULL OR CHAR_LENGTH(submission_number) = 6)'
        ));
        $harness->assertTrue(str_contains(
            $migration,
            'UNIQUE KEY uq_ch_accounts_submission_idempotency (environment, idempotency_key)'
        ));
    }
);

$harness->check(
    'Companies House accounts filing schema',
    'schema deliberately has no place to persist filing credentials or raw envelopes',
    static function () use ($harness, $migration): void {
        $normalized = strtolower($migration);
        foreach ([
            'company_auth_code',
            'company_authentication_code',
            'presenter_id',
            'presenter_code',
            'credential',
            'request_body',
            'response_body',
            'govtalk_envelope',
        ] as $forbiddenToken) {
            $harness->assertFalse(str_contains($normalized, $forbiddenToken));
        }
    }
);
