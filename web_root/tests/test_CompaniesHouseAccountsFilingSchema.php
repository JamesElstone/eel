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
$schemaMigration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_21_001_companies_house_accounts_schemas.sql'
);
$transmissionMigration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_23_001_safe_transmission_archives.sql'
);
$numericSubmissionMigration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_23_002_numeric_ch_submission_numbers.sql'
);
$archiveMetadataMigration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_23_003_transmission_archive_artifact_metadata.sql'
);
$protocolMigration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_23_004_companies_house_protocol_conversation.sql'
);

$harness->check(
    'Companies House accounts filing schema',
    'fresh SQLite schema exposes the filing tables and critical columns',
    static function () use ($harness): void {
        foreach ([
            'companies_house_accounts_eligibility',
            'companies_house_accounts_submissions',
            'companies_house_accounts_submission_events',
            'companies_house_schema_catalogue',
            'companies_house_schema_snapshots',
            'companies_house_schema_files',
            'companies_house_schema_dependencies',
            'companies_house_submission_sequences',
            'transmission_archives',
            'companies_house_company_auth_preflights',
            'companies_house_protocol_exchanges',
            'companies_house_accounts_status_cycles',
        ] as $table) {
            $harness->assertTrue(InterfaceDB::tableExists($table));
        }

        foreach ([
            'environment',
            'lifecycle',
            'raw_gateway_status',
            'submission_number',
            'presenter_fingerprint',
            'revised_artifact_path',
            'revised_artifact_sha256',
            'basis_hash',
            'idempotency_key',
            'rejection_code',
            'examiner_comments',
            'schema_snapshot_id',
            'schema_manifest_sha256',
            'schema_validated_at',
            'preflight_id',
            'pending_status_cycle_id',
            'document_request_key',
            'returned_document_sha256',
        ] as $column) {
            $harness->assertTrue(InterfaceDB::columnExists('companies_house_accounts_submissions', $column));
        }
        foreach ([
            'request_path',
            'request_sha256',
            'response_path',
            'response_sha256',
            'manifest_path',
            'manifest_sha256',
        ] as $column) {
            $harness->assertTrue(InterfaceDB::columnExists('transmission_archives', $column));
        }
    }
);

$harness->check(
    'Companies House accounts filing schema',
    'protocol migration persists preflight, exchange and mandatory acknowledgement state',
    static function () use ($harness, $protocolMigration, $masterSchema): void {
        foreach ([$protocolMigration, $masterSchema] as $schema) {
            foreach ([
                'companies_house_company_auth_preflights',
                'companies_house_protocol_exchanges',
                'companies_house_accounts_status_cycles',
                'binding_hmac',
                'status_in_flight_submission_id',
                'status_in_flight_cycle_id',
                'acknowledgement_state',
                'document_request_key',
            ] as $token) {
                $harness->assertTrue(str_contains($schema, $token));
            }
        }
        $normalized = strtolower($protocolMigration);
        $harness->assertFalse(str_contains($normalized, 'company_authentication_code'));
        $harness->assertFalse(str_contains($normalized, 'company_auth_code'));
    }
);

$harness->check(
    'Companies House accounts filing schema',
    'archive metadata addresses exact request and response evidence',
    static function () use ($harness, $archiveMetadataMigration, $masterSchema): void {
        foreach ([$archiveMetadataMigration, $masterSchema] as $schema) {
            foreach (['request_path', 'request_sha256', 'response_path', 'response_sha256'] as $token) {
                $harness->assertTrue(str_contains($schema, $token));
            }
        }
    }
);

$harness->check(
    'Companies House accounts filing schema',
    'numeric submission migration and baseline enforce an ordered six-digit series',
    static function () use ($harness, $numericSubmissionMigration, $masterSchema): void {
        foreach ([$numericSubmissionMigration, $masterSchema] as $schema) {
            $harness->assertTrue(str_contains($schema, "REGEXP '^[0-9]{6}$'")
                || str_contains($schema, "regexp '^[0-9]{6}$'"));
        }
    }
);

$harness->check(
    'Companies House accounts filing schema',
    'safe transmission migration defines presenter sequences and private archive metadata',
    static function () use ($harness, $transmissionMigration, $masterSchema): void {
        foreach ([$transmissionMigration, $masterSchema] as $schema) {
            foreach ([
                'companies_house_submission_sequences',
                'transmission_archives',
                'presenter_fingerprint',
                'next_value',
                'last_issued_value',
                'in_flight_submission_id',
                'manifest_sha256',
                'uq_ch_accounts_presenter_submission',
            ] as $token) {
                $harness->assertTrue(str_contains($schema, $token));
            }
        }
    }
);

$harness->check(
    'Companies House accounts filing schema',
    'schema refresh migration and master schema retain snapshot provenance',
    static function () use ($harness, $schemaMigration, $masterSchema): void {
        foreach ([$schemaMigration, $masterSchema] as $schema) {
            foreach (['companies_house_schema_catalogue','companies_house_schema_snapshots','companies_house_schema_files','companies_house_schema_dependencies','schema_snapshot_id','schema_manifest_sha256','schema_validated_at'] as $token) {
                $harness->assertTrue(str_contains($schema, $token));
            }
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
