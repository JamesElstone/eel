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
$schemaInventoryMigration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_30_001_companies_house_schema_inventory.sql'
);
$schemaValidationAssetsMigration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_30_006_companies_house_schema_validation_assets.sql'
);
$transmissionHistoryMigration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_30_002_companies_house_transmission_archive_history.sql'
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
$originalAccountsMigration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_27_002_companies_house_original_accounts.sql'
);
$authenticationChecksMigration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_30_005_companies_house_authentication_checks.sql'
);
$protocolMetadataMigration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_30_007_companies_house_protocol_metadata.sql'
);
$historyCardRenameMigration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_30_008_rename_govtalk_transmission_history_card.sql'
);
$govTalkLedgerMigration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_30_009_shared_govtalk_exchange_ledger.sql'
);
$govTalkIdentityMigration = (string)file_get_contents(
    $root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations'
    . DIRECTORY_SEPARATOR . '2026_07_30_010_govtalk_exchange_identity.sql'
);

$harness->check(
    'Companies House accounts filing schema',
    'shared GovTalk migration preserves Companies House rows before swapping ledgers',
    static function () use (
        $harness,
        $govTalkLedgerMigration,
        $govTalkIdentityMigration,
        $masterSchema
    ): void {
        foreach ([
            'govtalk_protocol_exchanges',
            'transmission_archive_id',
            'hmrc_submission_id',
            'request_qualifier',
            'request_function',
            'response_headers_sha256',
            'outcome_code',
        ] as $token) {
            $harness->assertTrue(str_contains($govTalkLedgerMigration, $token));
            $harness->assertTrue(str_contains($masterSchema, $token));
        }
        $harness->assertTrue(str_contains(
            $govTalkLedgerMigration,
            'companies_house_protocol_exchanges TO companies_house_protocol_exchanges_legacy'
        ));
        $harness->assertTrue(str_contains($govTalkLedgerMigration, 'AUTO_INCREMENT'));
        $harness->assertTrue(str_contains(
            $govTalkIdentityMigration,
            'MODIFY id bigint(20) NOT NULL AUTO_INCREMENT'
        ));
        $harness->assertTrue(str_contains(
            $masterSchema,
            '2026_07_30_010_govtalk_exchange_identity.sql'
        ));
        $harness->assertFalse(str_contains(
            $govTalkLedgerMigration,
            'DROP TABLE companies_house_protocol_exchanges_legacy'
        ));
    }
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
            'companies_house_schema_files',
            'companies_house_schema_dependencies',
            'companies_house_submission_sequences',
            'transmission_archives',
            'companies_house_company_auth_preflights',
            'govtalk_protocol_exchanges',
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
            'artifact_path',
            'artifact_sha256',
            'filing_metadata_json',
            'revised_artifact_path',
            'revised_artifact_sha256',
            'basis_hash',
            'idempotency_key',
            'rejection_code',
            'examiner_comments',
            'schema_validated_at',
            'preflight_id',
            'pending_status_cycle_id',
            'document_request_key',
            'returned_document_sha256',
        ] as $column) {
            $harness->assertTrue(InterfaceDB::columnExists('companies_house_accounts_submissions', $column));
        }
        foreach ([
            'request_message_class',
            'response_headers_json',
            'response_headers_sha256',
            'govtalk_errors_json',
        ] as $column) {
            $harness->assertTrue(InterfaceDB::columnExists(
                'govtalk_protocol_exchanges',
                $column
            ));
        }
        $harness->assertFalse(InterfaceDB::tableExists('companies_house_schema_snapshots'));
        $harness->assertFalse(
            InterfaceDB::columnExists('companies_house_accounts_submissions', 'schema_snapshot_id')
        );
        $harness->assertFalse(
            InterfaceDB::columnExists('companies_house_accounts_submissions', 'schema_manifest_sha256')
        );
        $preflightColumns = InterfaceDB::fetchAll(
            'PRAGMA table_info(companies_house_company_auth_preflights)'
        );
        $submissionColumn = array_values(array_filter(
            $preflightColumns,
            static fn (array $column): bool => (string)($column['name'] ?? '') === 'submission_id'
        ));
        $harness->assertCount(1, $submissionColumn);
        $harness->assertSame(0, (int)$submissionColumn[0]['notnull']);
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
    'protocol metadata migration indexes message class, response headers and GovTalk errors',
    static function () use ($harness, $protocolMetadataMigration, $masterSchema): void {
        foreach ([$protocolMetadataMigration, $masterSchema] as $schema) {
            foreach ([
                'request_message_class',
                'response_headers_json',
                'response_headers_sha256',
                'govtalk_errors_json',
                'presenter_authorisation_failed',
            ] as $token) {
                $harness->assertTrue(str_contains($schema, $token));
            }
        }
        $harness->assertTrue(str_contains(
            $protocolMetadataMigration,
            "WHEN 'company_data' THEN 'CompanyDataRequest'"
        ));
    }
);

$harness->check(
    'Companies House accounts filing schema',
    'authentication checks are company-context records rather than submission prerequisites',
    static function () use ($harness, $authenticationChecksMigration, $masterSchema): void {
        $harness->assertTrue(str_contains(
            $authenticationChecksMigration,
            'MODIFY COLUMN submission_id BIGINT NULL'
        ));
        $harness->assertTrue(str_contains(
            $authenticationChecksMigration,
            'DROP FOREIGN KEY IF EXISTS fk_ch_company_auth_preflight_submission'
        ));
        $harness->assertTrue(str_contains(
            $authenticationChecksMigration,
            'idx_ch_company_auth_preflight_company'
        ));
        $harness->assertTrue(str_contains(
            $masterSchema,
            '`submission_id` bigint(20) DEFAULT NULL'
        ));
        $harness->assertFalse(str_contains(
            $masterSchema,
            'CONSTRAINT `fk_ch_company_auth_preflight_submission`'
        ));
    }
);

$harness->check(
    'Companies House accounts filing schema',
    'original and revised filings share generic artifact persistence while legacy revised history remains readable',
    static function () use ($harness, $originalAccountsMigration, $masterSchema): void {
        foreach ([$originalAccountsMigration, $masterSchema] as $schema) {
            $normalizedSchema = preg_replace('/\s+/', '', strtolower($schema)) ?? '';
            foreach ([
                "enum('original','revised')",
                'artifact_path',
                'artifact_sha256',
                'filing_metadata_json',
            ] as $token) {
                $harness->assertTrue(str_contains($normalizedSchema, strtolower($token)));
            }
        }
        foreach ([
            'revised_artifact_path',
            'revised_artifact_sha256',
            'revision_declarations_json',
        ] as $legacyToken) {
            $harness->assertTrue(str_contains($masterSchema, $legacyToken));
        }
    }
);

$harness->check(
    'Companies House accounts filing schema',
    'protocol migration persists preflight, exchange and mandatory acknowledgement state',
    static function () use ($harness, $protocolMigration, $masterSchema): void {
        foreach ([
            'companies_house_company_auth_preflights',
            'companies_house_accounts_status_cycles',
            'binding_hmac',
            'status_in_flight_submission_id',
            'status_in_flight_cycle_id',
            'acknowledgement_state',
            'document_request_key',
        ] as $token) {
            $harness->assertTrue(str_contains($protocolMigration, $token));
            $harness->assertTrue(str_contains($masterSchema, $token));
        }
        $harness->assertTrue(str_contains(
            $protocolMigration,
            'companies_house_protocol_exchanges'
        ));
        $harness->assertTrue(str_contains($masterSchema, 'govtalk_protocol_exchanges'));
        $normalized = strtolower($protocolMigration);
        $harness->assertFalse(str_contains($normalized, 'company_authentication_code'));
        $harness->assertFalse(str_contains($normalized, 'company_auth_code'));
    }
);

$harness->check(
    'Companies House accounts filing schema',
    'transmission history migration supports shared pending bundles and evidence failures',
    static function () use (
        $harness,
        $transmissionHistoryMigration,
        $historyCardRenameMigration,
        $masterSchema
    ): void {
        foreach ([$transmissionHistoryMigration, $masterSchema] as $schema) {
            $harness->assertTrue(str_contains($schema, 'evidence_incomplete'));
            $harness->assertTrue(str_contains(
                $schema,
                'idx_ch_company_auth_preflight_archive_reference'
            ));
        }
        $harness->assertTrue(str_contains(
            $transmissionHistoryMigration,
            'companies_house_transmission_history'
        ));
        $harness->assertTrue(str_contains(
            $historyCardRenameMigration,
            'govtalk_transmission_history'
        ));
        $harness->assertTrue(str_contains($masterSchema, 'govtalk_transmission_history'));
        $harness->assertFalse(str_contains(
            $masterSchema,
            "'companies_house_transmission_history'"
        ));
        $harness->assertFalse(str_contains(
            $masterSchema,
            'UNIQUE KEY `uq_ch_company_auth_preflight_reference`'
        ));
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
    'schema inventory migration and master schema retain file-level provenance',
    static function () use (
        $harness,
        $schemaMigration,
        $schemaInventoryMigration,
        $schemaValidationAssetsMigration,
        $masterSchema
    ): void {
        foreach ([$schemaInventoryMigration, $masterSchema] as $schema) {
            foreach ([
                'companies_house_schema_files',
                'companies_house_schema_dependencies',
                'source_url',
                'relative_path',
                'sha256',
                'catalogue_status',
                'verified_at',
                'filing_metadata_json',
            ] as $token) {
                $harness->assertTrue(str_contains($schema, $token));
            }
        }
        $harness->assertTrue(str_contains($masterSchema, 'companies_house_schema_catalogue'));
        $harness->assertTrue(str_contains($schemaInventoryMigration, 'schema_validations'));
        $harness->assertTrue(str_contains($schemaMigration, 'companies_house_schema_snapshots'));
        $harness->assertTrue(str_contains(
            $schemaInventoryMigration,
            'DROP TABLE companies_house_schema_snapshots'
        ));
        $harness->assertFalse(str_contains($masterSchema, 'CREATE TABLE `companies_house_schema_snapshots`'));
        $harness->assertFalse(str_contains($masterSchema, '`schema_snapshot_id`'));
        foreach ([$schemaValidationAssetsMigration, $masterSchema] as $schema) {
            foreach ([
                'validation_profile',
                'validation_relative_path',
                'validation_sha256',
                'validation_verified_at',
            ] as $token) {
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
