/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for FreeBSD14.3 (amd64)
--
-- Host: localhost    Database: eel_accounts
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-log

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `application_activity_flash_history`
--

DROP TABLE IF EXISTS `application_activity_flash_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `application_activity_flash_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `page_id` varchar(255) NOT NULL,
  `action_name` varchar(255) DEFAULT NULL,
  `card_action_name` varchar(255) DEFAULT NULL,
  `message_type` enum('success','warning','error') NOT NULL,
  `message_text` longtext NOT NULL,
  `message_html_text` longtext DEFAULT NULL,
  `request_method` varchar(10) DEFAULT NULL,
  `is_ajax` tinyint(1) NOT NULL DEFAULT 0,
  `device_id` varchar(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(1000) DEFAULT NULL,
  `request_uri` varchar(2048) DEFAULT NULL,
  `occurred_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_application_activity_flash_user_time` (`user_id`,`occurred_at`),
  KEY `idx_application_activity_flash_page_time` (`page_id`,`occurred_at`),
  KEY `idx_application_activity_flash_type_time` (`message_type`,`occurred_at`),
  CONSTRAINT `fk_application_activity_flash_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `categorisation_rules`
--

DROP TABLE IF EXISTS `categorisation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categorisation_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `priority` int(11) NOT NULL DEFAULT 100,
  `match_field` enum('description','reference','name','type','card','any') NOT NULL DEFAULT 'description',
  `desc_match_type` enum('contains','equals','starts_with','regex') NOT NULL DEFAULT 'contains',
  `desc_match_value` varchar(255) NOT NULL,
  `ref_match_type` enum('none','contains','equals','starts_with') NOT NULL DEFAULT 'none',
  `ref_match_value` varchar(255) DEFAULT NULL,
  `source_category_value` varchar(255) DEFAULT NULL,
  `source_account_value` varchar(255) DEFAULT NULL,
  `nominal_account_id` int(11) NOT NULL,
  `director_id` bigint(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rules_company_priority` (`company_id`,`is_active`,`priority`),
  KEY `idx_rules_nominal` (`nominal_account_id`),
  KEY `idx_categorisation_rules_director` (`director_id`),
  CONSTRAINT `fk_categorisation_rules_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_categorisation_rules_director` FOREIGN KEY (`director_id`) REFERENCES `company_directors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_categorisation_rules_nominal_r` FOREIGN KEY (`nominal_account_id`) REFERENCES `nominal_accounts` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `companies`
--

DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) NOT NULL,
  `company_number` varchar(32) DEFAULT NULL,
  `is_vat_registered` tinyint(1) NOT NULL DEFAULT 0,
  `vat_country_code` varchar(2) DEFAULT NULL,
  `vat_number` varchar(32) DEFAULT NULL,
  `vat_validation_status` varchar(20) DEFAULT NULL,
  `vat_validated_at` datetime DEFAULT NULL,
  `vat_validation_source` varchar(20) DEFAULT NULL,
  `vat_validation_mode` varchar(8) DEFAULT NULL,
  `vat_validation_name` varchar(255) DEFAULT NULL,
  `vat_validation_address_line1` varchar(255) DEFAULT NULL,
  `vat_validation_postcode` varchar(32) DEFAULT NULL,
  `vat_validation_country_code` varchar(8) DEFAULT NULL,
  `vat_last_error` text DEFAULT NULL,
  `incorporation_date` date DEFAULT NULL,
  `company_status` varchar(50) DEFAULT NULL,
  `companies_house_type` varchar(100) DEFAULT NULL,
  `companies_house_jurisdiction` varchar(100) DEFAULT NULL,
  `registered_office_address_line_1` varchar(255) DEFAULT NULL,
  `registered_office_address_line_2` varchar(255) DEFAULT NULL,
  `registered_office_locality` varchar(255) DEFAULT NULL,
  `registered_office_region` varchar(255) DEFAULT NULL,
  `registered_office_postal_code` varchar(32) DEFAULT NULL,
  `registered_office_country` varchar(100) DEFAULT NULL,
  `registered_office_care_of` varchar(255) DEFAULT NULL,
  `registered_office_po_box` varchar(50) DEFAULT NULL,
  `registered_office_premises` varchar(255) DEFAULT NULL,
  `can_file` tinyint(1) DEFAULT NULL,
  `has_charges` tinyint(1) DEFAULT NULL,
  `has_insolvency_history` tinyint(1) DEFAULT NULL,
  `has_been_liquidated` tinyint(1) DEFAULT NULL,
  `registered_office_is_in_dispute` tinyint(1) DEFAULT NULL,
  `undeliverable_registered_office_address` tinyint(1) DEFAULT NULL,
  `has_super_secure_pscs` tinyint(1) DEFAULT NULL,
  `companies_house_environment` varchar(10) DEFAULT NULL,
  `companies_house_etag` varchar(255) DEFAULT NULL,
  `companies_house_last_checked_at` datetime DEFAULT NULL,
  `companies_house_profile_json` longtext DEFAULT NULL,
  `companies_house_active_director_count` int(11) DEFAULT NULL,
  `companies_house_officers_last_checked_at` datetime DEFAULT NULL,
  `companies_house_officers_json` longtext DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_companies_active` (`is_active`),
  KEY `idx_companies_incorporation_date` (`incorporation_date`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `company_directors`
--

DROP TABLE IF EXISTS `company_directors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_directors` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'companies_house',
  `external_key` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `officer_role` varchar(100) NOT NULL DEFAULT 'director',
  `appointed_on` date DEFAULT NULL,
  `resigned_on` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `source_json` longtext DEFAULT NULL,
  `last_synced_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_directors_source_identity` (`company_id`,`source`,`external_key`),
  KEY `idx_company_directors_company_status` (`company_id`,`is_active`,`full_name`),
  KEY `idx_company_directors_tenure` (`company_id`,`appointed_on`,`resigned_on`),
  CONSTRAINT `fk_company_directors_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `companies_house_document_contexts`
--

DROP TABLE IF EXISTS `companies_house_document_contexts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies_house_document_contexts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `document_fk` bigint(20) NOT NULL,
  `context_ref` varchar(100) NOT NULL,
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `instant_date` date DEFAULT NULL,
  `is_latest_year_context` tinyint(1) NOT NULL DEFAULT 0,
  `dimension_json` longtext DEFAULT NULL,
  `created_at_utc` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ch_contexts_document_ref` (`document_fk`,`context_ref`),
  KEY `idx_ch_contexts_document_latest` (`document_fk`,`is_latest_year_context`),
  CONSTRAINT `fk_ch_contexts_document` FOREIGN KEY (`document_fk`) REFERENCES `companies_house_documents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=183 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `companies_house_document_facts`
--

DROP TABLE IF EXISTS `companies_house_document_facts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies_house_document_facts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `document_fk` bigint(20) NOT NULL,
  `context_fk` bigint(20) NOT NULL,
  `concept_fk` bigint(20) NOT NULL,
  `fact_name` varchar(255) DEFAULT NULL,
  `raw_value` varchar(255) DEFAULT NULL,
  `normalised_numeric` decimal(18,2) DEFAULT NULL,
  `normalised_text` text DEFAULT NULL,
  `normalised_date` date DEFAULT NULL,
  `unit_ref` varchar(50) DEFAULT NULL,
  `decimals_value` varchar(20) DEFAULT NULL,
  `sign_hint` varchar(50) DEFAULT NULL,
  `is_numeric` tinyint(1) NOT NULL DEFAULT 0,
  `is_latest_year_fact` tinyint(1) NOT NULL DEFAULT 1,
  `created_at_utc` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ch_fact_document_context_concept_value` (`document_fk`,`context_fk`,`concept_fk`,`raw_value`),
  KEY `idx_ch_facts_document` (`document_fk`),
  KEY `idx_ch_facts_context` (`context_fk`),
  KEY `idx_ch_facts_concept` (`concept_fk`),
  KEY `idx_ch_facts_latest` (`document_fk`,`is_latest_year_fact`),
  CONSTRAINT `fk_ch_facts_concept` FOREIGN KEY (`concept_fk`) REFERENCES `companies_house_taxonomy_concepts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ch_facts_context` FOREIGN KEY (`context_fk`) REFERENCES `companies_house_document_contexts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ch_facts_document` FOREIGN KEY (`document_fk`) REFERENCES `companies_house_documents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=364 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `companies_house_documents`
--

DROP TABLE IF EXISTS `companies_house_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies_house_documents` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `company_number` varchar(32) NOT NULL,
  `transaction_id` varchar(128) NOT NULL,
  `filing_date` date DEFAULT NULL,
  `filing_type` varchar(32) DEFAULT NULL,
  `filing_category` varchar(64) DEFAULT NULL,
  `filing_description` varchar(255) DEFAULT NULL,
  `document_id` varchar(255) NOT NULL,
  `metadata_url` text NOT NULL,
  `content_url` text DEFAULT NULL,
  `final_content_url` text DEFAULT NULL,
  `content_type` varchar(100) DEFAULT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `classification` varchar(50) DEFAULT NULL,
  `significant_date` date DEFAULT NULL,
  `significant_date_type` varchar(100) DEFAULT NULL,
  `pages` int(11) DEFAULT NULL,
  `created_at_utc` datetime DEFAULT NULL,
  `fetched_at_utc` datetime DEFAULT NULL,
  `raw_metadata_json` longtext DEFAULT NULL,
  `raw_content_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `parse_status` varchar(50) DEFAULT NULL,
  `parse_error` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ch_documents_document_id` (`document_id`),
  KEY `idx_ch_documents_company_number` (`company_number`),
  KEY `idx_ch_documents_transaction_id` (`transaction_id`),
  KEY `idx_ch_documents_filing_date` (`filing_date`),
  KEY `idx_ch_documents_filing_type` (`filing_type`),
  KEY `idx_ch_documents_company_id` (`company_id`),
  CONSTRAINT `fk_ch_documents_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `companies_house_accounts_eligibility`
--

DROP TABLE IF EXISTS `companies_house_accounts_eligibility`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies_house_accounts_eligibility` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `original_document_id` bigint(20) DEFAULT NULL,
  `original_transaction_id` varchar(128) NOT NULL,
  `original_document_external_id` varchar(255) NOT NULL,
  `original_filing_channel` varchar(50) NOT NULL,
  `decision` enum('pending','eligible','ineligible') NOT NULL DEFAULT 'pending',
  `evidence_text` longtext NOT NULL,
  `evidence_reference` varchar(255) DEFAULT NULL,
  `evidence_received_at` datetime DEFAULT NULL,
  `decided_by` varchar(100) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ch_accounts_eligibility_source` (`company_id`,`accounting_period_id`,`original_transaction_id`),
  KEY `idx_ch_accounts_eligibility_period` (`company_id`,`accounting_period_id`,`decision`),
  KEY `idx_ch_accounts_eligibility_document` (`original_document_id`),
  CONSTRAINT `chk_ch_accounts_eligibility_decision` CHECK (`decision` = 'pending' and `decided_by` is null and `decided_at` is null or `decision` in ('eligible','ineligible') and `decided_by` is not null and `decided_at` is not null),
  CONSTRAINT `fk_ch_accounts_eligibility_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ch_accounts_eligibility_document` FOREIGN KEY (`original_document_id`) REFERENCES `companies_house_documents` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ch_accounts_eligibility_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `companies_house_accounts_submissions`
--

DROP TABLE IF EXISTS `companies_house_schema_dependencies`;
DROP TABLE IF EXISTS `companies_house_schema_files`;
DROP TABLE IF EXISTS `companies_house_schema_snapshots`;
DROP TABLE IF EXISTS `companies_house_schema_catalogue`;
CREATE TABLE `companies_house_schema_catalogue` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `schema_name` varchar(255) NOT NULL,
  `source_url` varchar(500) NOT NULL,
  `lifecycle_status` enum('released','live','deprecated','retired') NOT NULL,
  `release_date` date DEFAULT NULL,
  `live_date` date DEFAULT NULL,
  `deprecated_date` date DEFAULT NULL,
  `retirement_date` date DEFAULT NULL,
  `first_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ch_schema_catalogue_url` (`source_url`),
  KEY `idx_ch_schema_catalogue_status` (`lifecycle_status`,`schema_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `companies_house_schema_snapshots` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `manifest_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `catalogue_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `local_path` varchar(1000) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `profile_name` varchar(64) NOT NULL DEFAULT 'revised_accounts',
  `root_count` int(11) NOT NULL DEFAULT 0,
  `dependency_count` int(11) NOT NULL DEFAULT 0,
  `file_count` int(11) NOT NULL DEFAULT 0,
  `checked_at` datetime NOT NULL,
  `verified_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ch_schema_snapshot_manifest` (`manifest_sha256`),
  KEY `idx_ch_schema_snapshot_active` (`profile_name`,`is_active`,`verified_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `companies_house_schema_files` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `snapshot_id` bigint(20) NOT NULL,
  `source_url` varchar(500) NOT NULL,
  `relative_path` varchar(500) NOT NULL,
  `schema_name` varchar(255) NOT NULL,
  `file_role` enum('envelope','profile_root','dependency') NOT NULL,
  `catalogue_status` varchar(32) DEFAULT NULL,
  `target_namespace` varchar(1000) DEFAULT NULL,
  `file_size` bigint(20) NOT NULL,
  `sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `etag` varchar(255) DEFAULT NULL,
  `last_modified` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ch_schema_file_url` (`snapshot_id`,`source_url`),
  UNIQUE KEY `uq_ch_schema_file_path` (`snapshot_id`,`relative_path`),
  KEY `idx_ch_schema_file_snapshot_role` (`snapshot_id`,`file_role`),
  CONSTRAINT `fk_ch_schema_file_snapshot` FOREIGN KEY (`snapshot_id`) REFERENCES `companies_house_schema_snapshots` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `companies_house_schema_dependencies` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `snapshot_id` bigint(20) NOT NULL,
  `parent_file_id` bigint(20) NOT NULL,
  `child_file_id` bigint(20) NOT NULL,
  `relation_type` enum('include','import','redefine') NOT NULL,
  `declared_namespace` varchar(1000) DEFAULT NULL,
  `schema_location` varchar(1000) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ch_schema_dependency` (`parent_file_id`,`child_file_id`,`relation_type`),
  KEY `idx_ch_schema_dependency_snapshot` (`snapshot_id`),
  CONSTRAINT `fk_ch_schema_dependency_snapshot` FOREIGN KEY (`snapshot_id`) REFERENCES `companies_house_schema_snapshots` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ch_schema_dependency_parent` FOREIGN KEY (`parent_file_id`) REFERENCES `companies_house_schema_files` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ch_schema_dependency_child` FOREIGN KEY (`child_file_id`) REFERENCES `companies_house_schema_files` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `companies_house_accounts_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies_house_accounts_submissions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `evidence_bundle_id` bigint(20) DEFAULT NULL,
  `eligibility_id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `original_document_id` bigint(20) DEFAULT NULL,
  `original_transaction_id` varchar(128) NOT NULL,
  `original_document_external_id` varchar(255) NOT NULL,
  `ixbrl_generation_run_id` bigint(20) DEFAULT NULL,
  `environment` enum('TEST','LIVE') NOT NULL,
  `filing_type` enum('revised') NOT NULL DEFAULT 'revised',
  `lifecycle` enum('prepared','submitting','transport_unknown','pending','parked','accepted','rejected','internal_failure','failed') NOT NULL DEFAULT 'prepared',
  `raw_gateway_status` varchar(64) DEFAULT NULL,
  `submission_number` varchar(6) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `gateway_submission_reference` varchar(255) DEFAULT NULL,
  `revised_artifact_path` varchar(1000) NOT NULL,
  `revised_artifact_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `schema_snapshot_id` bigint(20) DEFAULT NULL,
  `schema_manifest_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `schema_validated_at` datetime DEFAULT NULL,
  `basis_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `idempotency_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision_declarations_json` longtext NOT NULL,
  `gateway_status_summary` text DEFAULT NULL,
  `rejection_code` varchar(100) DEFAULT NULL,
  `rejection_description` text DEFAULT NULL,
  `examiner_comments` text DEFAULT NULL,
  `prepared_by` varchar(100) NOT NULL,
  `submitted_by` varchar(100) DEFAULT NULL,
  `prepared_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_at` datetime DEFAULT NULL,
  `last_polled_at` datetime DEFAULT NULL,
  `status_updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `accepted_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ch_accounts_submission_idempotency` (`environment`,`idempotency_key`),
  UNIQUE KEY `uq_ch_accounts_submission_number` (`environment`,`submission_number`),
  KEY `idx_ch_accounts_submission_period` (`company_id`,`accounting_period_id`,`environment`,`lifecycle`),
  KEY `idx_ch_accounts_submission_eligibility` (`eligibility_id`),
  KEY `idx_ch_accounts_submission_document` (`original_document_id`),
  KEY `idx_ch_accounts_submission_ixbrl_run` (`ixbrl_generation_run_id`),
  KEY `idx_ch_accounts_submission_gateway_status` (`environment`,`lifecycle`,`raw_gateway_status`),
  KEY `idx_ch_accounts_submission_schema_snapshot` (`schema_snapshot_id`),
  CONSTRAINT `chk_ch_accounts_submission_number` CHECK (`submission_number` is null or char_length(`submission_number`) = 6),
  CONSTRAINT `fk_ch_accounts_submission_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ch_accounts_submission_document` FOREIGN KEY (`original_document_id`) REFERENCES `companies_house_documents` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ch_accounts_submission_eligibility` FOREIGN KEY (`eligibility_id`) REFERENCES `companies_house_accounts_eligibility` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ch_accounts_submission_ixbrl_run` FOREIGN KEY (`ixbrl_generation_run_id`) REFERENCES `ixbrl_generation_runs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ch_accounts_submission_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
  ,CONSTRAINT `fk_ch_accounts_submission_schema_snapshot` FOREIGN KEY (`schema_snapshot_id`) REFERENCES `companies_house_schema_snapshots` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `companies_house_accounts_submission_events`
--

DROP TABLE IF EXISTS `companies_house_accounts_submission_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies_house_accounts_submission_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `submission_id` bigint(20) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `event_level` enum('debug','info','warning','error','success') NOT NULL DEFAULT 'info',
  `lifecycle` enum('prepared','submitting','transport_unknown','pending','parked','accepted','rejected','internal_failure','failed') DEFAULT NULL,
  `raw_gateway_status` varchar(64) DEFAULT NULL,
  `event_message` text NOT NULL,
  `gateway_code` varchar(100) DEFAULT NULL,
  `gateway_description` text DEFAULT NULL,
  `examiner_comments` text DEFAULT NULL,
  `redacted_context_json` longtext DEFAULT NULL,
  `actor` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ch_accounts_submission_events_submission` (`submission_id`,`created_at`),
  KEY `idx_ch_accounts_submission_events_status` (`raw_gateway_status`,`created_at`),
  CONSTRAINT `fk_ch_accounts_submission_events_submission` FOREIGN KEY (`submission_id`) REFERENCES `companies_house_accounts_submissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `companies_house_taxonomy_concepts`
--

DROP TABLE IF EXISTS `companies_house_taxonomy_concepts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies_house_taxonomy_concepts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `concept_name` varchar(255) NOT NULL,
  `short_name` varchar(150) DEFAULT NULL,
  `friendly_label` varchar(255) DEFAULT NULL,
  `value_type` varchar(30) DEFAULT NULL,
  `created_at_utc` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ch_taxonomy_concept_name` (`concept_name`)
) ENGINE=InnoDB AUTO_INCREMENT=349 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `company_accounts`
--

DROP TABLE IF EXISTS `company_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `account_name` varchar(255) NOT NULL,
  `account_type` enum('bank','trade') NOT NULL DEFAULT 'bank',
  `institution_name` varchar(255) DEFAULT NULL,
  `account_identifier` varchar(255) DEFAULT NULL,
  `nominal_account_id` int(11) DEFAULT NULL,
  `internal_transfer_marker` varchar(100) DEFAULT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `phone_number` varchar(100) DEFAULT NULL,
  `address_line_1` varchar(255) DEFAULT NULL,
  `address_line_2` varchar(255) DEFAULT NULL,
  `address_locality` varchar(255) DEFAULT NULL,
  `address_region` varchar(255) DEFAULT NULL,
  `address_postal_code` varchar(32) DEFAULT NULL,
  `address_country` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_company_accounts_name_type` (`company_id`,`account_name`,`account_type`),
  KEY `idx_company_accounts_company_active` (`company_id`,`is_active`,`account_type`),
  KEY `idx_company_accounts_nominal` (`nominal_account_id`),
  CONSTRAINT `fk_company_accounts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_company_accounts_nominal` FOREIGN KEY (`nominal_account_id`) REFERENCES `nominal_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `company_incorporation_share_classes`
--

DROP TABLE IF EXISTS `company_incorporation_share_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_incorporation_share_classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `issued_at` datetime DEFAULT NULL,
  `share_class` varchar(100) NOT NULL DEFAULT 'Ordinary',
  `currency` varchar(10) NOT NULL DEFAULT 'GBP',
  `quantity` int(11) NOT NULL,
  `nominal_value_per_share` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `paid_value_per_share` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `unpaid_value_per_share` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `source_note` text DEFAULT NULL,
  `document_reference` varchar(255) DEFAULT NULL,
  `status` enum('unresolved','paid','part_paid','unpaid') NOT NULL DEFAULT 'unresolved',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_incorporation_shares_company` (`company_id`),
  CONSTRAINT `fk_incorporation_shares_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_incorporation_shares_quantity_positive` CHECK (`quantity` > 0),
  CONSTRAINT `chk_incorporation_shares_values_nonnegative` CHECK (`nominal_value_per_share` >= 0 and `paid_value_per_share` >= 0 and `unpaid_value_per_share` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Table structure for table `company_settings`
--

DROP TABLE IF EXISTS `company_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `setting` varchar(100) NOT NULL,
  `type` varchar(20) NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_settings_company_setting` (`company_id`,`setting`),
  KEY `idx_company_settings_company_id` (`company_id`)
) ENGINE=InnoDB AUTO_INCREMENT=163 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expense_claim_lines`
--

DROP TABLE IF EXISTS `expense_claim_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_claim_lines` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `expense_claim_id` bigint(20) NOT NULL,
  `line_number` int(11) NOT NULL,
  `expense_date` date NOT NULL,
  `description` varchar(500) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `nominal_account_id` int(11) DEFAULT NULL,
  `director_id` bigint(20) DEFAULT NULL,
  `receipt_reference` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_expense_claim_lines_claim_line` (`expense_claim_id`,`line_number`),
  KEY `idx_expense_claim_lines_nominal` (`nominal_account_id`),
  KEY `idx_expense_claim_lines_director` (`director_id`),
  CONSTRAINT `fk_expense_claim_lines_claim` FOREIGN KEY (`expense_claim_id`) REFERENCES `expense_claims` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_expense_claim_lines_director` FOREIGN KEY (`director_id`) REFERENCES `company_directors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_expense_claim_lines_nominal` FOREIGN KEY (`nominal_account_id`) REFERENCES `nominal_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_expense_claim_lines_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expense_claim_payment_links`
--

DROP TABLE IF EXISTS `expense_claim_payment_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_claim_payment_links` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `expense_claim_id` bigint(20) NOT NULL,
  `transaction_id` bigint(20) NOT NULL,
  `linked_amount` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_expense_claim_payment_links_claim_transaction` (`expense_claim_id`,`transaction_id`),
  KEY `idx_expense_claim_payment_links_transaction` (`transaction_id`),
  CONSTRAINT `fk_expense_claim_payment_links_claim` FOREIGN KEY (`expense_claim_id`) REFERENCES `expense_claims` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_expense_claim_payment_links_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_expense_claim_payment_links_amount` CHECK (`linked_amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expense_claimants`
--

DROP TABLE IF EXISTS `expense_claimants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_claimants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `claimant_name` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_expense_claimants_company_name` (`company_id`,`claimant_name`),
  KEY `idx_expense_claimants_company_active` (`company_id`,`is_active`,`claimant_name`),
  CONSTRAINT `fk_expense_claimants_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expense_claims`
--

DROP TABLE IF EXISTS `expense_claims`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_claims` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `claimant_id` int(11) NOT NULL,
  `claim_year` smallint(6) NOT NULL,
  `claim_month` tinyint(4) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `claim_reference_code` varchar(32) NOT NULL,
  `brought_forward_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `claimed_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payments_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `carried_forward_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','posted') NOT NULL DEFAULT 'draft',
  `posted_journal_id` bigint(20) DEFAULT NULL,
  `no_lines_confirmed_at` datetime DEFAULT NULL,
  `no_lines_confirmed_by` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_expense_claims_company_reference` (`company_id`,`claim_reference_code`),
  UNIQUE KEY `uniq_expense_claims_company_claimant_month` (`company_id`,`claimant_id`,`claim_year`,`claim_month`),
  KEY `idx_expense_claims_company_period` (`company_id`,`claim_year`,`claim_month`),
  KEY `idx_expense_claims_company_claimant_period` (`company_id`,`claimant_id`,`period_start`,`id`),
  KEY `idx_expense_claims_accounting_period` (`accounting_period_id`),
  KEY `idx_expense_claims_claimant` (`claimant_id`),
  KEY `idx_expense_claims_posted_journal` (`posted_journal_id`),
  CONSTRAINT `fk_expense_claims_claimant` FOREIGN KEY (`claimant_id`) REFERENCES `expense_claimants` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_expense_claims_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_expense_claims_posted_journal` FOREIGN KEY (`posted_journal_id`) REFERENCES `journals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_expense_claims_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_expense_claims_month` CHECK (`claim_month` between 1 and 12)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `journal_entry_metadata`
--

DROP TABLE IF EXISTS `journal_entry_metadata`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_entry_metadata` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `journal_id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `journal_tag` varchar(64) NOT NULL,
  `journal_key` varchar(128) NOT NULL DEFAULT '',
  `entry_mode` varchar(32) NOT NULL DEFAULT 'manual',
  `related_journal_id` bigint(20) DEFAULT NULL,
  `replacement_of_journal_id` bigint(20) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_journal_entry_metadata_journal` (`journal_id`),
  KEY `idx_journal_entry_metadata_key` (`company_id`,`accounting_period_id`,`journal_tag`,`journal_key`),
  KEY `idx_journal_entry_metadata_period` (`company_id`,`accounting_period_id`,`journal_tag`),
  KEY `idx_journal_entry_metadata_related` (`related_journal_id`),
  KEY `fk_journal_entry_metadata_accounting_period` (`accounting_period_id`),
  KEY `fk_journal_entry_metadata_replacement_journal` (`replacement_of_journal_id`),
  CONSTRAINT `fk_journal_entry_metadata_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_journal_entry_metadata_journal` FOREIGN KEY (`journal_id`) REFERENCES `journals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_journal_entry_metadata_related_journal` FOREIGN KEY (`related_journal_id`) REFERENCES `journals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_journal_entry_metadata_replacement_journal` FOREIGN KEY (`replacement_of_journal_id`) REFERENCES `journals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_journal_entry_metadata_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `journal_lines`
--

DROP TABLE IF EXISTS `journal_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_lines` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `journal_id` bigint(20) NOT NULL,
  `nominal_account_id` int(11) NOT NULL,
  `director_id` bigint(20) DEFAULT NULL,
  `party_id` bigint(20) DEFAULT NULL,
  `company_account_id` int(11) DEFAULT NULL,
  `debit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_journal_lines_journal` (`journal_id`),
  KEY `idx_journal_lines_nominal` (`nominal_account_id`),
  KEY `idx_journal_lines_director` (`director_id`),
  KEY `idx_journal_lines_nominal_director` (`nominal_account_id`,`director_id`),
  KEY `idx_journal_lines_party` (`party_id`),
  KEY `idx_journal_lines_nominal_party` (`nominal_account_id`,`party_id`),
  KEY `idx_journal_lines_company_account` (`company_account_id`),
  CONSTRAINT `fk_journal_lines_company_account` FOREIGN KEY (`company_account_id`) REFERENCES `company_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_journal_lines_director` FOREIGN KEY (`director_id`) REFERENCES `company_directors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_journal_lines_party` FOREIGN KEY (`party_id`) REFERENCES `company_parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_journal_lines_journal` FOREIGN KEY (`journal_id`) REFERENCES `journals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_journal_lines_nominal_r` FOREIGN KEY (`nominal_account_id`) REFERENCES `nominal_accounts` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_journal_lines_nonnegative` CHECK (`debit` >= 0 and `credit` >= 0),
  CONSTRAINT `chk_journal_lines_one_sided` CHECK (`debit` > 0 and `credit` = 0 or `credit` > 0 and `debit` = 0)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `journals`
--

DROP TABLE IF EXISTS `journals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `journals` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `source_type` enum('bank_csv','director_loan_register','expense_register','manual','asset_register','asset_depreciation','asset_disposal') NOT NULL,
  `source_ref` varchar(255) DEFAULT NULL,
  `journal_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `is_posted` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_journals_company_source_ref` (`company_id`,`source_type`,`source_ref`),
  KEY `idx_journals_company_date` (`company_id`,`journal_date`),
  KEY `idx_journals_accounting_period_date` (`accounting_period_id`,`journal_date`),
  KEY `idx_journals_company_period_posted_date` (`company_id`,`accounting_period_id`,`is_posted`,`journal_date`),
  KEY `idx_journals_source_type` (`source_type`),
  CONSTRAINT `fk_journals_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_journals_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dividend_vouchers`
--

DROP TABLE IF EXISTS `dividend_vouchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dividend_vouchers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `journal_id` bigint(20) NOT NULL,
  `transaction_id` int(11) DEFAULT NULL,
  `reversal_journal_id` bigint(20) DEFAULT NULL,
  `shareholder_party_id` bigint(20) DEFAULT NULL,
  `company_name` varchar(255) NOT NULL,
  `shareholder_name` varchar(255) NOT NULL,
  `director_name` varchar(255) NOT NULL,
  `director_id` bigint(20) DEFAULT NULL,
  `declaration_date` date NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `description` varchar(255) NOT NULL,
  `voucher_text` text NOT NULL,
  `minutes_text` text NOT NULL,
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `issued_by` varchar(100) NOT NULL DEFAULT 'web_app',
  `voided_at` datetime DEFAULT NULL,
  `voided_by` varchar(100) DEFAULT NULL,
  `void_reason` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dividend_vouchers_journal` (`journal_id`),
  KEY `idx_dividend_vouchers_company_period` (`company_id`,`accounting_period_id`),
  KEY `idx_dividend_vouchers_transaction` (`transaction_id`),
  KEY `idx_dividend_vouchers_shareholder_party` (`shareholder_party_id`),
  KEY `idx_dividend_vouchers_director` (`director_id`),
  KEY `idx_dividend_vouchers_reversal_journal` (`reversal_journal_id`),
  CONSTRAINT `fk_dividend_vouchers_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dividend_vouchers_director` FOREIGN KEY (`director_id`) REFERENCES `company_directors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_dividend_vouchers_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dividend_vouchers_journal` FOREIGN KEY (`journal_id`) REFERENCES `journals` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_dividend_vouchers_reversal_journal` FOREIGN KEY (`reversal_journal_id`) REFERENCES `journals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dividend_reserve_classification_rules`
--

DROP TABLE IF EXISTS `dividend_reserve_classification_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dividend_reserve_classification_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `nominal_account_id` int(11) NOT NULL,
  `treatment` varchar(40) NOT NULL,
  `note` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dividend_reserve_rule_company_nominal` (`company_id`,`nominal_account_id`),
  KEY `idx_dividend_reserve_rule_nominal` (`nominal_account_id`),
  CONSTRAINT `fk_dividend_reserve_rule_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dividend_reserve_rule_nominal` FOREIGN KEY (`nominal_account_id`) REFERENCES `nominal_accounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dividend_reserve_review_snapshots`
--

DROP TABLE IF EXISTS `dividend_reserve_review_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dividend_reserve_review_snapshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `as_at_date` date DEFAULT NULL,
  `source_hash` char(64) NOT NULL,
  `brought_forward_distributable_reserves` decimal(12,2) NOT NULL DEFAULT 0.00,
  `ledger_profit_loss` decimal(12,2) NOT NULL DEFAULT 0.00,
  `realised_profit_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `realised_loss_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unrealised_gain_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unrealised_loss_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `non_distributable_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `capital_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_charge_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `dividend_distribution_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unknown_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `distributable_current_profit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `dividends_declared` decimal(12,2) NOT NULL DEFAULT 0.00,
  `closing_distributable_reserves` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reviewed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` varchar(100) NOT NULL DEFAULT 'web_app',
  `summary_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dividend_reserve_snapshot_company_period` (`company_id`,`accounting_period_id`),
  KEY `idx_dividend_reserve_snapshot_hash` (`company_id`,`accounting_period_id`,`source_hash`),
  KEY `idx_dividend_reserve_snapshot_as_at` (`company_id`,`accounting_period_id`,`as_at_date`),
  CONSTRAINT `fk_dividend_reserve_snapshot_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dividend_reserve_snapshot_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hmrc_obligations`
--

DROP TABLE IF EXISTS `hmrc_obligations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hmrc_obligations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `obligation_type` enum('ct_payment','ct600_filing','hmrc_penalty','hmrc_interest','other') NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `notice_date` date DEFAULT NULL,
  `due_date` date NOT NULL,
  `amount_due` decimal(12,2) DEFAULT NULL,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `legacy_unlinked_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('not_started','in_progress','ready','filed','paid','part_paid','overdue','cancelled','not_applicable') NOT NULL DEFAULT 'not_started',
  `source` enum('calculated','manual','hmrc_notice','journal','bank_match') NOT NULL DEFAULT 'calculated',
  `source_reference` varchar(255) DEFAULT NULL,
  `related_journal_id` bigint(20) DEFAULT NULL,
  `related_fine_id` int(11) DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hmrc_obligations_company_accounting_period` (`company_id`,`accounting_period_id`),
  KEY `idx_hmrc_obligations_period_type` (`company_id`,`accounting_period_id`,`obligation_type`),
  KEY `idx_hmrc_obligations_type` (`obligation_type`),
  KEY `idx_hmrc_obligations_due_date` (`due_date`),
  KEY `idx_hmrc_obligations_status` (`status`),
  KEY `idx_hmrc_obligations_company_due_status` (`company_id`,`due_date`,`status`),
  KEY `fk_hmrc_obligations_accounting_period` (`accounting_period_id`),
  KEY `fk_hmrc_obligations_journal` (`related_journal_id`),
  CONSTRAINT `fk_hmrc_obligations_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_hmrc_obligations_journal` FOREIGN KEY (`related_journal_id`) REFERENCES `journals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_hmrc_obligations_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `corporation_tax_periods`
--

DROP TABLE IF EXISTS `corporation_tax_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `corporation_tax_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `sequence_no` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` enum('pending','computed','ready','submitted','accepted','rejected','superseded') NOT NULL DEFAULT 'pending',
  `latest_computation_run_id` int(11) DEFAULT NULL,
  `latest_submission_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ct_period_sequence` (`accounting_period_id`,`sequence_no`),
  KEY `idx_ct_period_company_period` (`company_id`,`accounting_period_id`),
  KEY `idx_ct_period_status` (`company_id`,`accounting_period_id`,`status`),
  CONSTRAINT `fk_ct_period_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_period_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Effective-dated ownership and CT-period human-confirmed facts.
DROP TABLE IF EXISTS `corporation_tax_s455_reviews`;
DROP TABLE IF EXISTS `corporation_tax_period_facts`;
DROP TABLE IF EXISTS `company_shareholdings`;
DROP TABLE IF EXISTS `company_party_roles`;
DROP TABLE IF EXISTS `company_parties`;
CREATE TABLE `company_parties` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `party_type` enum('individual','company','trust','partnership','other') NOT NULL DEFAULT 'individual',
  `legal_name` varchar(255) NOT NULL,
  `linked_director_id` bigint(20) DEFAULT NULL,
  `source_note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_parties_linked_director` (`company_id`,`linked_director_id`),
  KEY `idx_company_parties_company_name` (`company_id`,`legal_name`),
  CONSTRAINT `fk_company_parties_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_company_parties_director` FOREIGN KEY (`linked_director_id`) REFERENCES `company_directors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `company_party_roles` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `party_id` bigint(20) NOT NULL,
  `role_type` enum('participator','associate') NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `source_note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company_party_roles_effective` (`company_id`,`role_type`,`effective_from`,`effective_to`),
  KEY `idx_company_party_roles_party` (`party_id`,`effective_from`,`effective_to`),
  CONSTRAINT `fk_company_party_roles_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_company_party_roles_party` FOREIGN KEY (`party_id`) REFERENCES `company_parties` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_company_party_roles_dates` CHECK (`effective_to` is null or `effective_to` >= `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `company_shareholdings` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `party_id` bigint(20) NOT NULL,
  `share_class_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `source_note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company_shareholdings_effective` (`company_id`,`share_class_id`,`effective_from`,`effective_to`),
  KEY `idx_company_shareholdings_party` (`party_id`,`effective_from`,`effective_to`),
  CONSTRAINT `fk_company_shareholdings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_company_shareholdings_party` FOREIGN KEY (`party_id`) REFERENCES `company_parties` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_company_shareholdings_class` FOREIGN KEY (`share_class_id`) REFERENCES `company_incorporation_share_classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_company_shareholdings_quantity` CHECK (`quantity` > 0),
  CONSTRAINT `chk_company_shareholdings_dates` CHECK (`effective_to` is null or `effective_to` >= `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `dividend_vouchers`
  ADD CONSTRAINT `fk_dividend_vouchers_shareholder_party`
  FOREIGN KEY (`shareholder_party_id`) REFERENCES `company_parties` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
CREATE TABLE `corporation_tax_period_facts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `ct_period_id` int(11) NOT NULL,
  `associated_company_count` int(11) NOT NULL DEFAULT 0,
  `confirmed_at` datetime DEFAULT NULL,
  `confirmed_by` varchar(100) DEFAULT NULL,
  `confirmation_note` text DEFAULT NULL,
  `basis_hash` char(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ct_period_facts_period` (`ct_period_id`),
  KEY `idx_ct_period_facts_company_period` (`company_id`,`accounting_period_id`),
  CONSTRAINT `fk_ct_period_facts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_period_facts_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_period_facts_ct_period` FOREIGN KEY (`ct_period_id`) REFERENCES `corporation_tax_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_ct_period_facts_associated_count` CHECK (`associated_company_count` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `s455_rate_rules`;
CREATE TABLE `s455_rate_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `rate` decimal(9,6) NOT NULL,
  `source_note` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_s455_rate_rules_effective` (`is_active`,`effective_from`,`effective_to`),
  CONSTRAINT `chk_s455_rate_rules_rate` CHECK (`rate` >= 0 and `rate` <= 1),
  CONSTRAINT `chk_s455_rate_rules_dates` CHECK (`effective_to` is null or `effective_to` >= `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `s455_rate_rules` (`effective_from`,`effective_to`,`rate`,`source_note`,`is_active`) VALUES
  ('2016-04-06','2022-04-05',0.325000,'CTA 2010 s455 dated local catalogue',1),
  ('2022-04-06',NULL,0.337500,'CTA 2010 s455 dated local catalogue',1);
CREATE TABLE `corporation_tax_s455_reviews` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `ct_period_id` int(11) NOT NULL,
  `close_company_status` enum('unconfirmed','yes','no') NOT NULL DEFAULT 'unconfirmed',
  `gross_principal` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gross_tax` decimal(14,2) NOT NULL DEFAULT 0.00,
  `qualifying_repayments` decimal(14,2) NOT NULL DEFAULT 0.00,
  `relief_tax` decimal(14,2) NOT NULL DEFAULT 0.00,
  `net_tax` decimal(14,2) NOT NULL DEFAULT 0.00,
  `ct600a_required` tinyint(1) NOT NULL DEFAULT 0,
  `repayment_deadline` date NOT NULL,
  `evidence_cutoff` datetime NOT NULL,
  `window_status` enum('provisional_window_open','window_complete') NOT NULL,
  `basis_hash` char(64) NOT NULL,
  `basis_json` longtext NOT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `confirmed_by` varchar(100) DEFAULT NULL,
  `confirmation_note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ct_s455_review_period` (`ct_period_id`),
  KEY `idx_ct_s455_review_company_period` (`company_id`,`accounting_period_id`),
  CONSTRAINT `fk_ct_s455_review_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_s455_review_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_s455_review_ct_period` FOREIGN KEY (`ct_period_id`) REFERENCES `corporation_tax_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CT600A supplementary-page evidence and filing-scope confirmations.
DROP TABLE IF EXISTS `corporation_tax_ct600a_accounting_reviews`;
DROP TABLE IF EXISTS `corporation_tax_ct600a_reviews`;
DROP TABLE IF EXISTS `corporation_tax_ct600a_events`;
DROP TABLE IF EXISTS `corporation_tax_scope_confirmations`;
CREATE TABLE `corporation_tax_scope_confirmations` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `scope_version` varchar(50) NOT NULL,
  `answers_json` longtext NOT NULL,
  `revision` int(11) NOT NULL DEFAULT 1,
  `confirmed_by` varchar(100) DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `basis_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ct_scope_period` (`company_id`,`accounting_period_id`),
  CONSTRAINT `fk_ct_scope_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_scope_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `corporation_tax_ct600a_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `ct_period_id` int(11) NOT NULL,
  `originating_ct_period_id` int(11) DEFAULT NULL,
  `party_id` bigint(20) NOT NULL,
  `event_kind` enum('opening_outstanding','release','write_off','later_repayment','s464a_benefit','s464a_return_payment') NOT NULL,
  `event_date` date NOT NULL,
  `origin_date` date DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `source_type` enum('bank_transaction','journal','prior_return','manual_evidence') NOT NULL,
  `source_id` bigint(20) DEFAULT NULL,
  `evidence_reference` varchar(255) NOT NULL,
  `explanation` text NOT NULL,
  `matching_status` enum('clear','potential_464c','confirmed_464c') NOT NULL DEFAULT 'clear',
  `approval_role` enum('director','adviser') NOT NULL,
  `approved_by` varchar(100) NOT NULL,
  `approved_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ct600a_event_period` (`company_id`,`accounting_period_id`,`ct_period_id`,`event_date`),
  KEY `idx_ct600a_event_origin` (`originating_ct_period_id`),
  KEY `idx_ct600a_event_party` (`party_id`,`event_date`),
  CONSTRAINT `fk_ct600a_event_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct600a_event_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct600a_event_ct_period` FOREIGN KEY (`ct_period_id`) REFERENCES `corporation_tax_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct600a_event_origin` FOREIGN KEY (`originating_ct_period_id`) REFERENCES `corporation_tax_periods` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_ct600a_event_party` FOREIGN KEY (`party_id`) REFERENCES `company_parties` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_ct600a_event_amount` CHECK (`amount` > 0),
  CONSTRAINT `chk_ct600a_event_dates` CHECK (`origin_date` is null or `origin_date` <= `event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `corporation_tax_ct600a_reviews` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `ct_period_id` int(11) NOT NULL,
  `review_version` varchar(50) NOT NULL,
  `answers_json` longtext NOT NULL,
  `approver_role` enum('director','adviser') NOT NULL,
  `approved_by` varchar(100) NOT NULL,
  `confirmation_note` text DEFAULT NULL,
  `evidence_manifest_json` longtext NOT NULL,
  `basis_hash` char(64) NOT NULL,
  `confirmed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ct600a_review_period` (`ct_period_id`),
  KEY `idx_ct600a_review_company_period` (`company_id`,`accounting_period_id`),
  CONSTRAINT `fk_ct600a_review_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct600a_review_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct600a_review_ct_period` FOREIGN KEY (`ct_period_id`) REFERENCES `corporation_tax_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `corporation_tax_ct600a_accounting_reviews` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `review_version` varchar(50) NOT NULL,
  `answers_json` longtext NOT NULL,
  `approver_role` enum('director','adviser') NOT NULL,
  `approved_by` varchar(100) NOT NULL,
  `confirmation_note` text DEFAULT NULL,
  `evidence_manifest_json` longtext NOT NULL,
  `basis_hash` char(64) NOT NULL,
  `confirmed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ct600a_accounting_review_period` (`company_id`,`accounting_period_id`),
  CONSTRAINT `fk_ct600a_accounting_review_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct600a_accounting_review_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `corporation_tax_computation_runs`
--

DROP TABLE IF EXISTS `corporation_tax_computation_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `corporation_tax_computation_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `ct_period_id` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` enum('draft','generated','failed') NOT NULL DEFAULT 'draft',
  `computation_hash` char(64) NOT NULL,
  `summary_json` longtext NOT NULL,
  `ixbrl_status` varchar(32) NOT NULL DEFAULT 'not_generated',
  `computation_taxonomy_package_id` bigint(20) DEFAULT NULL,
  `computation_taxonomy_package_hash` char(64) DEFAULT NULL,
  `ixbrl_mapping_profile_id` bigint(20) DEFAULT NULL,
  `ixbrl_mapping_hash` char(64) DEFAULT NULL,
  `filing_basis_version` varchar(50) DEFAULT NULL,
  `filing_basis_hash` char(64) DEFAULT NULL,
  `generated_path` varchar(1000) DEFAULT NULL,
  `generated_filename` varchar(255) DEFAULT NULL,
  `taxonomy_profile` varchar(100) DEFAULT NULL,
  `validation_status` varchar(32) NOT NULL DEFAULT 'not_validated',
  `validation_errors_json` longtext DEFAULT NULL,
  `external_validator` varchar(50) DEFAULT NULL,
  `external_validator_version` varchar(100) DEFAULT NULL,
  `external_validation_status` varchar(32) NOT NULL DEFAULT 'not_configured',
  `external_validation_errors_json` longtext DEFAULT NULL,
  `external_validation_warnings_json` longtext DEFAULT NULL,
  `external_validation_log_path` varchar(1000) DEFAULT NULL,
  `external_validated_at` datetime DEFAULT NULL,
  `output_sha256` char(64) DEFAULT NULL,
  `external_validated_sha256` char(64) DEFAULT NULL,
  `ixbrl_generated_at` datetime DEFAULT NULL,
  `generated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ct_computation_period` (`ct_period_id`,`generated_at`),
  KEY `idx_ct_computation_company_period` (`company_id`,`accounting_period_id`,`generated_at`),
  CONSTRAINT `fk_ct_computation_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_computation_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_computation_ct_period` FOREIGN KEY (`ct_period_id`) REFERENCES `corporation_tax_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `corporation_tax_audit_snapshots`
--

DROP TABLE IF EXISTS `corporation_tax_audit_areas`;
DROP TABLE IF EXISTS `corporation_tax_audit_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `corporation_tax_audit_snapshots` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `computation_run_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `ct_period_id` int(11) NOT NULL,
  `basis_version` varchar(50) NOT NULL,
  `basis_hash` char(64) NOT NULL,
  `calculation_trace_version` varchar(64) DEFAULT NULL,
  `calculation_trace_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `calculation_trace_json` longtext DEFAULT NULL,
  `snapshot_origin` varchar(32) NOT NULL DEFAULT 'year_end_lock',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ct_audit_snapshot_run` (`computation_run_id`),
  KEY `idx_ct_audit_snapshot_period` (`company_id`,`accounting_period_id`,`ct_period_id`),
  CONSTRAINT `fk_ct_audit_snapshot_run` FOREIGN KEY (`computation_run_id`) REFERENCES `corporation_tax_computation_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_audit_snapshot_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_audit_snapshot_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_audit_snapshot_ct_period` FOREIGN KEY (`ct_period_id`) REFERENCES `corporation_tax_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_ct_audit_snapshot_origin` CHECK (`snapshot_origin` in ('year_end_lock','legacy_reconstruction'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `corporation_tax_audit_areas` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `snapshot_id` bigint(20) NOT NULL,
  `area_code` varchar(64) NOT NULL,
  `area_label` varchar(150) NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `expected_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reconciliation_difference` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reconciliation_status` varchar(16) NOT NULL DEFAULT 'reconciled',
  `source_count` int(11) NOT NULL DEFAULT 0,
  `area_hash` char(64) NOT NULL,
  `detail_json` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ct_audit_area_snapshot_code` (`snapshot_id`,`area_code`),
  KEY `idx_ct_audit_area_snapshot` (`snapshot_id`,`id`),
  CONSTRAINT `fk_ct_audit_area_snapshot` FOREIGN KEY (`snapshot_id`) REFERENCES `corporation_tax_audit_snapshots` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_ct_audit_area_reconciliation` CHECK (`reconciliation_status` in ('reconciled','discrepancy'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hmrc_ct600_submissions`
--

DROP TABLE IF EXISTS `hmrc_ct600_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hmrc_ct600_submissions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `evidence_bundle_id` bigint(20) DEFAULT NULL,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `ct_period_id` int(11) DEFAULT NULL,
  `mode` enum('TEST','TIL','LIVE') NOT NULL,
  `environment` enum('TEST','TIL','LIVE') NOT NULL DEFAULT 'TEST',
  `status` enum('draft','validating','validation_failed','ready','submitting','accepted','rejected','failed') NOT NULL,
  `protocol_state` enum('prepared','validation_failed','ready','submitting','awaiting_poll','final_received','delete_pending','closed','transport_uncertain','invalidated') NOT NULL DEFAULT 'prepared',
  `business_outcome` enum('none','sandbox_passed','til_validated','live_accepted','rejected','error') NOT NULL DEFAULT 'none',
  `submission_type` enum('original','amendment') NOT NULL DEFAULT 'original',
  `ct600_xml_path` varchar(1000) DEFAULT NULL,
  `accounts_ixbrl_path` varchar(1000) DEFAULT NULL,
  `accounts_run_id` bigint(20) DEFAULT NULL,
  `accounts_sha256` char(64) DEFAULT NULL,
  `computations_ixbrl_path` varchar(1000) DEFAULT NULL,
  `computation_run_id` int(11) DEFAULT NULL,
  `computations_sha256` char(64) DEFAULT NULL,
  `year_end_locked_at` datetime DEFAULT NULL,
  `package_hash` char(64) DEFAULT NULL,
  `idempotency_key` char(64) DEFAULT NULL,
  `transaction_id` varchar(64) DEFAULT NULL,
  `hmrc_submission_reference` varchar(255) DEFAULT NULL,
  `hmrc_correlation_id` varchar(255) DEFAULT NULL,
  `response_endpoint` varchar(1000) DEFAULT NULL,
  `poll_interval_seconds` int(11) DEFAULT NULL,
  `next_poll_at` datetime DEFAULT NULL,
  `poll_attempts` int(11) NOT NULL DEFAULT 0,
  `irmark` varchar(64) DEFAULT NULL,
  `schema_version` varchar(50) DEFAULT NULL,
  `body_sha256` char(64) DEFAULT NULL,
  `ct600_sha256` char(64) DEFAULT NULL,
  `hmrc_response_code` int(11) DEFAULT NULL,
  `hmrc_response_summary` text DEFAULT NULL,
  `request_headers_json` longtext DEFAULT NULL,
  `response_headers_json` longtext DEFAULT NULL,
  `request_body_path` varchar(1000) DEFAULT NULL,
  `manifest_path` varchar(1000) DEFAULT NULL,
  `source_manifest_json` longtext DEFAULT NULL,
  `source_manifest_sha256` char(64) DEFAULT NULL,
  `test_submission_id` bigint(20) DEFAULT NULL,
  `response_body_path` varchar(1000) DEFAULT NULL,
  `response_sha256` char(64) DEFAULT NULL,
  `validation_json` longtext DEFAULT NULL,
  `declarant_name` varchar(255) DEFAULT NULL,
  `declarant_status` varchar(255) DEFAULT NULL,
  `declaration_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `authority_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `authority_confirmed_at` datetime DEFAULT NULL,
  `authority_confirmed_by` varchar(255) DEFAULT NULL,
  `supplementary_scope_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `original_unfiled_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `declaration_approved_at` datetime DEFAULT NULL,
  `declaration_approved_by` varchar(255) DEFAULT NULL,
  `approved_package_hash` char(64) DEFAULT NULL,
  `prepared_by` varchar(255) DEFAULT NULL,
  `submitted_by` varchar(100) DEFAULT NULL,
  `submitted_by_user_id` bigint(20) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `final_response_at` datetime DEFAULT NULL,
  `cleanup_completed_at` datetime DEFAULT NULL,
  `cleanup_response_path` varchar(1000) DEFAULT NULL,
  `cleanup_response_sha256` char(64) DEFAULT NULL,
  `cleanup_error` text DEFAULT NULL,
  `cleanup_attempts` int(11) NOT NULL DEFAULT 0,
  `recovery_attempts` int(11) NOT NULL DEFAULT 0,
  `last_recovery_at` datetime DEFAULT NULL,
  `invalidated_at` datetime DEFAULT NULL,
  `invalidation_reason` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hmrc_ct600_company_accounting_period` (`company_id`,`accounting_period_id`),
  KEY `idx_hmrc_ct600_ct_period` (`ct_period_id`),
  KEY `idx_hmrc_ct600_mode_status` (`mode`,`status`),
  UNIQUE KEY `uq_hmrc_ct600_idempotency` (`idempotency_key`),
  KEY `idx_hmrc_ct600_environment_outcome` (`environment`,`business_outcome`),
  KEY `idx_hmrc_ct600_poll_due` (`protocol_state`,`next_poll_at`),
  KEY `idx_hmrc_ct600_source_manifest` (`ct_period_id`,`environment`,`source_manifest_sha256`,`body_sha256`),
  KEY `idx_hmrc_ct600_test_submission` (`test_submission_id`),
  CONSTRAINT `fk_hmrc_ct600_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_hmrc_ct600_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_hmrc_ct600_ct_period` FOREIGN KEY (`ct_period_id`) REFERENCES `corporation_tax_periods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_hmrc_ct600_test_submission` FOREIGN KEY (`test_submission_id`) REFERENCES `hmrc_ct600_submissions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hmrc_submission_events`
--

DROP TABLE IF EXISTS `hmrc_submission_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hmrc_submission_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `submission_id` bigint(20) NOT NULL,
  `event_level` enum('debug','info','warning','error','success') NOT NULL DEFAULT 'info',
  `event_message` text NOT NULL,
  `event_context_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hmrc_submission_events_submission` (`submission_id`),
  CONSTRAINT `fk_hmrc_submission_events_submission` FOREIGN KEY (`submission_id`) REFERENCES `hmrc_ct600_submissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ixbrl_generation_runs`
--

DROP TABLE IF EXISTS `ixbrl_generation_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ixbrl_generation_runs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `status` enum('draft','ready','generated','failed') NOT NULL DEFAULT 'draft',
  `export_type` varchar(32) NOT NULL DEFAULT 'preview',
  `taxonomy_profile` varchar(100) DEFAULT NULL,
  `basis_version` varchar(50) DEFAULT NULL,
  `basis_hash` char(64) DEFAULT NULL,
  `filing_approval_id` bigint(20) DEFAULT NULL,
  `filing_approval_hash` char(64) DEFAULT NULL,
  `validation_status` varchar(32) NOT NULL DEFAULT 'not_validated',
  `validation_errors_json` longtext DEFAULT NULL,
  `external_validator` varchar(50) DEFAULT NULL,
  `external_validation_status` varchar(32) NOT NULL DEFAULT 'not_configured',
  `external_validation_errors_json` longtext DEFAULT NULL,
  `external_validation_warnings_json` longtext DEFAULT NULL,
  `external_validation_log_path` varchar(1000) DEFAULT NULL,
  `external_validated_at` datetime DEFAULT NULL,
  `external_validated_sha256` char(64) DEFAULT NULL,
  `external_taxonomy_package_id` bigint(20) DEFAULT NULL,
  `external_taxonomy_sha256` char(64) DEFAULT NULL,
  `generated_filename` varchar(255) DEFAULT NULL,
  `generated_path` varchar(1000) DEFAULT NULL,
  `output_sha256` char(64) DEFAULT NULL,
  `generated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ixbrl_runs_company_accounting_period` (`company_id`,`accounting_period_id`),
  KEY `idx_ixbrl_runs_status` (`status`),
  KEY `idx_ixbrl_runs_filing_approval` (`filing_approval_id`),
  KEY `idx_ixbrl_external_taxonomy` (`external_taxonomy_package_id`),
  CONSTRAINT `fk_ixbrl_runs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ixbrl_runs_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ixbrl_generation_facts`
--

DROP TABLE IF EXISTS `ixbrl_generation_facts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ixbrl_generation_facts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) NOT NULL,
  `fact_key` varchar(150) NOT NULL,
  `taxonomy_concept` varchar(255) NOT NULL,
  `label` varchar(255) NOT NULL,
  `value_type` enum('numeric','text','date','boolean') NOT NULL,
  `numeric_value` decimal(18,2) DEFAULT NULL,
  `text_value` text DEFAULT NULL,
  `date_value` date DEFAULT NULL,
  `unit_ref` varchar(50) DEFAULT 'GBP',
  `decimals_value` varchar(20) DEFAULT '2',
  `context_ref` varchar(100) NOT NULL,
  `dimensions_json` longtext DEFAULT NULL,
  `source_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ixbrl_generation_facts_key_context` (`run_id`,`fact_key`,`context_ref`),
  KEY `idx_ixbrl_generation_facts_run` (`run_id`),
  CONSTRAINT `fk_ixbrl_generation_facts_run` FOREIGN KEY (`run_id`) REFERENCES `ixbrl_generation_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ixbrl_fact_mappings`
--

DROP TABLE IF EXISTS `ixbrl_fact_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ixbrl_fact_mappings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fact_key` varchar(150) NOT NULL,
  `taxonomy_concept` varchar(255) NOT NULL,
  `namespace_uri` varchar(255) DEFAULT NULL,
  `local_name` varchar(255) DEFAULT NULL,
  `label` varchar(255) NOT NULL,
  `value_type` enum('numeric','text','date','boolean') NOT NULL,
  `calculation_type` enum('nominal_subtype_sum','nominal_account_sum','manual','derived','company_field','period_field','disclosure_field','disclosure_statement','absence_statement','application_value','fixed_marker') NOT NULL,
  `source_key` varchar(150) DEFAULT NULL,
  `sign_multiplier` decimal(8,2) NOT NULL DEFAULT 1.00,
  `period_type` enum('instant','duration') DEFAULT NULL,
  `unit_ref` varchar(50) DEFAULT NULL,
  `decimals_value` varchar(20) DEFAULT NULL,
  `context_profile` varchar(100) DEFAULT NULL,
  `dimensions_json` longtext DEFAULT NULL,
  `comparative_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ixbrl_fact_mappings_fact_key` (`fact_key`),
  KEY `idx_ixbrl_fact_mappings_active_sort` (`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hmrc_obligation_submission_links`
--

DROP TABLE IF EXISTS `hmrc_obligation_submission_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hmrc_obligation_submission_links` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `hmrc_obligation_id` int(11) NOT NULL,
  `submission_id` bigint(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hmrc_obligation_submission` (`hmrc_obligation_id`,`submission_id`),
  KEY `idx_hmrc_obligation_submission_submission` (`submission_id`),
  CONSTRAINT `fk_hmrc_obligation_submission_obligation` FOREIGN KEY (`hmrc_obligation_id`) REFERENCES `hmrc_obligations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_hmrc_obligation_submission_submission` FOREIGN KEY (`submission_id`) REFERENCES `hmrc_ct600_submissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ixbrl_accounts_disclosures`
--

DROP TABLE IF EXISTS `ixbrl_accounts_disclosures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ixbrl_accounts_disclosures` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `accounting_standard` varchar(20) NOT NULL DEFAULT 'FRS_105',
  `average_number_employees` int(10) unsigned DEFAULT NULL,
  `entity_dormant` tinyint(1) DEFAULT NULL,
  `entity_trading_status` varchar(30) DEFAULT NULL,
  `micro_entity_eligibility_confirmed` tinyint(1) DEFAULT NULL,
  `going_concern_basis_appropriate` tinyint(1) DEFAULT NULL,
  `has_material_off_balance_sheet_arrangements` tinyint(1) DEFAULT NULL,
  `has_director_advances_credits_or_guarantees` tinyint(1) DEFAULT NULL,
  `has_financial_commitments_guarantees_or_contingencies` tinyint(1) DEFAULT NULL,
  `accounts_approval_date` date DEFAULT NULL,
  `approving_director_name` varchar(255) DEFAULT NULL,
  `prepared_under_small_companies_regime` tinyint(1) DEFAULT NULL,
  `audit_exempt_section_477` tinyint(1) DEFAULT NULL,
  `directors_acknowledge_responsibilities` tinyint(1) DEFAULT NULL,
  `members_have_not_required_audit` tinyint(1) DEFAULT NULL,
  `revision` int(10) unsigned NOT NULL DEFAULT 1,
  `created_by` varchar(100) NOT NULL,
  `updated_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ixbrl_disclosures_company_period` (`company_id`,`accounting_period_id`),
  KEY `idx_ixbrl_disclosures_period` (`accounting_period_id`),
  CONSTRAINT `chk_ixbrl_disclosures_standard` CHECK (`accounting_standard` = 'FRS_105'),
  CONSTRAINT `chk_ixbrl_disclosures_entity_dormant` CHECK (`entity_dormant` is null or `entity_dormant` in (0,1)),
  CONSTRAINT `chk_ixbrl_disclosures_trading_status` CHECK (`entity_trading_status` is null or `entity_trading_status` in ('trading','never_traded','no_longer_trading')),
  CONSTRAINT `chk_ixbrl_disclosures_micro_entity_eligibility` CHECK (`micro_entity_eligibility_confirmed` is null or `micro_entity_eligibility_confirmed` in (0,1)),
  CONSTRAINT `chk_ixbrl_disclosures_going_concern` CHECK (`going_concern_basis_appropriate` is null or `going_concern_basis_appropriate` in (0,1)),
  CONSTRAINT `chk_ixbrl_disclosures_off_balance_sheet` CHECK (`has_material_off_balance_sheet_arrangements` is null or `has_material_off_balance_sheet_arrangements` in (0,1)),
  CONSTRAINT `chk_ixbrl_disclosures_director_advances` CHECK (`has_director_advances_credits_or_guarantees` is null or `has_director_advances_credits_or_guarantees` in (0,1)),
  CONSTRAINT `chk_ixbrl_disclosures_financial_commitments` CHECK (`has_financial_commitments_guarantees_or_contingencies` is null or `has_financial_commitments_guarantees_or_contingencies` in (0,1)),
  CONSTRAINT `chk_ixbrl_disclosures_small_companies` CHECK (`prepared_under_small_companies_regime` is null or `prepared_under_small_companies_regime` in (0,1)),
  CONSTRAINT `chk_ixbrl_disclosures_audit_exempt` CHECK (`audit_exempt_section_477` is null or `audit_exempt_section_477` in (0,1)),
  CONSTRAINT `chk_ixbrl_disclosures_directors_responsibilities` CHECK (`directors_acknowledge_responsibilities` is null or `directors_acknowledge_responsibilities` in (0,1)),
  CONSTRAINT `chk_ixbrl_disclosures_members_audit` CHECK (`members_have_not_required_audit` is null or `members_have_not_required_audit` in (0,1)),
  CONSTRAINT `fk_ixbrl_disclosures_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ixbrl_disclosures_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `ixbrl_fact_mappings` (`fact_key`,`taxonomy_concept`,`namespace_uri`,`local_name`,`label`,`value_type`,`calculation_type`,`source_key`,`sign_multiplier`,`period_type`,`unit_ref`,`decimals_value`,`context_profile`,`dimensions_json`,`comparative_enabled`,`is_required`,`sort_order`,`is_active`) VALUES
('entity_name','bus:EntityCurrentLegalOrRegisteredName','http://xbrl.frc.org.uk/cd/2026-01-01/business','EntityCurrentLegalOrRegisteredName','Entity name','text','company_field','company_name',1,'duration',NULL,NULL,'duration',NULL,0,1,10,1),
('company_number','bus:UKCompaniesHouseRegisteredNumber','http://xbrl.frc.org.uk/cd/2026-01-01/business','UKCompaniesHouseRegisteredNumber','Company number','text','company_field','company_number',1,'duration',NULL,NULL,'duration',NULL,0,1,20,1),
('country_formation_or_incorporation','bus:CountryFormationOrIncorporation','http://xbrl.frc.org.uk/cd/2026-01-01/business','CountryFormationOrIncorporation','Country of formation or incorporation','text','fixed_marker','companies_house_jurisdiction',1,'duration',NULL,NULL,'duration_country_formation','{"countries:CountriesRegionsDimension":"countries:EnglandWales"}',0,1,21,1),
('legal_form_entity','bus:LegalFormEntity','http://xbrl.frc.org.uk/cd/2026-01-01/business','LegalFormEntity','Legal form of entity','text','fixed_marker','companies_house_type',1,'duration',NULL,NULL,'duration_legal_form','{"bus:LegalFormEntityDimension":"bus:PrivateLimitedCompanyLtd"}',0,1,22,1),
('registered_office_address_line_1','bus:AddressLine1','http://xbrl.frc.org.uk/cd/2026-01-01/business','AddressLine1','Registered office address line 1','text','company_field','registered_office_address_line_1',1,'duration',NULL,NULL,'duration_registered_office','{"bus:EntityContactTypeDimension":"bus:RegisteredOffice","countries:CountriesRegionsDimension":"countries:UnitedKingdom"}',0,1,23,1),
('registered_office_address_line_2','bus:AddressLine2','http://xbrl.frc.org.uk/cd/2026-01-01/business','AddressLine2','Registered office address line 2','text','company_field','registered_office_address_line_2',1,'duration',NULL,NULL,'duration_registered_office','{"bus:EntityContactTypeDimension":"bus:RegisteredOffice","countries:CountriesRegionsDimension":"countries:UnitedKingdom"}',0,1,24,1),
('registered_office_address_line_3','bus:AddressLine3','http://xbrl.frc.org.uk/cd/2026-01-01/business','AddressLine3','Registered office address line 3','text','company_field','registered_office_address_line_3',1,'duration',NULL,NULL,'duration_registered_office','{"bus:EntityContactTypeDimension":"bus:RegisteredOffice","countries:CountriesRegionsDimension":"countries:UnitedKingdom"}',0,1,25,1),
('registered_office_postal_code','bus:PostalCodeZip','http://xbrl.frc.org.uk/cd/2026-01-01/business','PostalCodeZip','Registered office postal code','text','company_field','registered_office_postal_code',1,'duration',NULL,NULL,'duration_registered_office','{"bus:EntityContactTypeDimension":"bus:RegisteredOffice","countries:CountriesRegionsDimension":"countries:UnitedKingdom"}',0,1,26,1),
('period_start','bus:StartDateForPeriodCoveredByReport','http://xbrl.frc.org.uk/cd/2026-01-01/business','StartDateForPeriodCoveredByReport','Period start','date','period_field','period_start',1,'instant',NULL,NULL,'instant_start',NULL,0,1,30,1),
('period_end','bus:EndDateForPeriodCoveredByReport','http://xbrl.frc.org.uk/cd/2026-01-01/business','EndDateForPeriodCoveredByReport','Period end','date','period_field','period_end',1,'instant',NULL,NULL,'instant_end',NULL,0,1,40,1),
('balance_sheet_date','bus:BalanceSheetDate','http://xbrl.frc.org.uk/cd/2026-01-01/business','BalanceSheetDate','Balance sheet date','date','period_field','period_end',1,'instant',NULL,NULL,'instant_end',NULL,0,1,50,1),
('accounts_approval_date','core:DateAuthorisationFinancialStatementsForIssue','http://xbrl.frc.org.uk/fr/2026-01-01/core','DateAuthorisationFinancialStatementsForIssue','Accounts approval date','date','disclosure_field','accounts_approval_date',1,'instant',NULL,NULL,'instant_approval',NULL,0,1,60,1),
('approving_director_name','bus:NameEntityOfficer','http://xbrl.frc.org.uk/cd/2026-01-01/business','NameEntityOfficer','Director approving the financial statements','text','disclosure_field','approving_director_name',1,'duration',NULL,NULL,'duration_director_1','{"bus:EntityOfficersDimension":"bus:Director1"}',0,1,70,1),
('director_signing_financial_statements','core:DirectorSigningFinancialStatements','http://xbrl.frc.org.uk/fr/2026-01-01/core','DirectorSigningFinancialStatements','Director signing financial statements','text','fixed_marker','approving_director_name',1,'duration',NULL,NULL,'duration_director_1','{"bus:EntityOfficersDimension":"bus:Director1"}',0,1,75,1),
('entity_trading_status','bus:EntityTradingStatus','http://xbrl.frc.org.uk/cd/2026-01-01/business','EntityTradingStatus','Entity trading status','text','fixed_marker','entity_trading_status',1,'duration',NULL,NULL,'duration_trading_status',NULL,0,1,80,1),
('accounting_standards_applied','bus:AccountingStandardsApplied','http://xbrl.frc.org.uk/cd/2026-01-01/business','AccountingStandardsApplied','Accounting standards applied','text','fixed_marker','accounting_standard',1,'duration',NULL,NULL,'duration_accounting_standards','{"bus:AccountingStandardsDimension":"bus:Micro-entities"}',0,1,85,1),
('accounts_status','bus:AccountsStatusAuditedOrUnaudited','http://xbrl.frc.org.uk/cd/2026-01-01/business','AccountsStatusAuditedOrUnaudited','Accounts status audited or unaudited','text','fixed_marker','audit_exempt_section_477',1,'duration',NULL,NULL,'duration_accounts_status','{"bus:AccountsStatusDimension":"bus:AuditExempt-NoAccountantsReport"}',0,1,90,1),
('turnover','core:TurnoverRevenue','http://xbrl.frc.org.uk/fr/2026-01-01/core','TurnoverRevenue','Turnover','numeric','derived','turnover',1,'duration','GBP','2','duration',NULL,1,1,100,1),
('other_income','core:OtherOperatingIncomeFormat2','http://xbrl.frc.org.uk/fr/2026-01-01/core','OtherOperatingIncomeFormat2','Other income','numeric','derived','other_income',1,'duration','GBP','2','duration',NULL,1,1,110,1),
('raw_materials_consumables','core:RawMaterialsConsumablesUsed','http://xbrl.frc.org.uk/fr/2026-01-01/core','RawMaterialsConsumablesUsed','Raw materials and consumables','numeric','derived','raw_materials_consumables',1,'duration','GBP','2','duration',NULL,1,1,120,1),
('staff_costs','core:StaffCostsEmployeeBenefitsExpense','http://xbrl.frc.org.uk/fr/2026-01-01/core','StaffCostsEmployeeBenefitsExpense','Staff costs','numeric','derived','staff_costs',1,'duration','GBP','2','duration',NULL,1,1,130,1),
('depreciation_write_offs','core:DepreciationAmortisationImpairmentExpense','http://xbrl.frc.org.uk/fr/2026-01-01/core','DepreciationAmortisationImpairmentExpense','Depreciation and other amounts written off assets','numeric','derived','depreciation_write_offs',1,'duration','GBP','2','duration',NULL,1,1,140,1),
('other_charges','core:OtherExternalCharges','http://xbrl.frc.org.uk/fr/2026-01-01/core','OtherExternalCharges','Other charges','numeric','derived','other_charges',1,'duration','GBP','2','duration',NULL,1,1,145,1),
('tax_on_profit','core:TaxTaxCreditOnProfitOrLossOnOrdinaryActivities','http://xbrl.frc.org.uk/fr/2026-01-01/core','TaxTaxCreditOnProfitOrLossOnOrdinaryActivities','Tax on profit / loss','numeric','derived','tax_on_profit',1,'duration','GBP','2','duration',NULL,1,1,150,1),
('profit_loss','core:ProfitLoss','http://xbrl.frc.org.uk/fr/2026-01-01/core','ProfitLoss','Profit / loss for the financial year','numeric','derived','profit_loss',1,'duration','GBP','2','duration',NULL,1,1,160,1),
('fixed_assets','core:FixedAssets','http://xbrl.frc.org.uk/fr/2026-01-01/core','FixedAssets','Fixed assets','numeric','derived','fixed_assets',1,'instant','GBP','2','instant_end',NULL,1,1,200,1),
('current_assets','core:CurrentAssets','http://xbrl.frc.org.uk/fr/2026-01-01/core','CurrentAssets','Current assets','numeric','derived','current_assets',1,'instant','GBP','2','instant_end',NULL,1,1,210,1),
('prepayments_accrued_income','core:PrepaymentsAccruedIncome','http://xbrl.frc.org.uk/fr/2026-01-01/core','PrepaymentsAccruedIncome','Prepayments and accrued income','numeric','derived','prepayments_accrued_income',1,'instant','GBP','2','instant_end',NULL,1,1,215,1),
('creditors_within_one_year','core:Creditors','http://xbrl.frc.org.uk/fr/2026-01-01/core','Creditors','Creditors within one year','numeric','derived','creditors_within_one_year',1,'instant','GBP','2','instant_end_creditors_within','{"core:MaturitiesOrExpirationPeriodsDimension":"core:WithinOneYear"}',1,1,220,1),
('net_current_assets_liabilities','core:NetCurrentAssetsLiabilities','http://xbrl.frc.org.uk/fr/2026-01-01/core','NetCurrentAssetsLiabilities','Net current assets / liabilities','numeric','derived','net_current_assets_liabilities',1,'instant','GBP','2','instant_end',NULL,1,1,230,1),
('total_assets_less_current_liabilities','core:TotalAssetsLessCurrentLiabilities','http://xbrl.frc.org.uk/fr/2026-01-01/core','TotalAssetsLessCurrentLiabilities','Total assets less current liabilities','numeric','derived','total_assets_less_current_liabilities',1,'instant','GBP','2','instant_end',NULL,1,1,240,1),
('creditors_after_one_year','core:Creditors','http://xbrl.frc.org.uk/fr/2026-01-01/core','Creditors','Creditors after more than one year','numeric','derived','creditors_after_more_than_one_year',1,'instant','GBP','2','instant_end_creditors_after','{"core:MaturitiesOrExpirationPeriodsDimension":"core:AfterOneYear"}',1,1,250,1),
('net_assets_liabilities','core:NetAssetsLiabilities','http://xbrl.frc.org.uk/fr/2026-01-01/core','NetAssetsLiabilities','Net assets / liabilities','numeric','derived','net_assets_liabilities',1,'instant','GBP','2','instant_end',NULL,1,1,260,1),
('equity','core:Equity','http://xbrl.frc.org.uk/fr/2026-01-01/core','Equity','Equity','numeric','derived','equity_capital_reserves',1,'instant','GBP','2','instant_end',NULL,1,1,270,1),
('average_number_employees','core:AverageNumberEmployeesDuringPeriod','http://xbrl.frc.org.uk/fr/2026-01-01/core','AverageNumberEmployeesDuringPeriod','Average number of employees','numeric','disclosure_field','average_number_employees',1,'duration','pure','0','duration',NULL,1,1,300,1),
('entity_dormant','bus:EntityDormantTruefalse','http://xbrl.frc.org.uk/cd/2026-01-01/business','EntityDormantTruefalse','Entity dormant','boolean','disclosure_field','entity_dormant',1,'duration',NULL,NULL,'duration',NULL,0,1,310,1),
('small_companies_regime_statement','direp:StatementThatAccountsHaveBeenPreparedInAccordanceWithProvisionsSmallCompaniesRegime','http://xbrl.frc.org.uk/reports/2026-01-01/direp','StatementThatAccountsHaveBeenPreparedInAccordanceWithProvisionsSmallCompaniesRegime','Small companies regime statement','text','disclosure_statement','prepared_under_small_companies_regime',1,'duration',NULL,NULL,'duration',NULL,0,1,320,1),
('audit_exemption_statement','direp:StatementThatCompanyEntitledToExemptionFromAuditUnderSection477CompaniesAct2006RelatingToSmallCompanies','http://xbrl.frc.org.uk/reports/2026-01-01/direp','StatementThatCompanyEntitledToExemptionFromAuditUnderSection477CompaniesAct2006RelatingToSmallCompanies','Audit exemption statement','text','disclosure_statement','audit_exempt_section_477',1,'duration',NULL,NULL,'duration',NULL,0,1,330,1),
('directors_responsibility_statement','direp:StatementThatDirectorsAcknowledgeTheirResponsibilitiesUnderCompaniesAct','http://xbrl.frc.org.uk/reports/2026-01-01/direp','StatementThatDirectorsAcknowledgeTheirResponsibilitiesUnderCompaniesAct','Directors responsibilities statement','text','disclosure_statement','directors_acknowledge_responsibilities',1,'duration',NULL,NULL,'duration',NULL,0,1,340,1),
('members_no_audit_statement','direp:StatementThatMembersHaveNotRequiredCompanyToObtainAnAudit','http://xbrl.frc.org.uk/reports/2026-01-01/direp','StatementThatMembersHaveNotRequiredCompanyToObtainAnAudit','Members have not required an audit statement','text','disclosure_statement','members_have_not_required_audit',1,'duration',NULL,NULL,'duration',NULL,0,1,350,1),
('no_material_off_balance_sheet_arrangements','core:GeneralDescriptionAnyOff-balanceSheetArrangementsIncludingNaturePurposeFinancialImpactOnEntity','http://xbrl.frc.org.uk/fr/2026-01-01/core','GeneralDescriptionAnyOff-balanceSheetArrangementsIncludingNaturePurposeFinancialImpactOnEntity','No material off-balance sheet arrangements','text','absence_statement','has_material_off_balance_sheet_arrangements',1,'duration',NULL,NULL,'duration',NULL,0,1,360,1),
('no_director_advances_or_credits','direp:GeneralDescriptionAdvancesCreditsToDirectorsIncludingTermsInterestRates','http://xbrl.frc.org.uk/reports/2026-01-01/direp','GeneralDescriptionAdvancesCreditsToDirectorsIncludingTermsInterestRates','Director advances and credits to directors','text','director_loan_statement','has_director_advances_credits_or_guarantees',1,'duration',NULL,NULL,'duration',NULL,0,1,361,1),
('no_director_guarantees','direp:GeneralDescriptionGuaranteesTheirTermsDirectors','http://xbrl.frc.org.uk/reports/2026-01-01/direp','GeneralDescriptionGuaranteesTheirTermsDirectors','No guarantees on behalf of directors','text','absence_statement','has_director_advances_credits_or_guarantees',1,'duration',NULL,NULL,'duration',NULL,0,1,362,1),
('no_capital_commitments','core:DescriptionCapitalCommitments','http://xbrl.frc.org.uk/fr/2026-01-01/core','DescriptionCapitalCommitments','No capital commitments','text','absence_statement','has_financial_commitments_guarantees_or_contingencies',1,'duration',NULL,NULL,'duration',NULL,0,1,363,1),
('no_financial_commitments','core:DescriptionFinancialCommitmentsOtherThanCapitalCommitments','http://xbrl.frc.org.uk/fr/2026-01-01/core','DescriptionFinancialCommitmentsOtherThanCapitalCommitments','No other financial commitments','text','absence_statement','has_financial_commitments_guarantees_or_contingencies',1,'duration',NULL,NULL,'duration',NULL,0,1,364,1),
('no_contingent_liabilities','core:GeneralDescriptionContingentLiabilitiesIncludingFinancialEffectUncertaintiesPossibleReimbursement','http://xbrl.frc.org.uk/fr/2026-01-01/core','GeneralDescriptionContingentLiabilitiesIncludingFinancialEffectUncertaintiesPossibleReimbursement','No contingent liabilities','text','absence_statement','has_financial_commitments_guarantees_or_contingencies',1,'duration',NULL,NULL,'duration',NULL,0,1,365,1),
('production_software','bus:NameProductionSoftware','http://xbrl.frc.org.uk/cd/2026-01-01/business','NameProductionSoftware','Production software','text','application_value','app_name',1,'duration',NULL,NULL,'duration',NULL,0,1,370,1),
('production_software_version','bus:VersionProductionSoftware','http://xbrl.frc.org.uk/cd/2026-01-01/business','VersionProductionSoftware','Production software version','text','application_value','app_version',1,'duration',NULL,NULL,'duration',NULL,0,1,371,1);

--
-- Table structure for table `nominal_account_subtypes`
--

DROP TABLE IF EXISTS `nominal_account_subtypes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `nominal_account_subtypes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `parent_account_type` enum('income','cost_of_sales','expense','asset','liability','equity') NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_subtype_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Baseline seed rows for table `nominal_account_subtypes`
--

INSERT INTO `nominal_account_subtypes` (`code`, `name`, `parent_account_type`, `sort_order`, `is_active`) VALUES
  ('bank', 'Bank', 'asset', 10, 1),
  ('prepayments', 'Prepayments', 'asset', 25, 1),
  ('fixed_asset', 'Fixed Asset', 'asset', 20, 1),
  ('director_loan_asset', 'Director Loan Asset', 'asset', 30, 1),
  ('participator_loan_asset', 'Participator Loan Asset', 'asset', 31, 1),
  ('trade_creditor', 'Trade Creditor', 'liability', 45, 1),
  ('expense_payable', 'Expense Payable', 'liability', 46, 1),
  ('director_loan_liability', 'Director Loan Liability', 'liability', 50, 1),
  ('participator_loan_liability', 'Participator Loan Liability', 'liability', 51, 1),
  ('vat_control', 'VAT Control', 'liability', 55, 1),
  ('ordinary_share_capital', 'Ordinary Share Capital', 'equity', 70, 1),
  ('capital_reserves', 'Capital Reserves', 'equity', 80, 1),
  ('dividends_payable', 'Dividends Payable', 'liability', 85, 1),
  ('overhead', 'Overhead', 'expense', 600, 1),
  ('hmrc_payable', 'HMRC Penalties & Interest Payable', 'liability', 610, 1),
  ('corp_tax', 'Corporation Tax', 'liability', 650, 1),
  ('corp_tax_expense', 'Corporation Tax Expense', 'expense', 625, 1),
  ('asset_disposal_gain', 'Asset Disposal Gain', 'income', 420, 1),
  ('depreciation_expense', 'Depreciation Expense', 'expense', 620, 1),
  ('asset_disposal_loss', 'Asset Disposal Loss', 'expense', 621, 1);

--
-- Table structure for table `nominal_accounts`
--

DROP TABLE IF EXISTS `nominal_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `nominal_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `name` varchar(255) NOT NULL,
  `account_type` enum('income','cost_of_sales','expense','asset','liability','equity') NOT NULL,
  `account_subtype_id` int(11) DEFAULT NULL,
  `tax_treatment` enum('allowable','disallowable','capital','other') NOT NULL DEFAULT 'allowable',
  `prepayment_candidate` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `origin_type` enum('manual','company_account_auto') NOT NULL DEFAULT 'manual',
  `origin_company_id` int(11) DEFAULT NULL,
  `origin_company_account_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_nominal_code` (`code`),
  KEY `idx_nominal_type_active` (`account_type`,`is_active`,`sort_order`),
  KEY `idx_nominal_prepayment_candidate` (`prepayment_candidate`,`account_type`,`is_active`),
  KEY `idx_nominal_subtype` (`account_subtype_id`),
  KEY `idx_nominal_origin` (`origin_type`,`origin_company_id`,`origin_company_account_id`),
  CONSTRAINT `fk_nominal_accounts_subtype` FOREIGN KEY (`account_subtype_id`) REFERENCES `nominal_account_subtypes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `prepayment_reviews`
--

DROP TABLE IF EXISTS `prepayment_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `prepayment_reviews` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `source_type` enum('transaction','transaction_split_line','expense_claim_line') NOT NULL,
  `source_id` bigint(20) NOT NULL,
  `status` enum('not_prepaid','prepaid') NOT NULL DEFAULT 'not_prepaid',
  `service_start_date` date DEFAULT NULL,
  `service_end_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `generated_journal_id` bigint(20) DEFAULT NULL,
  `reversal_journal_id` bigint(20) DEFAULT NULL,
  `current_schedule_id` bigint(20) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_prepayment_reviews_source` (`company_id`,`accounting_period_id`,`source_type`,`source_id`),
  KEY `idx_prepayment_reviews_period_status` (`company_id`,`accounting_period_id`,`status`),
  KEY `idx_prepayment_reviews_generated_journal` (`generated_journal_id`),
  KEY `idx_prepayment_reviews_reversal_journal` (`reversal_journal_id`),
  KEY `idx_prepayment_reviews_current_schedule` (`current_schedule_id`),
  CONSTRAINT `fk_prepayment_reviews_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prepayment_reviews_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prepayment_reviews_generated_journal` FOREIGN KEY (`generated_journal_id`) REFERENCES `journals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_prepayment_reviews_reversal_journal` FOREIGN KEY (`reversal_journal_id`) REFERENCES `journals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_prepayment_reviews_dates` CHECK (`service_start_date` IS NULL OR `service_end_date` IS NULL OR `service_start_date` <= `service_end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `prepayment_schedules`
--

DROP TABLE IF EXISTS `prepayment_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `prepayment_schedules` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `review_id` bigint(20) NOT NULL,
  `version_no` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `source_accounting_period_id` int(11) NOT NULL,
  `source_type` enum('transaction','transaction_split_line','expense_claim_line') NOT NULL,
  `source_id` bigint(20) NOT NULL,
  `source_journal_id` bigint(20) NOT NULL,
  `source_journal_line_id` bigint(20) NOT NULL,
  `source_date` date NOT NULL,
  `source_amount_pence` bigint(20) NOT NULL,
  `original_expense_nominal_id` int(11) NOT NULL,
  `asset_nominal_id` int(11) NOT NULL,
  `service_start_date` date NOT NULL,
  `service_end_date` date NOT NULL,
  `total_days` int(11) NOT NULL,
  `calculation_version` smallint(5) unsigned NOT NULL DEFAULT 2,
  `calculation_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` enum('draft','active','superseded','complete','needs_review') NOT NULL DEFAULT 'active',
  `superseded_by_schedule_id` bigint(20) DEFAULT NULL,
  `created_by` varchar(100) NOT NULL DEFAULT 'web_app',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prepayment_schedules_review_version` (`review_id`,`version_no`),
  KEY `idx_prepayment_schedules_company_source_period` (`company_id`,`source_accounting_period_id`,`status`),
  KEY `idx_prepayment_schedules_source` (`company_id`,`source_type`,`source_id`),
  KEY `idx_prepayment_schedules_source_journal` (`source_journal_id`),
  KEY `idx_prepayment_schedules_source_line` (`source_journal_line_id`),
  KEY `idx_prepayment_schedules_asset_nominal` (`asset_nominal_id`),
  KEY `idx_prepayment_schedules_superseded_by` (`superseded_by_schedule_id`),
  CONSTRAINT `fk_prepayment_schedules_review` FOREIGN KEY (`review_id`) REFERENCES `prepayment_reviews` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_prepayment_schedules_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prepayment_schedules_source_period` FOREIGN KEY (`source_accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_prepayment_schedules_source_journal` FOREIGN KEY (`source_journal_id`) REFERENCES `journals` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_prepayment_schedules_source_line` FOREIGN KEY (`source_journal_line_id`) REFERENCES `journal_lines` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_prepayment_schedules_expense_nominal` FOREIGN KEY (`original_expense_nominal_id`) REFERENCES `nominal_accounts` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_prepayment_schedules_asset_nominal` FOREIGN KEY (`asset_nominal_id`) REFERENCES `nominal_accounts` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_prepayment_schedules_superseded_by` FOREIGN KEY (`superseded_by_schedule_id`) REFERENCES `prepayment_schedules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_prepayment_schedules_amount` CHECK (`source_amount_pence` > 0),
  CONSTRAINT `chk_prepayment_schedules_days` CHECK (`total_days` > 0),
  CONSTRAINT `chk_prepayment_schedules_dates` CHECK (`service_start_date` <= `service_end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `prepayment_schedule_periods`
--

DROP TABLE IF EXISTS `prepayment_schedule_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `prepayment_schedule_periods` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `schedule_id` bigint(20) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `overlap_start` date DEFAULT NULL,
  `overlap_end` date DEFAULT NULL,
  `overlap_days` int(11) NOT NULL,
  `expense_pence` bigint(20) NOT NULL,
  `opening_deferred_pence` bigint(20) NOT NULL,
  `closing_deferred_pence` bigint(20) NOT NULL,
  `is_source_period` tinyint(1) NOT NULL DEFAULT 0,
  `allocation_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prepayment_schedule_period` (`schedule_id`,`accounting_period_id`),
  KEY `idx_prepayment_schedule_period_accounting_period` (`accounting_period_id`,`schedule_id`),
  CONSTRAINT `fk_prepayment_schedule_period_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `prepayment_schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prepayment_schedule_period_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_prepayment_schedule_period_days` CHECK (`overlap_days` >= 0),
  CONSTRAINT `chk_prepayment_schedule_period_expense` CHECK (`expense_pence` >= 0),
  CONSTRAINT `chk_prepayment_schedule_period_opening` CHECK (`opening_deferred_pence` >= 0),
  CONSTRAINT `chk_prepayment_schedule_period_closing` CHECK (`closing_deferred_pence` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `prepayment_schedule_postings`
--

DROP TABLE IF EXISTS `prepayment_schedule_postings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `prepayment_schedule_postings` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `schedule_id` bigint(20) NOT NULL,
  `schedule_period_id` bigint(20) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `journal_id` bigint(20) NOT NULL,
  `posting_role` enum('deferral','release') NOT NULL,
  `posting_type` enum('deferral','release','correction','reopen_compensation') NOT NULL,
  `effect_pence` bigint(20) NOT NULL,
  `target_pence` bigint(20) NOT NULL,
  `calculation_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by` varchar(100) NOT NULL DEFAULT 'web_app',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prepayment_schedule_posting_journal` (`journal_id`),
  KEY `idx_prepayment_postings_schedule_period` (`schedule_id`,`schedule_period_id`,`posting_role`),
  KEY `idx_prepayment_postings_accounting_period` (`accounting_period_id`,`posting_role`),
  CONSTRAINT `fk_prepayment_postings_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `prepayment_schedules` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_prepayment_postings_schedule_period` FOREIGN KEY (`schedule_period_id`) REFERENCES `prepayment_schedule_periods` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_prepayment_postings_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_prepayment_postings_journal` FOREIGN KEY (`journal_id`) REFERENCES `journals` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_prepayment_postings_effect` CHECK (`effect_pence` <> 0),
  CONSTRAINT `chk_prepayment_postings_target` CHECK (`target_pence` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

ALTER TABLE `prepayment_reviews`
  ADD CONSTRAINT `fk_prepayment_reviews_current_schedule` FOREIGN KEY (`current_schedule_id`) REFERENCES `prepayment_schedules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Table structure for table `role_card_permissions`
--

DROP TABLE IF EXISTS `role_card_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_card_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `card_key` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_card_permissions_role_card` (`role_id`,`card_key`),
  KEY `idx_role_card_permissions_card_key` (`card_key`),
  CONSTRAINT `fk_role_card_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `statement_import_mappings`
--

DROP TABLE IF EXISTS `statement_import_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statement_import_mappings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `upload_id` int(11) NOT NULL,
  `source_type` varchar(50) NOT NULL DEFAULT 'bank_account',
  `mapping_origin` varchar(20) NOT NULL DEFAULT 'manual',
  `source_mapping_upload_id` int(11) DEFAULT NULL,
  `original_headers_json` longtext NOT NULL,
  `mapping_json` longtext NOT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_statement_import_mappings_upload` (`upload_id`),
  KEY `idx_statement_import_mappings_origin` (`mapping_origin`),
  KEY `idx_statement_import_mappings_source_upload` (`source_mapping_upload_id`),
  CONSTRAINT `fk_statement_import_mappings_source_upload` FOREIGN KEY (`source_mapping_upload_id`) REFERENCES `statement_uploads` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_statement_import_mappings_upload` FOREIGN KEY (`upload_id`) REFERENCES `statement_uploads` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=187 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `statement_import_rows`
--

DROP TABLE IF EXISTS `statement_import_rows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statement_import_rows` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `upload_id` int(11) NOT NULL,
  `row_number` int(11) NOT NULL,
  `raw_json` longtext NOT NULL,
  `source_account` varchar(255) DEFAULT NULL,
  `source_created` varchar(255) DEFAULT NULL,
  `source_processed` varchar(255) DEFAULT NULL,
  `source_description` text DEFAULT NULL,
  `source_amount` varchar(100) DEFAULT NULL,
  `source_balance` varchar(100) DEFAULT NULL,
  `source_currency` varchar(32) DEFAULT NULL,
  `source_category` varchar(255) DEFAULT NULL,
  `source_document_url` varchar(2000) DEFAULT NULL,
  `accounting_period_id` int(11) DEFAULT NULL,
  `chosen_txn_date` date DEFAULT NULL,
  `chosen_date_source` enum('processed','created') DEFAULT NULL,
  `normalised_description` text DEFAULT NULL,
  `normalised_amount` decimal(12,2) DEFAULT NULL,
  `normalised_balance` decimal(12,2) DEFAULT NULL,
  `normalised_currency` varchar(10) DEFAULT NULL,
  `row_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `validation_status` enum('valid','invalid') NOT NULL DEFAULT 'invalid',
  `validation_notes` text DEFAULT NULL,
  `is_duplicate_within_upload` tinyint(1) NOT NULL DEFAULT 0,
  `is_duplicate_existing` tinyint(1) NOT NULL DEFAULT 0,
  `committed_transaction_id` bigint(20) DEFAULT NULL,
  `committed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_statement_import_rows_upload_row` (`upload_id`,`row_number`),
  KEY `idx_statement_import_rows_upload_status` (`upload_id`,`validation_status`),
  KEY `idx_statement_import_rows_upload_duplicates` (`upload_id`,`is_duplicate_within_upload`,`is_duplicate_existing`),
  KEY `idx_statement_import_rows_row_hash` (`row_hash`),
  KEY `idx_statement_import_rows_committed_transaction` (`committed_transaction_id`),
  KEY `idx_statement_import_rows_accounting_period` (`accounting_period_id`),
  KEY `idx_statement_import_rows_period_date_upload` (`accounting_period_id`,`chosen_txn_date`,`upload_id`),
  CONSTRAINT `fk_statement_import_rows_committed_transaction` FOREIGN KEY (`committed_transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_statement_import_rows_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_statement_import_rows_upload` FOREIGN KEY (`upload_id`) REFERENCES `statement_uploads` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8762 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `statement_uploads`
--

DROP TABLE IF EXISTS `statement_uploads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `statement_uploads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `source_type` varchar(50) NOT NULL DEFAULT 'bank_account',
  `workflow_status` enum('uploaded','mapped','staged','needs_accounting_period','committed','completed') NOT NULL DEFAULT 'uploaded',
  `statement_month` date NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `file_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_headers_json` longtext DEFAULT NULL,
  `date_range_start` date DEFAULT NULL,
  `date_range_end` date DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `rows_parsed` int(11) NOT NULL DEFAULT 0,
  `rows_inserted` int(11) NOT NULL DEFAULT 0,
  `rows_duplicate` int(11) NOT NULL DEFAULT 0,
  `rows_valid` int(11) NOT NULL DEFAULT 0,
  `rows_invalid` int(11) NOT NULL DEFAULT 0,
  `rows_duplicate_within_upload` int(11) NOT NULL DEFAULT 0,
  `rows_duplicate_existing` int(11) NOT NULL DEFAULT 0,
  `rows_ready_to_import` int(11) NOT NULL DEFAULT 0,
  `rows_committed` int(11) NOT NULL DEFAULT 0,
  `last_staged_at` datetime DEFAULT NULL,
  `committed_at` datetime DEFAULT NULL,
  `upload_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_statement_uploads_company_taxyear_month` (`company_id`,`accounting_period_id`,`statement_month`),
  KEY `fk_statement_uploads_accounting_period` (`accounting_period_id`),
  KEY `idx_statement_uploads_company_status` (`company_id`,`accounting_period_id`,`workflow_status`,`uploaded_at`),
  KEY `idx_statement_uploads_account` (`account_id`),
  KEY `idx_statement_uploads_company_file_hash` (`company_id`,`file_sha256`),
  KEY `idx_statement_uploads_company_uploaded` (`company_id`,`uploaded_at`),
  KEY `idx_statement_uploads_company_account_source_uploaded` (`company_id`,`account_id`,`source_type`,`uploaded_at`,`id`),
  KEY `idx_statement_uploads_company_month_period_rows` (`company_id`,`statement_month`,`accounting_period_id`,`rows_parsed`),
  CONSTRAINT `fk_statement_uploads_account` FOREIGN KEY (`account_id`) REFERENCES `company_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_statement_uploads_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_statement_uploads_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=194 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `corporation_tax_rate_rules`
--

DROP TABLE IF EXISTS `corporation_tax_rate_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `corporation_tax_rate_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `regime` varchar(32) NOT NULL DEFAULT 'non_ring_fence',
  `financial_year_start` date NOT NULL,
  `financial_year_end` date NOT NULL,
  `rule_version` varchar(32) NOT NULL,
  `main_rate` decimal(8,6) NOT NULL,
  `small_profits_rate` decimal(8,6) DEFAULT NULL,
  `lower_limit` decimal(12,2) DEFAULT NULL,
  `upper_limit` decimal(12,2) DEFAULT NULL,
  `marginal_relief_fraction` decimal(8,6) DEFAULT NULL,
  `source_url` varchar(500) NOT NULL,
  `source_updated_at` date DEFAULT NULL,
  `source_checked_at` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ct_rate_rule_version` (`regime`,`financial_year_start`,`rule_version`),
  KEY `idx_ct_rate_rules_lookup` (`regime`,`is_active`,`financial_year_start`,`financial_year_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Baseline seed rows for table `corporation_tax_rate_rules`
--

INSERT INTO `corporation_tax_rate_rules` (
  `regime`,
  `financial_year_start`,
  `financial_year_end`,
  `rule_version`,
  `main_rate`,
  `small_profits_rate`,
  `lower_limit`,
  `upper_limit`,
  `marginal_relief_fraction`,
  `source_url`,
  `source_updated_at`,
  `source_checked_at`,
  `is_active`,
  `notes`
) VALUES
  ('non_ring_fence', '2022-04-01', '2023-03-31', 'govuk-2026-04-01', 0.190000, NULL, NULL, NULL, NULL, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-05-26', 1, 'Main rate for all non-ring-fence profits before the 1 April 2023 small profits/main rate split.'),
  ('non_ring_fence', '2023-04-01', '2024-03-31', 'govuk-2026-04-01', 0.250000, 0.190000, 50000.00, 250000.00, 0.015000, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-05-26', 1, 'GOV.UK rates table shows small profits rate 19%, main rate 25%, lower limit 50000, upper limit 250000, standard fraction 3/200.'),
  ('non_ring_fence', '2024-04-01', '2025-03-31', 'govuk-2026-04-01', 0.250000, 0.190000, 50000.00, 250000.00, 0.015000, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-05-26', 1, 'GOV.UK rates table shows small profits rate 19%, main rate 25%, lower limit 50000, upper limit 250000, standard fraction 3/200.'),
  ('non_ring_fence', '2025-04-01', '2026-03-31', 'govuk-2026-04-01', 0.250000, 0.190000, 50000.00, 250000.00, 0.015000, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-05-26', 1, 'GOV.UK rates table shows small profits rate 19%, main rate 25%, lower limit 50000, upper limit 250000, standard fraction 3/200.'),
  ('non_ring_fence', '2026-04-01', '2027-03-31', 'govuk-2026-04-01', 0.250000, 0.190000, 50000.00, 250000.00, 0.015000, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-05-26', 1, 'GOV.UK rates table shows small profits rate 19%, main rate 25%, lower limit 50000, upper limit 250000, standard fraction 3/200.');

--
-- Table structure for table `tax_rate_rules`
--

DROP TABLE IF EXISTS `tax_rate_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_rate_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tax_domain` varchar(64) NOT NULL,
  `regime` varchar(64) NOT NULL DEFAULT '',
  `rule_key` varchar(96) NOT NULL,
  `rule_label` varchar(255) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL DEFAULT '9999-12-31',
  `value_type` varchar(32) NOT NULL,
  `rate_value` decimal(10,6) DEFAULT NULL,
  `amount_value` decimal(14,2) DEFAULT NULL,
  `fraction_value` decimal(10,6) DEFAULT NULL,
  `source_url` varchar(500) NOT NULL,
  `source_updated_at` date DEFAULT NULL,
  `source_checked_at` date NOT NULL,
  `rule_version` varchar(64) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tax_rate_rule_version` (`tax_domain`,`regime`,`rule_key`,`period_start`,`period_end`,`rule_version`),
  KEY `idx_tax_rate_rules_lookup` (`tax_domain`,`regime`,`rule_key`,`is_active`,`period_start`,`period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Baseline seed rows for table `tax_rate_rules`
--

INSERT INTO `tax_rate_rules` (
  `tax_domain`,
  `regime`,
  `rule_key`,
  `rule_label`,
  `period_start`,
  `period_end`,
  `value_type`,
  `rate_value`,
  `amount_value`,
  `fraction_value`,
  `source_url`,
  `source_updated_at`,
  `source_checked_at`,
  `rule_version`,
  `is_active`,
  `notes`
) VALUES
  ('capital_allowances', 'plant_machinery', 'aia_annual_limit', 'Annual investment allowance limit', '2019-01-01', '9999-12-31', 'amount', NULL, 1000000.00, NULL, 'https://www.gov.uk/capital-allowances/annual-investment-allowance', NULL, '2026-07-07', 'govuk-seed-ca-aia-2019', 1, 'GOV.UK AIA page shows the AIA amount as GBP 1 million from 1 January 2019 for limited companies.'),
  ('capital_allowances', 'plant_machinery', 'main_pool_wda', 'Main pool writing down allowance', '1900-01-01', '2026-03-31', 'rate', 0.180000, NULL, NULL, 'https://www.gov.uk/work-out-capital-allowances/rates-and-pools', NULL, '2026-07-07', 'govuk-seed-ca-main-wda-before-2026', 1, 'GOV.UK rates and pools page shows the main pool rate as 18% before April 2026.'),
  ('capital_allowances', 'plant_machinery', 'main_pool_wda', 'Main pool writing down allowance', '2026-04-01', '9999-12-31', 'rate', 0.140000, NULL, NULL, 'https://www.gov.uk/work-out-capital-allowances/rates-and-pools', NULL, '2026-07-07', 'govuk-seed-ca-main-wda-from-2026', 1, 'GOV.UK rates and pools page shows the main pool rate as 14% from 1 April 2026 for Corporation Tax.'),
  ('capital_allowances', 'plant_machinery', 'special_rate_pool_wda', 'Special rate pool writing down allowance', '1900-01-01', '9999-12-31', 'rate', 0.060000, NULL, NULL, 'https://www.gov.uk/work-out-capital-allowances/rates-and-pools', NULL, '2026-07-07', 'govuk-seed-ca-special-wda', 1, 'GOV.UK rates and pools page shows the special rate pool rate as 6%.'),
  ('corporation_tax', 'special_unit_trust_oeic', 'special_rate', 'Special rate for unit trusts and open-ended investment companies', '2022-04-01', '2023-03-31', 'rate', 0.200000, NULL, NULL, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-07-07', 'govuk-seed-ct-special-2022', 1, 'GOV.UK Corporation Tax rates table shows this special rate.'),
  ('corporation_tax', 'special_unit_trust_oeic', 'special_rate', 'Special rate for unit trusts and open-ended investment companies', '2023-04-01', '2024-03-31', 'rate', 0.200000, NULL, NULL, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-07-07', 'govuk-seed-ct-special-2023', 1, 'GOV.UK Corporation Tax rates table shows this special rate.'),
  ('corporation_tax', 'special_unit_trust_oeic', 'special_rate', 'Special rate for unit trusts and open-ended investment companies', '2024-04-01', '2025-03-31', 'rate', 0.200000, NULL, NULL, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-07-07', 'govuk-seed-ct-special-2024', 1, 'GOV.UK Corporation Tax rates table shows this special rate.'),
  ('corporation_tax', 'special_unit_trust_oeic', 'special_rate', 'Special rate for unit trusts and open-ended investment companies', '2025-04-01', '2026-03-31', 'rate', 0.200000, NULL, NULL, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-07-07', 'govuk-seed-ct-special-2025', 1, 'GOV.UK Corporation Tax rates table shows this special rate.'),
  ('corporation_tax', 'special_unit_trust_oeic', 'special_rate', 'Special rate for unit trusts and open-ended investment companies', '2026-04-01', '2027-03-31', 'rate', 0.200000, NULL, NULL, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-07-07', 'govuk-seed-ct-special-2026', 1, 'GOV.UK Corporation Tax rates table shows this special rate.'),
  ('corporation_tax', 'ring_fence', 'small_profits_rate', 'Small ring fence profits rate under GBP 300,000', '2015-04-01', '2023-03-31', 'rate', 0.190000, NULL, NULL, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-07-07', 'govuk-seed-ct-rf-small-2015-2022', 1, 'GOV.UK ring fence table shows this rate for 2015 to 2022.'),
  ('corporation_tax', 'ring_fence', 'main_rate', 'Main ring fence rate over GBP 1,500,000', '2015-04-01', '2023-03-31', 'rate', 0.300000, NULL, NULL, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-07-07', 'govuk-seed-ct-rf-main-2015-2022', 1, 'GOV.UK ring fence table shows this rate for 2015 to 2022.'),
  ('corporation_tax', 'ring_fence', 'ring_fence_fraction', 'Ring fence fraction', '2015-04-01', '2023-03-31', 'fraction', NULL, NULL, 0.027500, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-07-07', 'govuk-seed-ct-rf-fraction-2015-2022', 1, 'GOV.UK ring fence table shows the ring fence fraction.'),
  ('corporation_tax', 'ring_fence', 'small_profits_rate', 'Small ring fence profits rate under GBP 50,000', '2023-04-01', '2027-03-31', 'rate', 0.190000, NULL, NULL, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-07-07', 'govuk-seed-ct-rf-small-2023-2026', 1, 'GOV.UK ring fence table shows this rate for 2023 to 2026.'),
  ('corporation_tax', 'ring_fence', 'main_rate', 'Main ring fence profits rate over GBP 250,000', '2023-04-01', '2027-03-31', 'rate', 0.300000, NULL, NULL, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-07-07', 'govuk-seed-ct-rf-main-2023-2026', 1, 'GOV.UK ring fence table shows this rate for 2023 to 2026.'),
  ('corporation_tax', 'ring_fence', 'ring_fence_fraction', 'Ring fence fraction', '2023-04-01', '2027-03-31', 'fraction', NULL, NULL, 0.027500, 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax', '2026-04-01', '2026-07-07', 'govuk-seed-ct-rf-fraction-2023-2026', 1, 'GOV.UK ring fence table shows the ring fence fraction.');

--
-- Table structure for table `corporation_tax_treatment_rules`
--

DROP TABLE IF EXISTS `corporation_tax_treatment_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `corporation_tax_treatment_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rule_code` varchar(64) NOT NULL,
  `rule_version` varchar(32) NOT NULL,
  `priority` int(11) NOT NULL DEFAULT 100,
  `nominal_account_id` int(11) DEFAULT NULL,
  `nominal_code` varchar(32) DEFAULT NULL,
  `account_type` enum('income','cost_of_sales','expense','asset','liability','equity') DEFAULT NULL,
  `name_contains` varchar(255) DEFAULT NULL,
  `tax_treatment` enum('allowable','disallowable','capital','other') NOT NULL,
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `source_url` varchar(500) NOT NULL,
  `source_checked_at` date NOT NULL,
  `rationale` text NOT NULL,
  `review_status` enum('seeded','needs_review','reviewed') NOT NULL DEFAULT 'seeded',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ct_treatment_rule_version` (`rule_code`,`rule_version`),
  KEY `idx_ct_treatment_rules_lookup` (`is_active`,`priority`,`effective_from`,`effective_to`),
  KEY `idx_ct_treatment_rules_nominal` (`nominal_account_id`),
  KEY `idx_ct_treatment_rules_code` (`nominal_code`),
  CONSTRAINT `fk_ct_treatment_rule_nominal` FOREIGN KEY (`nominal_account_id`) REFERENCES `nominal_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tax_loss_carryforwards`
--

DROP TABLE IF EXISTS `tax_loss_carryforwards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_loss_carryforwards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `origin_accounting_period_id` int(11) NOT NULL,
  `origin_ct_period_id` int(11) DEFAULT NULL,
  `amount_originated` decimal(12,2) NOT NULL,
  `amount_used` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_remaining` decimal(12,2) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tax_loss_origin` (`company_id`,`origin_accounting_period_id`),
  KEY `fk_tax_loss_accounting_period` (`origin_accounting_period_id`),
  KEY `idx_tax_loss_origin_ct_period` (`origin_ct_period_id`),
  CONSTRAINT `fk_tax_loss_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tax_loss_accounting_period` FOREIGN KEY (`origin_accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tax_loss_origin_ct_period` FOREIGN KEY (`origin_ct_period_id`) REFERENCES `corporation_tax_periods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tax_loss_movement_history`
--

DROP TABLE IF EXISTS `tax_loss_movement_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_loss_movement_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `ct_period_id` int(11) DEFAULT NULL,
  `computation_hash` varchar(64) NOT NULL,
  `loss_created` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loss_brought_forward` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loss_utilised` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loss_carried_forward` decimal(12,2) NOT NULL DEFAULT 0.00,
  `taxable_before_losses` decimal(12,2) NOT NULL DEFAULT 0.00,
  `taxable_profit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `computed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tax_loss_history_period` (`company_id`,`accounting_period_id`,`computed_at`),
  KEY `idx_tax_loss_history_hash` (`company_id`,`accounting_period_id`,`computation_hash`),
  KEY `fk_tax_loss_history_accounting_period` (`accounting_period_id`),
  KEY `idx_tax_loss_history_ct_period` (`ct_period_id`),
  CONSTRAINT `fk_tax_loss_history_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tax_loss_history_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tax_loss_history_ct_period` FOREIGN KEY (`ct_period_id`) REFERENCES `corporation_tax_periods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1003 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `accounting_periods`
--

DROP TABLE IF EXISTS `accounting_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `label` varchar(64) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_company_label` (`company_id`,`label`),
  UNIQUE KEY `uniq_company_period` (`company_id`,`period_start`,`period_end`),
  KEY `idx_accounting_periods_company_period` (`company_id`,`period_start`,`period_end`),
  CONSTRAINT `fk_accounting_periods_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `accounting_period_month_confirmations`
--

DROP TABLE IF EXISTS `accounting_period_month_confirmations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_period_month_confirmations` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `month_start` date NOT NULL,
  `confirmation_type` varchar(64) NOT NULL DEFAULT 'no_financial_activity',
  `notes` text DEFAULT NULL,
  `evidence_json` longtext NOT NULL,
  `confirmed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `confirmed_by` varchar(100) NOT NULL DEFAULT 'web_app',
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_month_confirmation` (`company_id`,`accounting_period_id`,`month_start`,`confirmation_type`),
  KEY `idx_ap_month_confirmations_period` (`company_id`,`accounting_period_id`,`revoked_at`),
  KEY `fk_ap_month_confirmations_period` (`accounting_period_id`),
  CONSTRAINT `fk_ap_month_confirmations_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ap_month_confirmations_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transaction_category_audit`
--

DROP TABLE IF EXISTS `transaction_category_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction_category_audit` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `transaction_id` bigint(20) NOT NULL,
  `old_nominal_account_id` int(11) DEFAULT NULL,
  `new_nominal_account_id` int(11) DEFAULT NULL,
  `old_category_status` enum('uncategorised','auto','manual') DEFAULT NULL,
  `new_category_status` enum('uncategorised','auto','manual') DEFAULT NULL,
  `old_auto_rule_id` int(11) DEFAULT NULL,
  `new_auto_rule_id` int(11) DEFAULT NULL,
  `old_is_auto_excluded` tinyint(1) NOT NULL DEFAULT 0,
  `new_is_auto_excluded` tinyint(1) NOT NULL DEFAULT 0,
  `changed_by` varchar(100) NOT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_transaction` (`transaction_id`),
  KEY `idx_audit_changed_at` (`changed_at`),
  KEY `fk_transaction_category_audit_old_nominal` (`old_nominal_account_id`),
  KEY `fk_transaction_category_audit_new_nominal` (`new_nominal_account_id`),
  KEY `idx_audit_old_auto_rule` (`old_auto_rule_id`),
  KEY `idx_audit_new_auto_rule` (`new_auto_rule_id`),
  CONSTRAINT `fk_transaction_category_audit_new_auto_rule` FOREIGN KEY (`new_auto_rule_id`) REFERENCES `categorisation_rules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transaction_category_audit_new_nominal` FOREIGN KEY (`new_nominal_account_id`) REFERENCES `nominal_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transaction_category_audit_old_auto_rule` FOREIGN KEY (`old_auto_rule_id`) REFERENCES `categorisation_rules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transaction_category_audit_old_nominal` FOREIGN KEY (`old_nominal_account_id`) REFERENCES `nominal_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transaction_category_audit_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=421 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transaction_auto_approvals`
--

DROP TABLE IF EXISTS `transaction_auto_approvals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction_auto_approvals` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `transaction_id` bigint(20) NOT NULL,
  `state` enum('pending','checked','confirmed') NOT NULL DEFAULT 'pending',
  `state_change_user_id` int(11) DEFAULT NULL,
  `state_change_at` datetime DEFAULT NULL,
  `state_change_transaction_updated_at` datetime DEFAULT NULL,
  `confirmed_by_user_id` int(11) DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `confirmed_transaction_updated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_transaction_auto_approvals_transaction` (`transaction_id`),
  KEY `idx_transaction_auto_approvals_state` (`state`),
  KEY `idx_transaction_auto_approvals_confirmed` (`state`,`confirmed_at`,`confirmed_transaction_updated_at`),
  KEY `fk_transaction_auto_approvals_state_user` (`state_change_user_id`),
  KEY `fk_transaction_auto_approvals_confirmed_user` (`confirmed_by_user_id`),
  CONSTRAINT `fk_transaction_auto_approvals_confirmed_user` FOREIGN KEY (`confirmed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transaction_auto_approvals_state_user` FOREIGN KEY (`state_change_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transaction_auto_approvals_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `statement_upload_id` int(11) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `txn_date` date NOT NULL,
  `txn_type` varchar(100) DEFAULT NULL,
  `description` text NOT NULL,
  `reference` varchar(500) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` varchar(10) DEFAULT NULL,
  `source_type` varchar(50) NOT NULL DEFAULT 'statement_csv',
  `source_account_label` varchar(255) DEFAULT NULL,
  `source_created_at` datetime DEFAULT NULL,
  `source_processed_at` datetime DEFAULT NULL,
  `source_category` varchar(255) DEFAULT NULL,
  `source_document_url` varchar(2000) DEFAULT NULL,
  `local_document_path` varchar(1000) DEFAULT NULL,
  `document_url_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `document_downloaded_at` datetime DEFAULT NULL,
  `document_download_status` enum('pending','success','failed','skipped') NOT NULL DEFAULT 'skipped',
  `document_error` text DEFAULT NULL,
  `balance` decimal(12,2) DEFAULT NULL,
  `counterparty_name` varchar(500) DEFAULT NULL,
  `card` varchar(100) DEFAULT NULL,
  `dedupe_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nominal_account_id` int(11) DEFAULT NULL,
  `director_id` bigint(20) DEFAULT NULL,
  `party_id` bigint(20) DEFAULT NULL,
  `transfer_account_id` int(11) DEFAULT NULL,
  `is_internal_transfer` tinyint(1) NOT NULL DEFAULT 0,
  `category_status` enum('uncategorised','auto','manual') NOT NULL DEFAULT 'uncategorised',
  `auto_rule_id` int(11) DEFAULT NULL,
  `is_auto_excluded` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_company_dedupe` (`company_id`,`dedupe_hash`),
  KEY `idx_transactions_accounting_period_date` (`accounting_period_id`,`txn_date`),
  KEY `idx_transactions_upload` (`statement_upload_id`),
  KEY `idx_transactions_nominal` (`nominal_account_id`),
  KEY `idx_transactions_director` (`director_id`),
  KEY `idx_transactions_party` (`party_id`),
  KEY `idx_transactions_category_status` (`category_status`),
  KEY `idx_transactions_company_month` (`company_id`,`txn_date`),
  CONSTRAINT `fk_transactions_director` FOREIGN KEY (`director_id`) REFERENCES `company_directors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transactions_party` FOREIGN KEY (`party_id`) REFERENCES `company_parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_transactions_company_period_date` (`company_id`,`accounting_period_id`,`txn_date`),
  KEY `idx_transactions_company_period_category_status` (`company_id`,`accounting_period_id`,`category_status`),
  KEY `idx_transactions_company_currency` (`company_id`,`accounting_period_id`,`currency`),
  KEY `idx_transactions_company_document_hash` (`company_id`,`document_url_hash`),
  KEY `idx_transactions_document_status` (`document_download_status`),
  KEY `idx_transactions_account` (`account_id`),
  KEY `idx_transactions_auto_rule` (`auto_rule_id`),
  KEY `idx_transactions_auto_excluded` (`company_id`,`accounting_period_id`,`is_auto_excluded`,`category_status`),
  KEY `idx_transactions_transfer_account` (`transfer_account_id`),
  KEY `idx_transactions_internal_transfer` (`company_id`,`accounting_period_id`,`is_internal_transfer`),
  CONSTRAINT `fk_transactions_account` FOREIGN KEY (`account_id`) REFERENCES `company_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transactions_auto_rule` FOREIGN KEY (`auto_rule_id`) REFERENCES `categorisation_rules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transactions_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_transactions_nominal` FOREIGN KEY (`nominal_account_id`) REFERENCES `nominal_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transactions_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_transactions_transfer_account` FOREIGN KEY (`transfer_account_id`) REFERENCES `company_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transactions_upload` FOREIGN KEY (`statement_upload_id`) REFERENCES `statement_uploads` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
CONSTRAINT `chk_transactions_amount_nonzero` CHECK (`amount` <> 0)
) ENGINE=InnoDB AUTO_INCREMENT=4099 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `hmrc_obligation_evidence_links`
--

DROP TABLE IF EXISTS `hmrc_obligation_evidence_links`;
CREATE TABLE `hmrc_obligation_evidence_links` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `hmrc_obligation_id` int(11) NOT NULL,
  `transaction_id` bigint(20) DEFAULT NULL,
  `expense_claim_line_id` bigint(20) DEFAULT NULL,
  `allocated_amount` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hmrc_evidence_obligation_transaction` (`hmrc_obligation_id`,`transaction_id`),
  UNIQUE KEY `uq_hmrc_evidence_obligation_expense` (`hmrc_obligation_id`,`expense_claim_line_id`),
  KEY `idx_hmrc_evidence_transaction` (`transaction_id`),
  KEY `idx_hmrc_evidence_expense` (`expense_claim_line_id`),
  CONSTRAINT `chk_hmrc_evidence_one_source` CHECK ((`transaction_id` is not null and `expense_claim_line_id` is null) or (`transaction_id` is null and `expense_claim_line_id` is not null)),
  CONSTRAINT `chk_hmrc_evidence_positive_amount` CHECK (`allocated_amount` > 0),
  CONSTRAINT `fk_hmrc_evidence_obligation` FOREIGN KEY (`hmrc_obligation_id`) REFERENCES `hmrc_obligations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_hmrc_evidence_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_hmrc_evidence_expense` FOREIGN KEY (`expense_claim_line_id`) REFERENCES `expense_claim_lines` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transaction_inter_ac_marker`
--

DROP TABLE IF EXISTS `transaction_inter_ac_marker`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction_inter_ac_marker` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `transaction_id` bigint(20) NOT NULL,
  `matched_transaction_id` bigint(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(100) NOT NULL DEFAULT 'web_app',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transaction_inter_ac_source` (`transaction_id`),
  UNIQUE KEY `uq_transaction_inter_ac_matched` (`matched_transaction_id`),
  KEY `idx_transaction_inter_ac_company_period` (`company_id`,`accounting_period_id`),
  KEY `idx_transaction_inter_ac_matched` (`matched_transaction_id`),
  CONSTRAINT `fk_transaction_inter_ac_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_transaction_inter_ac_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_transaction_inter_ac_source` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_transaction_inter_ac_matched` FOREIGN KEY (`matched_transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transaction_splits`
--

DROP TABLE IF EXISTS `transaction_splits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction_splits` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `transaction_id` bigint(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transaction_splits_transaction` (`transaction_id`),
  CONSTRAINT `fk_transaction_splits_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transaction_split_lines`
--

DROP TABLE IF EXISTS `transaction_split_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction_split_lines` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `split_id` bigint(20) NOT NULL,
  `line_number` int(11) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `nominal_account_id` int(11) DEFAULT NULL,
  `director_id` bigint(20) DEFAULT NULL,
  `is_deferred` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transaction_split_lines_number` (`split_id`,`line_number`),
  KEY `idx_transaction_split_lines_nominal` (`nominal_account_id`),
  KEY `idx_transaction_split_lines_director` (`director_id`),
  CONSTRAINT `fk_transaction_split_lines_director` FOREIGN KEY (`director_id`) REFERENCES `company_directors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transaction_split_lines_split` FOREIGN KEY (`split_id`) REFERENCES `transaction_splits` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_transaction_split_lines_nominal` FOREIGN KEY (`nominal_account_id`) REFERENCES `nominal_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_transaction_split_lines_amount` CHECK (`amount` IS NULL OR `amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `company_incorporation_share_payment_matches`
--

DROP TABLE IF EXISTS `company_incorporation_share_payment_matches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_incorporation_share_payment_matches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `share_class_id` int(11) NOT NULL,
  `transaction_id` bigint(20) NOT NULL,
  `matched_amount` decimal(12,2) NOT NULL,
  `match_status` enum('current','cleared') NOT NULL DEFAULT 'current',
  `matched_at` datetime NOT NULL DEFAULT current_timestamp(),
  `matched_by` varchar(100) NOT NULL DEFAULT 'web_app',
  `cleared_at` datetime DEFAULT NULL,
  `cleared_by` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_share_payment_company` (`company_id`),
  KEY `idx_share_payment_share_class` (`share_class_id`),
  KEY `idx_share_payment_transaction` (`transaction_id`),
  KEY `idx_share_payment_status` (`share_class_id`,`match_status`),
  CONSTRAINT `fk_share_payment_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_share_payment_share_class` FOREIGN KEY (`share_class_id`) REFERENCES `company_incorporation_share_classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_share_payment_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_share_payment_amount_positive` CHECK (`matched_amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_register`
--

DROP TABLE IF EXISTS `asset_register`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_register` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `asset_code` varchar(64) NOT NULL,
  `description` varchar(255) NOT NULL,
  `category` varchar(64) NOT NULL,
  `nominal_account_id` int(11) NOT NULL,
  `accum_dep_nominal_id` int(11) NOT NULL,
  `purchase_date` date NOT NULL,
  `cost` decimal(12,2) NOT NULL,
  `useful_life_years` int(11) NOT NULL DEFAULT 3,
  `depreciation_method` varchar(32) NOT NULL DEFAULT 'straight_line',
  `residual_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `linked_journal_id` bigint(20) DEFAULT NULL,
  `linked_transaction_id` bigint(20) DEFAULT NULL,
  `linked_expense_claim_line_id` bigint(20) DEFAULT NULL,
  `linked_transaction_split_line_id` bigint(20) DEFAULT NULL,
  `manual_addition_reason` varchar(64) DEFAULT NULL,
  `manual_offset_nominal_id` int(11) DEFAULT NULL,
  `manual_evidence_path` varchar(512) DEFAULT NULL,
  `manual_evidence_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `manual_evidence_original_filename` varchar(255) DEFAULT NULL,
  `manual_evidence_content_type` varchar(128) DEFAULT NULL,
  `manual_evidence_size_bytes` int(11) DEFAULT NULL,
  `manual_legal_warning_version` varchar(128) DEFAULT NULL,
  `manual_legal_acknowledged_at` datetime DEFAULT NULL,
  `disposal_date` date DEFAULT NULL,
  `disposal_proceeds` decimal(12,2) DEFAULT NULL,
  `disposal_event_type` varchar(64) DEFAULT NULL,
  `disposal_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asset_register_company_code` (`company_id`,`asset_code`),
  KEY `idx_asset_register_company_status` (`company_id`,`status`,`purchase_date`),
  KEY `idx_asset_register_nominal` (`nominal_account_id`),
  KEY `idx_asset_register_accum_dep_nominal` (`accum_dep_nominal_id`),
  KEY `idx_asset_register_linked_journal` (`linked_journal_id`),
  KEY `idx_asset_register_linked_transaction` (`linked_transaction_id`),
  KEY `idx_asset_register_expense_claim_line` (`linked_expense_claim_line_id`),
  KEY `idx_asset_register_transaction_split_line` (`linked_transaction_split_line_id`),
  KEY `idx_asset_register_manual_reconcile` (`company_id`,`manual_addition_reason`,`linked_transaction_id`),
  KEY `idx_asset_register_manual_offset_nominal` (`manual_offset_nominal_id`),
  KEY `idx_asset_register_manual_evidence_sha` (`company_id`,`manual_evidence_sha256`),
  CONSTRAINT `fk_asset_register_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_asset_register_nominal` FOREIGN KEY (`nominal_account_id`) REFERENCES `nominal_accounts` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_asset_register_accum_dep_nominal` FOREIGN KEY (`accum_dep_nominal_id`) REFERENCES `nominal_accounts` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_asset_register_linked_journal` FOREIGN KEY (`linked_journal_id`) REFERENCES `journals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_asset_register_linked_transaction` FOREIGN KEY (`linked_transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_asset_register_expense_claim_line` FOREIGN KEY (`linked_expense_claim_line_id`) REFERENCES `expense_claim_lines` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_asset_register_transaction_split_line` FOREIGN KEY (`linked_transaction_split_line_id`) REFERENCES `transaction_split_lines` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_asset_register_manual_offset_nominal` FOREIGN KEY (`manual_offset_nominal_id`) REFERENCES `nominal_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_asset_register_cost` CHECK (`cost` > 0),
  CONSTRAINT `chk_asset_register_useful_life` CHECK (`useful_life_years` > 0),
  CONSTRAINT `chk_asset_register_residual` CHECK (`residual_value` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_vehicle_details`
--

DROP TABLE IF EXISTS `asset_vehicle_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_vehicle_details` (
  `asset_id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `vehicle_type` varchar(32) NOT NULL DEFAULT 'unreviewed',
  `registration_mark` varchar(32) DEFAULT NULL,
  `make_model` varchar(255) DEFAULT NULL,
  `colour` varchar(64) DEFAULT NULL,
  `engine_capacity_cc` int(11) DEFAULT NULL,
  `first_registered_date` date DEFAULT NULL,
  `acquisition_condition` varchar(32) DEFAULT NULL,
  `is_zero_emission` tinyint(1) NOT NULL DEFAULT 0,
  `co2_emissions_g_km` int(11) DEFAULT NULL,
  `payload_kg` decimal(10,2) DEFAULT NULL,
  `contract_date` date DEFAULT NULL,
  `tax_review_status` varchar(32) NOT NULL DEFAULT 'unreviewed',
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` varchar(128) DEFAULT NULL,
  `notes` varchar(512) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`asset_id`),
  KEY `idx_asset_vehicle_company_type` (`company_id`,`vehicle_type`),
  KEY `idx_asset_vehicle_registration` (`company_id`,`registration_mark`),
  CONSTRAINT `fk_asset_vehicle_asset` FOREIGN KEY (`asset_id`) REFERENCES `asset_register` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_asset_vehicle_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `capital_allowance_pool_runs`
--

DROP TABLE IF EXISTS `capital_allowance_pool_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `capital_allowance_pool_runs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `ct_period_id` int(11) DEFAULT NULL,
  `pool_type` varchar(32) NOT NULL,
  `opening_wdv` decimal(12,2) NOT NULL DEFAULT 0.00,
  `additions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `aia_claimed` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fya_claimed` decimal(12,2) NOT NULL DEFAULT 0.00,
  `disposal_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `wda_claimed` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balancing_charge` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balancing_allowance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `closing_wdv` decimal(12,2) NOT NULL DEFAULT 0.00,
  `warnings_json` longtext DEFAULT NULL,
  `run_hash` char(64) NOT NULL,
  `computed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_capital_allowance_pool_ct_period` (`company_id`,`ct_period_id`,`pool_type`),
  KEY `idx_capital_allowance_pool_period` (`company_id`,`accounting_period_id`),
  KEY `idx_capital_allowance_pool_ct_period` (`ct_period_id`),
  CONSTRAINT `fk_capital_allowance_pool_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_capital_allowance_pool_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_capital_allowance_pool_ct_period` FOREIGN KEY (`ct_period_id`) REFERENCES `corporation_tax_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `capital_allowance_asset_calculations`
--

DROP TABLE IF EXISTS `capital_allowance_asset_calculations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `capital_allowance_asset_calculations` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `ct_period_id` int(11) DEFAULT NULL,
  `asset_id` bigint(20) NOT NULL,
  `pool_type` varchar(32) NOT NULL,
  `allowance_type` varchar(32) NOT NULL,
  `addition_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `allowance_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `disposal_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `warning` varchar(512) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_capital_allowance_asset_period` (`company_id`,`accounting_period_id`),
  KEY `idx_capital_allowance_asset_ct_period` (`ct_period_id`),
  KEY `idx_capital_allowance_asset_asset` (`asset_id`),
  CONSTRAINT `fk_capital_allowance_asset_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_capital_allowance_asset_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_capital_allowance_asset_ct_period` FOREIGN KEY (`ct_period_id`) REFERENCES `corporation_tax_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_capital_allowance_asset_asset` FOREIGN KEY (`asset_id`) REFERENCES `asset_register` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_disposal_transaction_links`
--

DROP TABLE IF EXISTS `asset_disposal_transaction_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_disposal_transaction_links` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `asset_id` bigint(20) NOT NULL,
  `transaction_id` bigint(20) NOT NULL,
  `linked_amount` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asset_disposal_transaction_links_asset` (`asset_id`),
  UNIQUE KEY `uq_asset_disposal_transaction_links_transaction` (`transaction_id`),
  CONSTRAINT `fk_asset_disposal_transaction_links_asset` FOREIGN KEY (`asset_id`) REFERENCES `asset_register` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_asset_disposal_transaction_links_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_asset_disposal_transaction_links_amount` CHECK (`linked_amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expense_claim_line_assets`
--

DROP TABLE IF EXISTS `expense_claim_line_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_claim_line_assets` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `expense_claim_line_id` bigint(20) NOT NULL,
  `category` varchar(64) NOT NULL DEFAULT 'tools_equipment',
  `description` varchar(255) DEFAULT NULL,
  `useful_life_years` int(11) NOT NULL DEFAULT 3,
  `depreciation_method` varchar(32) NOT NULL DEFAULT 'straight_line',
  `residual_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `generated_asset_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_expense_claim_line_assets_line` (`expense_claim_line_id`),
  KEY `idx_expense_claim_line_assets_asset` (`generated_asset_id`),
  CONSTRAINT `fk_expense_claim_line_assets_line` FOREIGN KEY (`expense_claim_line_id`) REFERENCES `expense_claim_lines` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_expense_claim_line_assets_asset` FOREIGN KEY (`generated_asset_id`) REFERENCES `asset_register` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_expense_claim_line_assets_life` CHECK (`useful_life_years` > 0),
  CONSTRAINT `chk_expense_claim_line_assets_residual` CHECK (`residual_value` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_depreciation_entries`
--

DROP TABLE IF EXISTS `asset_depreciation_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_depreciation_entries` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `asset_id` bigint(20) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `journal_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asset_depreciation_period` (`asset_id`,`accounting_period_id`,`period_start`,`period_end`),
  KEY `idx_asset_depreciation_asset_period_end` (`asset_id`,`period_end`),
  KEY `idx_asset_depreciation_accounting_period` (`accounting_period_id`),
  KEY `idx_asset_depreciation_journal` (`journal_id`),
  CONSTRAINT `fk_asset_depreciation_asset` FOREIGN KEY (`asset_id`) REFERENCES `asset_register` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_asset_depreciation_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_asset_depreciation_journal` FOREIGN KEY (`journal_id`) REFERENCES `journals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_asset_depreciation_period` CHECK (`period_start` <= `period_end`),
  CONSTRAINT `chk_asset_depreciation_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_account_audit`
--

DROP TABLE IF EXISTS `user_account_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_account_audit` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `affected_user_id` int(11) NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `action_type` enum('user_created','user_enabled','user_disabled','password_set_admin','password_change_required_admin','password_changed_self','email_changed','display_name_changed','mobile_number_changed','otp_requirement_changed','otp_reset_admin','login_lockout_reset_admin','otp_rotation_started','otp_rotation_completed','mfa_authenticated','role_changed','invite_created','invite_link_copied','invite_email_sent','invite_sms_sent','invite_opened','invite_verification_failed','invite_verification_succeeded','invite_completion_failed','invite_completed','invite_expired','invite_revoked','invite_locked') NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `details_json` longtext DEFAULT NULL,
  `device_id` varchar(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(1000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_account_audit_affected_time` (`affected_user_id`,`created_at`),
  KEY `idx_user_account_audit_actor_time` (`actor_user_id`,`created_at`),
  KEY `idx_user_account_audit_action_time` (`action_type`,`created_at`),
  CONSTRAINT `fk_user_account_audit_actor_user` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_user_account_audit_affected_user` FOREIGN KEY (`affected_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_login_rate_limits`
--

DROP TABLE IF EXISTS `user_login_rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_login_rate_limits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email_address` varchar(255) NOT NULL,
  `scope_type` varchar(20) NOT NULL DEFAULT 'email',
  `scope_key` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `consecutive_failed_password_attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `failed_attempt_window_started_at` datetime DEFAULT NULL,
  `last_failed_password_attempt_at` datetime DEFAULT NULL,
  `next_allowed_login_at` datetime DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `lock_reason` varchar(100) DEFAULT NULL,
  `lock_expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_login_rate_limits_scope` (`scope_type`,`scope_key`),
  KEY `idx_user_login_rate_limits_email_address` (`email_address`),
  KEY `idx_user_login_rate_limits_user_id` (`user_id`),
  KEY `idx_user_login_rate_limits_next_allowed_login_at` (`next_allowed_login_at`),
  KEY `idx_user_login_rate_limits_locked_at` (`locked_at`),
  KEY `idx_user_login_rate_limits_lock_expires_at` (`lock_expires_at`),
  CONSTRAINT `fk_user_login_rate_limits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_logon_history`
--

DROP TABLE IF EXISTS `user_logon_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_logon_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `attempted_email_address` varchar(255) DEFAULT NULL,
  `event_type` enum('login_succeeded','login_failed','logout','forced_logout','session_replaced','otp_challenge_passed','otp_challenge_failed','otp_setup_started','otp_setup_completed') NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 1,
  `reason` varchar(255) DEFAULT NULL,
  `session_token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `device_id` varchar(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(1000) DEFAULT NULL,
  `browser_label` varchar(255) DEFAULT NULL,
  `occurred_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_logon_history_user_time` (`user_id`,`occurred_at`),
  KEY `idx_user_logon_history_email_time` (`attempted_email_address`,`occurred_at`),
  KEY `idx_user_logon_history_token` (`session_token_hash`),
  KEY `idx_user_logon_history_event_time` (`event_type`,`occurred_at`),
  CONSTRAINT `fk_user_logon_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=141 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_totp`
--

DROP TABLE IF EXISTS `user_totp`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_totp` (
  `user_id` int(11) NOT NULL,
  `otp_secret` varchar(128) DEFAULT NULL,
  `pending_otp_secret` varchar(128) DEFAULT NULL,
  `pending_otp_algorithm` enum('SHA1','SHA256','SHA512') DEFAULT NULL,
  `pending_otp_digits` tinyint(2) DEFAULT NULL,
  `pending_otp_period` int(11) DEFAULT NULL,
  `pending_otp_requested_at` datetime DEFAULT NULL,
  `otp_algorithm` enum('SHA1','SHA256','SHA512') NOT NULL DEFAULT 'SHA1',
  `otp_digits` tinyint(2) NOT NULL DEFAULT 6,
  `otp_period` int(11) NOT NULL DEFAULT 30,
  `otp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `otp_confirmed_at` datetime DEFAULT NULL,
  `otp_last_used_timestep` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_totp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `display_name` varchar(255) NOT NULL,
  `email_address` varchar(255) DEFAULT NULL,
  `mobile_number` varchar(32) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `current_session_token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `current_session_started_at` datetime DEFAULT NULL,
  `current_session_last_seen_at` datetime DEFAULT NULL,
  `current_session_device_id` varchar(64) DEFAULT NULL,
  `current_session_ip_address` varchar(45) DEFAULT NULL,
  `current_session_user_agent` varchar(1000) DEFAULT NULL,
  `current_session_browser_label` varchar(255) DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `account_completed_at` datetime DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `otp_required` tinyint(1) NOT NULL DEFAULT 1,
  `confirmed_director` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `account_status` varchar(30) NOT NULL DEFAULT 'active',
  `role_id` int(11) NOT NULL DEFAULT -1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email_address` (`email_address`),
  UNIQUE KEY `uq_users_current_session_token_hash` (`current_session_token_hash`),
  KEY `idx_users_role_id` (`role_id`)
) ENGINE=InnoDB AUTO_INCREMENT=261 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mobile_country_codes`
--

DROP TABLE IF EXISTS `mobile_country_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mobile_country_codes` (
  `country_code` varchar(8) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`country_code`),
  KEY `idx_mobile_country_codes_default_sort` (`is_default`,`display_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sic_section`
--

DROP TABLE IF EXISTS `sic_section`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sic_section` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_letter` char(1) NOT NULL,
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sic_section_letter` (`section_letter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sic_codes`
--

DROP TABLE IF EXISTS `sic_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sic_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_id` int(11) NOT NULL,
  `sic_code` varchar(10) NOT NULL,
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sic_code` (`sic_code`),
  KEY `idx_sic_codes_section_id` (`section_id`),
  CONSTRAINT `fk_sic_codes_section` FOREIGN KEY (`section_id`) REFERENCES `sic_section` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_account_invites`
--

DROP TABLE IF EXISTS `user_account_invites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_account_invites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `token_value` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `purpose` varchar(50) NOT NULL DEFAULT 'account_completion',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by_user_id` int(11) DEFAULT NULL,
  `last_sent_at` datetime DEFAULT NULL,
  `send_attempts` int(11) NOT NULL DEFAULT 0,
  `failed_attempts` int(11) NOT NULL DEFAULT 0,
  `last_failed_at` datetime DEFAULT NULL,
  `next_allowed_attempt_at` datetime DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `lock_expires_at` datetime DEFAULT NULL,
  `opened_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `ip_created` varchar(45) DEFAULT NULL,
  `ip_opened` varchar(45) DEFAULT NULL,
  `ip_used` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_account_invites_token_hash` (`token_hash`),
  KEY `idx_user_account_invites_user_id` (`user_id`),
  KEY `idx_user_account_invites_status` (`status`),
  KEY `idx_user_account_invites_expires_at` (`expires_at`),
  CONSTRAINT `fk_user_account_invites_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_user_account_invites_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_user_account_invites_purpose_not_blank` CHECK (`purpose` <> ''),
  CONSTRAINT `chk_user_account_invites_status_not_blank` CHECK (`status` <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_account_invite_deliveries`
--

DROP TABLE IF EXISTS `user_account_invite_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_account_invite_deliveries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invite_id` int(11) NOT NULL,
  `contact_method` varchar(20) NOT NULL,
  `sent_to` varchar(255) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'created',
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by_user_id` int(11) DEFAULT NULL,
  `error_summary` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_account_invite_deliveries_invite_id` (`invite_id`),
  KEY `idx_user_account_invite_deliveries_contact_method` (`contact_method`),
  KEY `idx_user_account_invite_deliveries_sent_at` (`sent_at`),
  KEY `idx_user_account_invite_deliveries_created_by` (`created_by_user_id`),
  CONSTRAINT `fk_user_account_invite_deliveries_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_user_account_invite_deliveries_invite` FOREIGN KEY (`invite_id`) REFERENCES `user_account_invites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_user_account_invite_deliveries_contact_method_not_blank` CHECK (`contact_method` <> ''),
  CONSTRAINT `chk_user_account_invite_deliveries_sent_to_not_blank` CHECK (`sent_to` <> ''),
  CONSTRAINT `chk_user_account_invite_deliveries_status_not_blank` CHECK (`status` <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `signup_token_rate_limits`
--

DROP TABLE IF EXISTS `signup_token_rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `signup_token_rate_limits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_ip` varchar(45) NOT NULL,
  `failed_attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `window_started_at` datetime DEFAULT NULL,
  `last_failed_at` datetime DEFAULT NULL,
  `blocked_at` datetime DEFAULT NULL,
  `block_expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_signup_token_rate_limits_client_ip` (`client_ip`),
  KEY `idx_signup_token_rate_limits_block_expires_at` (`block_expires_at`),
  KEY `idx_signup_token_rate_limits_last_failed_at` (`last_failed_at`),
  CONSTRAINT `chk_signup_token_rate_limits_client_ip_not_blank` CHECK (`client_ip` <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `signup_verification_rate_limits`
--

DROP TABLE IF EXISTS `signup_verification_rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `signup_verification_rate_limits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `scope_type` varchar(20) NOT NULL,
  `scope_key` varchar(80) NOT NULL,
  `scope_label` varchar(80) NOT NULL,
  `failed_attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `window_started_at` datetime DEFAULT NULL,
  `last_failed_at` datetime DEFAULT NULL,
  `blocked_at` datetime DEFAULT NULL,
  `block_expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_signup_verification_rate_limits_scope` (`scope_type`,`scope_key`),
  KEY `idx_signup_verification_rate_limits_block_expires_at` (`block_expires_at`),
  KEY `idx_signup_verification_rate_limits_last_failed_at` (`last_failed_at`),
  CONSTRAINT `chk_signup_verification_rate_limits_scope_type_not_blank` CHECK (`scope_type` <> ''),
  CONSTRAINT `chk_signup_verification_rate_limits_scope_key_not_blank` CHECK (`scope_key` <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `year_end_audit_log`
--

DROP TABLE IF EXISTS `year_end_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `year_end_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `action_by` varchar(100) NOT NULL,
  `action_at` datetime NOT NULL DEFAULT current_timestamp(),
  `old_value_json` longtext DEFAULT NULL,
  `new_value_json` longtext DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_year_end_audit_log_company_period` (`company_id`,`accounting_period_id`),
  KEY `idx_year_end_audit_log_accounting_period` (`accounting_period_id`),
  KEY `idx_year_end_audit_log_action_at` (`action_at`),
  CONSTRAINT `fk_year_end_audit_log_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_year_end_audit_log_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vat_rate_rules`
--

DROP TABLE IF EXISTS `vat_rate_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vat_rate_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rate_type` varchar(16) NOT NULL,
  `scope` varchar(32) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `rate_percentage` decimal(7,3) NOT NULL,
  `original_period_text` varchar(255) NOT NULL,
  `source_url` varchar(500) NOT NULL,
  `source_content_id` varchar(64) NOT NULL,
  `source_updated_at` datetime DEFAULT NULL,
  `source_checked_at` datetime NOT NULL,
  `rule_version` varchar(64) NOT NULL,
  `dataset_hash` char(64) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vat_rate_rule_dataset` (`dataset_hash`,`rate_type`,`scope`,`effective_from`),
  KEY `idx_vat_rate_rules_lookup` (`rate_type`,`scope`,`is_active`,`effective_from`,`effective_to`),
  KEY `idx_vat_rate_rules_dataset` (`dataset_hash`,`is_active`),
  CONSTRAINT `chk_vat_rate_rule_dates` CHECK (`effective_to` is null or `effective_from` <= `effective_to`),
  CONSTRAINT `chk_vat_rate_percentage` CHECK (`rate_percentage` >= 0 and `rate_percentage` <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vat_threshold_rules`
--

DROP TABLE IF EXISTS `vat_threshold_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vat_threshold_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `threshold_type` varchar(32) NOT NULL,
  `jurisdiction` varchar(32) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `original_period_text` varchar(255) NOT NULL,
  `registration_threshold` decimal(14,2) DEFAULT NULL,
  `deregistration_threshold` decimal(14,2) DEFAULT NULL,
  `source_url` varchar(500) NOT NULL,
  `source_content_id` char(36) NOT NULL,
  `source_updated_at` datetime DEFAULT NULL,
  `source_checked_at` datetime NOT NULL,
  `dataset_hash` char(64) NOT NULL,
  `row_hash` char(64) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `audit_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vat_threshold_rule_dataset_row` (`dataset_hash`,`row_hash`),
  KEY `idx_vat_threshold_rules_lookup` (`threshold_type`,`jurisdiction`,`is_active`,`effective_from`,`effective_to`),
  KEY `idx_vat_threshold_rules_dataset` (`dataset_hash`,`is_active`),
  CONSTRAINT `chk_vat_threshold_rule_dates` CHECK (`effective_to` is null or `effective_from` <= `effective_to`),
  CONSTRAINT `chk_vat_threshold_registration_amount` CHECK (`registration_threshold` is null or `registration_threshold` > 0),
  CONSTRAINT `chk_vat_threshold_deregistration_amount` CHECK (`deregistration_threshold` is null or `deregistration_threshold` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `year_end_reviews`
--

DROP TABLE IF EXISTS `year_end_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `year_end_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` varchar(100) DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_year_end_reviews_company_period` (`company_id`,`accounting_period_id`),
  KEY `idx_year_end_reviews_company` (`company_id`),
  KEY `idx_year_end_reviews_accounting_period` (`accounting_period_id`),
  CONSTRAINT `fk_year_end_reviews_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_year_end_reviews_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `ct_period_filing_bases`;
DROP TABLE IF EXISTS `ixbrl_accounts_filing_approvals`;
CREATE TABLE `ixbrl_accounts_filing_approvals` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `evidence_bundle_id` bigint(20) DEFAULT NULL,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `disclosure_id` bigint(20) NOT NULL,
  `disclosure_revision` int(10) unsigned NOT NULL,
  `year_end_review_id` int(11) NOT NULL,
  `year_end_locked_at` datetime NOT NULL,
  `basis_version` varchar(64) NOT NULL,
  `basis_hash` char(64) NOT NULL,
  `basis_json` longtext NOT NULL,
  `approved_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_by` varchar(100) NOT NULL,
  `approval_note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ixbrl_filing_approval_period` (`company_id`,`accounting_period_id`,`id`),
  KEY `idx_ixbrl_filing_approval_basis` (`basis_hash`),
  CONSTRAINT `fk_ixbrl_filing_approval_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ixbrl_filing_approval_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ixbrl_filing_approval_disclosure` FOREIGN KEY (`disclosure_id`) REFERENCES `ixbrl_accounts_disclosures` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_ixbrl_filing_approval_year_end` FOREIGN KEY (`year_end_review_id`) REFERENCES `year_end_reviews` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `ct_period_filing_bases` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `filing_approval_id` bigint(20) NOT NULL,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `ct_period_id` int(11) NOT NULL,
  `computation_run_id` int(11) NOT NULL,
  `calculation_basis_version` varchar(64) NOT NULL,
  `calculation_basis_hash` char(64) NOT NULL,
  `basis_version` varchar(100) NOT NULL,
  `basis_hash` char(64) NOT NULL,
  `basis_json` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ct_period_filing_basis_approval_period` (`filing_approval_id`,`ct_period_id`),
  KEY `idx_ct_period_filing_basis_context` (`company_id`,`accounting_period_id`,`ct_period_id`,`id`),
  KEY `idx_ct_period_filing_basis_hash` (`basis_hash`),
  CONSTRAINT `fk_ct_period_filing_basis_approval` FOREIGN KEY (`filing_approval_id`) REFERENCES `ixbrl_accounts_filing_approvals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_period_filing_basis_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_period_filing_basis_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_period_filing_basis_ct_period` FOREIGN KEY (`ct_period_id`) REFERENCES `corporation_tax_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ct_period_filing_basis_run` FOREIGN KEY (`computation_run_id`) REFERENCES `corporation_tax_computation_runs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `ixbrl_generation_runs`
  ADD CONSTRAINT `fk_ixbrl_runs_filing_approval` FOREIGN KEY (`filing_approval_id`) REFERENCES `ixbrl_accounts_filing_approvals` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Table structure for table `participator_loan_attribution_audit`
--

DROP TABLE IF EXISTS `participator_loan_attribution_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `participator_loan_attribution_audit` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `source_type` varchar(64) NOT NULL,
  `source_id` bigint(20) NOT NULL,
  `old_party_id` bigint(20) DEFAULT NULL,
  `new_party_id` bigint(20) DEFAULT NULL,
  `changed_by` varchar(100) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pla_attribution_audit_source` (`source_type`,`source_id`,`changed_at`),
  KEY `idx_pla_attribution_audit_company` (`company_id`,`changed_at`),
  KEY `idx_pla_attribution_audit_old_party` (`old_party_id`),
  KEY `idx_pla_attribution_audit_new_party` (`new_party_id`),
  CONSTRAINT `fk_pla_attribution_audit_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pla_attribution_audit_old_party` FOREIGN KEY (`old_party_id`) REFERENCES `company_parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pla_attribution_audit_new_party` FOREIGN KEY (`new_party_id`) REFERENCES `company_parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `director_loan_reporting_presentations`
--

DROP TABLE IF EXISTS `director_loan_reporting_presentations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `director_loan_reporting_presentations` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `liability_nominal_account_id` int(11) NOT NULL,
  `classification` varchar(40) NOT NULL,
  `revision` int(10) unsigned NOT NULL DEFAULT 1,
  `created_by` varchar(100) NOT NULL,
  `updated_by` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_dla_reporting_presentation_company_period` (`company_id`,`accounting_period_id`),
  KEY `idx_dla_reporting_presentation_nominal` (`liability_nominal_account_id`),
  CONSTRAINT `chk_dla_reporting_presentation_classification` CHECK (`classification` in ('within_one_year','after_more_than_one_year')),
  CONSTRAINT `fk_dla_reporting_presentation_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dla_reporting_presentation_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dla_reporting_presentation_nominal` FOREIGN KEY (`liability_nominal_account_id`) REFERENCES `nominal_accounts` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `director_loan_reporting_presentation_audit`
--

DROP TABLE IF EXISTS `director_loan_reporting_presentation_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `director_loan_reporting_presentation_audit` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `old_liability_nominal_account_id` int(11) DEFAULT NULL,
  `new_liability_nominal_account_id` int(11) DEFAULT NULL,
  `old_classification` varchar(40) NOT NULL,
  `new_classification` varchar(40) NOT NULL,
  `old_revision` int(10) unsigned NOT NULL,
  `new_revision` int(10) unsigned NOT NULL,
  `changed_by` varchar(100) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dla_reporting_presentation_audit_scope` (`company_id`,`accounting_period_id`,`changed_at`),
  KEY `idx_dla_reporting_presentation_audit_old_nominal` (`old_liability_nominal_account_id`),
  KEY `idx_dla_reporting_presentation_audit_new_nominal` (`new_liability_nominal_account_id`),
  CONSTRAINT `chk_dla_reporting_presentation_audit_old_classification` CHECK (`old_classification` in ('within_one_year','after_more_than_one_year')),
  CONSTRAINT `chk_dla_reporting_presentation_audit_new_classification` CHECK (`new_classification` in ('within_one_year','after_more_than_one_year')),
  CONSTRAINT `fk_dla_reporting_presentation_audit_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dla_reporting_presentation_audit_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dla_reporting_presentation_audit_old_nominal` FOREIGN KEY (`old_liability_nominal_account_id`) REFERENCES `nominal_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_dla_reporting_presentation_audit_new_nominal` FOREIGN KEY (`new_liability_nominal_account_id`) REFERENCES `nominal_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `year_end_review_acknowledgements`
--

DROP TABLE IF EXISTS `year_end_review_acknowledgements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `year_end_review_acknowledgements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `check_code` varchar(100) NOT NULL,
  `acknowledged_at` datetime NOT NULL,
  `acknowledged_by` varchar(100) NOT NULL,
  `note` text DEFAULT NULL,
  `basis_version` varchar(50) DEFAULT NULL,
  `basis_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `basis_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_year_end_review_ack_company_period_check` (`company_id`,`accounting_period_id`,`check_code`),
  KEY `idx_year_end_review_ack_company_period` (`company_id`,`accounting_period_id`),
  KEY `idx_year_end_review_ack_accounting_period` (`accounting_period_id`),
  KEY `idx_year_end_review_ack_check_code` (`check_code`),
  CONSTRAINT `fk_year_end_review_ack_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_year_end_review_ack_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `schema_migrations`
--

DROP TABLE IF EXISTS `hmrc_ct_rim_packages`;
CREATE TABLE `hmrc_ct_rim_packages` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `form_version` varchar(16) NOT NULL,
  `artifact_version` varchar(64) NOT NULL,
  `applicable_from` date DEFAULT NULL,
  `applicable_to` date DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `live_from` datetime DEFAULT NULL,
  `live_to` datetime DEFAULT NULL,
  `hmrc_status` varchar(64) NOT NULL DEFAULT 'unknown',
  `source_url` varchar(500) NOT NULL,
  `download_url` varchar(1000) DEFAULT NULL,
  `local_path` varchar(1000) DEFAULT NULL,
  `sha256` char(64) DEFAULT NULL,
  `source_updated_at` datetime DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL,
  `latest_change_note` text DEFAULT NULL,
  `package_state` varchar(32) NOT NULL DEFAULT 'not_downloaded',
  `xsd_count` int(11) NOT NULL DEFAULT 0,
  `applicability_source_file_id` bigint(20) DEFAULT NULL,
  `applicability_xpath` varchar(500) DEFAULT NULL,
  `applicability_extracted_at` datetime DEFAULT NULL,
  `applicability_status` varchar(32) NOT NULL DEFAULT 'pending',
  `verification_error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hmrc_ct_rim_package` (`form_version`,`artifact_version`),
  KEY `idx_hmrc_ct_rim_applicability` (`form_version`,`applicable_from`,`applicable_to`),
  KEY `idx_hmrc_ct_rim_live` (`form_version`,`live_from`,`live_to`,`hmrc_status`),
  CONSTRAINT `chk_hmrc_ct_rim_dates` CHECK (`applicable_to` IS NULL OR `applicable_from` IS NULL OR `applicable_from` <= `applicable_to`),
  CONSTRAINT `chk_hmrc_ct_rim_applicability_status` CHECK (`applicability_status` in ('pending','confirmed','open_start','ambiguous','failed')),
  CONSTRAINT `chk_hmrc_ct_rim_state` CHECK (`package_state` in ('not_downloaded','downloaded','verified','stale','failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `hmrc_ct_rim_files`;
CREATE TABLE `hmrc_ct_rim_files` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `package_id` bigint(20) NOT NULL,
  `archive_path` varchar(1000) NOT NULL,
  `extracted_path` varchar(1000) NOT NULL,
  `file_type` varchar(16) NOT NULL,
  `file_size` bigint(20) NOT NULL DEFAULT 0,
  `sha256` char(64) DEFAULT NULL,
  `file_role` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hmrc_ct_rim_file` (`package_id`,`archive_path`),
  KEY `idx_hmrc_ct_rim_file_package` (`package_id`),
  KEY `idx_hmrc_ct_rim_file_role` (`package_id`,`file_role`)
  ,CONSTRAINT `chk_hmrc_ct_rim_file_type` CHECK (`file_type` in ('xsd','sch','xslt'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `hmrc_ct_rim_packages` (`form_version`,`artifact_version`,`applicable_from`,`applicable_to`,`live_from`,`hmrc_status`,`source_url`) VALUES
('V2','V3.99',NULL,NULL,'2015-07-22 00:00:00','live','https://www.gov.uk/government/publications/corporation-tax-technical-specifications-ct600-rim-artefacts'),
('V3','V1.994',NULL,NULL,'2026-04-07 08:23:02','live','https://www.gov.uk/government/publications/corporation-tax-technical-specifications-ct600-rim-artefacts');

INSERT IGNORE INTO `role_card_permissions` (`role_id`, `card_key`)
SELECT DISTINCT `role_id`, 'ixbrl_accounts_disclosures'
FROM `role_card_permissions`
WHERE `card_key` IN ('ixbrl_readiness', 'ixbrl_facts_preview');

DROP TABLE IF EXISTS `schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `schema_migrations` (
  `migration` varchar(255) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

INSERT INTO `schema_migrations` (`migration`) VALUES
  ('2026_05_07_001_initial_schema.sql'),
  ('2026_05_08_001_schema_integrity.sql'),
  ('2026_05_08_002_force_password_change.sql'),
  ('2026_05_08_003_user_otp_optional.sql'),
  ('2026_05_09_001_login_lockout_reset_audit.sql'),
  ('2026_05_14_001_application_activity_flash_history.sql'),
  ('2026_05_25_001_company_account_nominals.sql'),
  ('2026_05_26_001_corporation_tax_rate_rules.sql'),
  ('2026_05_26_002_drop_legacy_tax_loss_pools.sql'),
  ('2026_05_26_003_fixed_asset_register.sql'),
  ('2026_05_26_004_corporation_tax_treatment_rules.sql'),
  ('2026_05_26_005_accounting_period_ct_periods.sql'),
  ('2026_05_27_001_trade_creditors_default_nominal.sql'),
  ('2026_05_27_002_repair_default_trade_nominal.sql'),
  ('2026_06_01_001_user_mobile_number.sql'),
  ('2026_06_01_002_mobile_country_codes.sql'),
  ('2026_06_01_003_invited_account_completion.sql'),
  ('2026_06_15_001_invite_deliveries.sql'),
  ('2026_06_15_002_signup_token_rate_limits.sql'),
  ('2026_06_15_003_signup_verification_rate_limits.sql'),
  ('2026_06_29_001_ixbrl_export_metadata.sql'),
  ('2026_06_29_002_ixbrl_external_validation.sql'),
  ('2026_06_29_003_nominal_account_origin.sql'),
  ('2026_06_29_004_database_integrity_alignment.sql'),
  ('2026_06_29_005_remove_deferred_tax_ct_rule.sql'),
  ('2026_06_30_001_ensure_expense_claim_tables.sql'),
  ('2026_06_30_001_warning_flash_messages.sql'),
  ('2026_06_30_002_ordinary_share_capital_default_nominal.sql'),
  ('2026_06_30_003_expense_payable_nominal_subtype.sql'),
  ('2026_06_30_004_fixed_asset_pl_nominal_subtypes.sql'),
  ('2026_06_30_005_dividend_default_nominals.sql'),
  ('2026_06_30_006_reference_aware_auto_rules.sql'),
  ('2026_06_30_007_preserve_regex_auto_rule_matches.sql'),
  ('2026_07_01_001_expense_add_claimant_card_permission.sql'),
  ('2026_07_01_002_expense_claim_create_card_permission.sql'),
  ('2026_07_01_003_expense_claim_line_assets.sql'),
  ('2026_07_01_004_expense_claim_series_index.sql'),
  ('2026_07_01_005_asset_disposal_transaction_links.sql'),
  ('2026_07_01_007_manual_asset_reconciliation.sql'),
  ('2026_07_02_001_empty_month_confirmations.sql'),
  ('2026_07_02_002_expense_statistics_card_permission.sql'),
  ('2026_07_02_003_expense_asset_line_nominals.sql'),
  ('2026_07_02_004_expense_search_card_permission.sql'),
  ('2026_07_02_005_manual_asset_evidence.sql'),
  ('2026_07_03_005_dividend_vouchers.sql'),
  ('2026_07_03_006_company_minutes_card_permission.sql'),
  ('2026_07_03_007_non_assets_card_permission.sql'),
  ('2026_07_04_001_incorporation_share_capital.sql'),
  ('2026_07_04_002_dividend_reserve_classification.sql'),
  ('2026_07_04_003_dividend_reserve_snapshot_roll_forward.sql'),
  ('2026_07_04_004_asset_disposal_metadata.sql'),
  ('2026_07_04_005_vehicle_register_capital_allowances.sql'),
  ('2026_07_04_006_read_only_tax_workings_permissions.sql'),
  ('2026_07_05_001_ct_period_tax_page_provisions.sql'),
  ('2026_07_05_002_dynamic_tax_append_only_close.sql'),
  ('2026_07_06_001_prepayments_cutoff_workflows.sql'),
  ('2026_07_06_002_remove_pending_prepayment_status.sql'),
  ('2026_07_07_001_transaction_splits.sql'),
  ('2026_07_07_002_transaction_inter_ac_marker.sql'),
  ('2026_07_07_003_sourced_tax_rate_rules.sql'),
  ('2026_07_13_001_live_year_end_acknowledgements.sql'),
  ('2026_07_14_001_normalise_tax_rate_rule_labels.sql'),
  ('2026_07_14_002_prepayment_schedules.sql'),
  ('2026_07_14_003_vat_monitoring_support_scope.sql'),
  ('2026_07_14_004_tax_prepayment_card_permission.sql'),
  ('2026_07_14_005_vat_reference_rates_and_tax_cards.sql'),
  ('2026_07_14_006_hmrc_obligation_evidence.sql'),
  ('2026_07_15_001_prepayment_schedule_repair.sql'),
  ('2026_07_16_001_director_loan_subledger.sql'),
  ('2026_07_16_002_director_loan_reporting_presentation.sql'),
  ('2026_07_16_004_ixbrl_accounts_disclosures.sql'),
  ('2026_07_16_005_ixbrl_taxonomy_facts.sql'),
  ('2026_07_17_001_ixbrl_sales_nominal.sql'),
  ('2026_07_17_002_frs105_thresholds.sql'),
  ('2026_07_17_004_hmrc_ct600_govtalk.sql'),
  ('2026_07_18_002_hmrc_ct_rim_catalogue.sql');
INSERT IGNORE INTO `schema_migrations` (`migration`) VALUES
  ('2026_07_18_004_hmrc_ct_rim_files.sql');
INSERT IGNORE INTO `role_card_permissions` (`role_id`, `card_key`)
SELECT DISTINCT existing_permission.`role_id`, audit_card.`card_key`
FROM `role_card_permissions` existing_permission
INNER JOIN (
  SELECT 'tax_audit_areas' AS `card_key`
  UNION ALL SELECT 'tax_audit_detail'
) audit_card
WHERE existing_permission.`card_key` IN ('tax_corporation_tax_summary','tax_taxable_profit_bridge','year_end_tax_readiness');
INSERT IGNORE INTO `schema_migrations` (`migration`) VALUES
  ('2026_07_19_001_corporation_tax_audit_snapshots.sql');
INSERT IGNORE INTO `schema_migrations` (`migration`) VALUES
  ('2026_07_19_002_ct_period_participator_controls.sql');
INSERT IGNORE INTO `schema_migrations` (`migration`) VALUES
  ('2026_07_19_005_accounts_filing_approvals.sql');
INSERT IGNORE INTO `schema_migrations` (`migration`) VALUES
  ('2026_07_21_001_companies_house_accounts_schemas.sql');
INSERT IGNORE INTO `schema_migrations` (`migration`) VALUES
  ('2026_07_22_003_dividend_voucher_shareholder_links.sql');
INSERT IGNORE INTO `role_card_permissions` (`role_id`, `card_key`)
SELECT DISTINCT `role_id`, 'tax_companies_house_accounts_schemas'
FROM `role_card_permissions`
WHERE `card_key` = 'tax_rates_ct600_rim';
INSERT IGNORE INTO `role_card_permissions` (`role_id`, `card_key`)
SELECT DISTINCT existing_permission.`role_id`, new_card.`card_key`
FROM `role_card_permissions` existing_permission
INNER JOIN (
  SELECT 'incorporation_ownership_parties' AS `card_key`
  UNION ALL SELECT 'incorporation_share_allocation'
  UNION ALL SELECT 'incorporation_relationships'
  UNION ALL SELECT 'tax_ct_period_facts'
  UNION ALL SELECT 'director_loan_s455'
) new_card
WHERE existing_permission.`card_key` IN ('incorporation_share_capital','tax_rate_bands','director_loan_state');
DROP TABLE IF EXISTS `filing_evidence_events`;
DROP TABLE IF EXISTS `filing_evidence_artifacts`;
DROP TABLE IF EXISTS `filing_evidence_ct_snapshots`;
DROP TABLE IF EXISTS `filing_evidence_bundles`;
CREATE TABLE `filing_evidence_bundles` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `evidence_id` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `company_id` int(11) NOT NULL,
  `accounting_period_id` int(11) NOT NULL,
  `year_end_review_id` int(11) DEFAULT NULL,
  `predecessor_bundle_id` bigint(20) DEFAULT NULL,
  `lifecycle_status` enum('current','reopened','invalidated','superseded') NOT NULL DEFAULT 'current',
  `evidence_version` varchar(64) NOT NULL,
  `application_name` varchar(100) NOT NULL,
  `application_version` varchar(100) NOT NULL,
  `calculation_build` varchar(100) NOT NULL,
  `locked_at` datetime NOT NULL,
  `locked_by` varchar(100) NOT NULL,
  `bundle_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `legacy_backfill` tinyint(1) NOT NULL DEFAULT 0,
  `reopened_at` datetime DEFAULT NULL,
  `superseded_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_filing_evidence_id` (`evidence_id`),
  KEY `idx_filing_evidence_lock` (`company_id`,`accounting_period_id`,`locked_at`),
  KEY `idx_filing_evidence_period` (`company_id`,`accounting_period_id`,`id`),
  KEY `idx_filing_evidence_predecessor` (`predecessor_bundle_id`),
  CONSTRAINT `fk_filing_evidence_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_filing_evidence_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_filing_evidence_year_end` FOREIGN KEY (`year_end_review_id`) REFERENCES `year_end_reviews` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_filing_evidence_predecessor` FOREIGN KEY (`predecessor_bundle_id`) REFERENCES `filing_evidence_bundles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `filing_evidence_ct_snapshots` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `bundle_id` bigint(20) NOT NULL,
  `ct_period_id` int(11) NOT NULL,
  `computation_run_id` int(11) NOT NULL,
  `tax_audit_snapshot_id` bigint(20) NOT NULL,
  `calculation_basis_version` varchar(64) NOT NULL,
  `calculation_basis_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `trace_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_filing_evidence_snapshot` (`bundle_id`,`ct_period_id`),
  KEY `idx_filing_evidence_snapshot_lookup` (`tax_audit_snapshot_id`),
  CONSTRAINT `fk_filing_evidence_snapshot_bundle` FOREIGN KEY (`bundle_id`) REFERENCES `filing_evidence_bundles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_filing_evidence_snapshot_period` FOREIGN KEY (`ct_period_id`) REFERENCES `corporation_tax_periods` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_filing_evidence_snapshot_run` FOREIGN KEY (`computation_run_id`) REFERENCES `corporation_tax_computation_runs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_filing_evidence_snapshot_audit` FOREIGN KEY (`tax_audit_snapshot_id`) REFERENCES `corporation_tax_audit_snapshots` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `filing_evidence_artifacts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `artifact_id` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `transaction_hex` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `bundle_id` bigint(20) NOT NULL,
  `ct_period_id` int(11) DEFAULT NULL,
  `artifact_role` varchar(64) NOT NULL,
  `artifact_status` enum('reserved','generated','validated','failed','historical') NOT NULL DEFAULT 'reserved',
  `filename` varchar(255) DEFAULT NULL,
  `storage_path` varchar(1000) DEFAULT NULL,
  `sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `schema_identity` varchar(255) DEFAULT NULL,
  `schema_manifest_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `generator_name` varchar(100) NOT NULL,
  `generator_version` varchar(100) NOT NULL,
  `validator_name` varchar(100) DEFAULT NULL,
  `validator_version` varchar(100) DEFAULT NULL,
  `validation_status` varchar(32) DEFAULT NULL,
  `identifier_embedded` tinyint(1) NOT NULL DEFAULT 0,
  `legacy_non_embedded` tinyint(1) NOT NULL DEFAULT 0,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_filing_evidence_artifact_id` (`artifact_id`),
  UNIQUE KEY `uq_filing_evidence_transaction_hex` (`transaction_hex`),
  KEY `idx_filing_evidence_artifact_bundle` (`bundle_id`,`artifact_role`,`id`),
  CONSTRAINT `fk_filing_evidence_artifact_bundle` FOREIGN KEY (`bundle_id`) REFERENCES `filing_evidence_bundles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_filing_evidence_artifact_ct_period` FOREIGN KEY (`ct_period_id`) REFERENCES `corporation_tax_periods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `filing_evidence_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `bundle_id` bigint(20) NOT NULL,
  `artifact_id` bigint(20) DEFAULT NULL,
  `event_type` varchar(64) NOT NULL,
  `event_status` varchar(32) NOT NULL DEFAULT 'info',
  `actor` varchar(100) NOT NULL,
  `event_message` text NOT NULL,
  `event_context_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_filing_evidence_events_bundle` (`bundle_id`,`id`),
  CONSTRAINT `fk_filing_evidence_event_bundle` FOREIGN KEY (`bundle_id`) REFERENCES `filing_evidence_bundles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_filing_evidence_event_artifact` FOREIGN KEY (`artifact_id`) REFERENCES `filing_evidence_artifacts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `ixbrl_accounts_filing_approvals`
  ADD KEY `idx_ixbrl_approval_evidence_bundle` (`evidence_bundle_id`),
  ADD CONSTRAINT `fk_ixbrl_approval_evidence_bundle` FOREIGN KEY (`evidence_bundle_id`) REFERENCES `filing_evidence_bundles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `hmrc_ct600_submissions`
  ADD KEY `idx_hmrc_ct600_evidence_bundle` (`evidence_bundle_id`),
  ADD CONSTRAINT `fk_hmrc_ct600_evidence_bundle` FOREIGN KEY (`evidence_bundle_id`) REFERENCES `filing_evidence_bundles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `companies_house_accounts_submissions`
  ADD KEY `idx_ch_accounts_evidence_bundle` (`evidence_bundle_id`),
  ADD CONSTRAINT `fk_ch_accounts_evidence_bundle` FOREIGN KEY (`evidence_bundle_id`) REFERENCES `filing_evidence_bundles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
CREATE TABLE `frc_taxonomy_packages` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `taxonomy_version` varchar(32) NOT NULL,
  `artifact_version` varchar(32) NOT NULL,
  `source_url` varchar(1000) NOT NULL,
  `download_url` varchar(1000) DEFAULT NULL,
  `local_path` varchar(1000) DEFAULT NULL,
  `sha256` char(64) DEFAULT NULL,
  `package_state` enum('not_downloaded','verified','failed','stale') NOT NULL DEFAULT 'not_downloaded',
  `verification_error` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `published_at` date DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_frc_taxonomy_identity` (`taxonomy_version`,`artifact_version`),
  KEY `idx_frc_taxonomy_active` (`is_active`,`package_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `schema_migrations` (`migration`) VALUES
  ('2026_07_21_003_frc_taxonomy_artifacts.sql');
INSERT IGNORE INTO `role_card_permissions` (`role_id`, `card_key`)
SELECT DISTINCT `role_id`, 'tax_frc_taxonomy'
FROM `role_card_permissions`
WHERE `card_key` = 'tax_rates_ct600_rim';

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- CT-period iXBRL taxonomy and mapping tables are applied by
-- migrations/2026_07_19_003_ct_ixbrl_mapping_profiles.sql and
-- migrations/2026_07_19_004_ct_filing_mapping_maintenance.sql.
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-17 20:26:45
