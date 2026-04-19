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
-- Table structure for table `categorisation_rules`
--

DROP TABLE IF EXISTS `categorisation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categorisation_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `priority` int(11) NOT NULL DEFAULT 100,
  `match_field` enum('description','reference','name','type','card','any') NOT NULL DEFAULT 'any',
  `match_type` enum('contains','equals','starts_with','regex') NOT NULL DEFAULT 'contains',
  `match_value` varchar(255) NOT NULL,
  `source_category_value` varchar(255) DEFAULT NULL,
  `source_account_value` varchar(255) DEFAULT NULL,
  `nominal_account_id` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rules_company_priority` (`company_id`,`is_active`,`priority`),
  KEY `idx_rules_nominal` (`nominal_account_id`),
  CONSTRAINT `fk_categorisation_rules_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
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
  `vat_validation_name` varchar(255) DEFAULT NULL,
  `vat_validation_address_line1` varchar(255) DEFAULT NULL,
  `vat_validation_postcode` varchar(32) DEFAULT NULL,
  `vat_validation_country_code` varchar(8) DEFAULT NULL,
  `vat_last_error` text DEFAULT NULL,
  `incorporation_date` date DEFAULT NULL,
  `company_status` varchar(50) DEFAULT NULL,
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
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_companies_active` (`is_active`),
  KEY `idx_companies_incorporation_date` (`incorporation_date`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  CONSTRAINT `fk_company_accounts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
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
  `receipt_reference` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_expense_claim_lines_claim_line` (`expense_claim_id`,`line_number`),
  KEY `idx_expense_claim_lines_nominal` (`nominal_account_id`),
  CONSTRAINT `fk_expense_claim_lines_claim` FOREIGN KEY (`expense_claim_id`) REFERENCES `expense_claims` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
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
  `tax_year_id` int(11) NOT NULL,
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
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_expense_claims_company_reference` (`company_id`,`claim_reference_code`),
  UNIQUE KEY `uniq_expense_claims_company_claimant_month` (`company_id`,`claimant_id`,`claim_year`,`claim_month`),
  KEY `idx_expense_claims_company_period` (`company_id`,`claim_year`,`claim_month`),
  KEY `idx_expense_claims_tax_year` (`tax_year_id`),
  KEY `idx_expense_claims_claimant` (`claimant_id`),
  KEY `idx_expense_claims_posted_journal` (`posted_journal_id`),
  CONSTRAINT `fk_expense_claims_claimant` FOREIGN KEY (`claimant_id`) REFERENCES `expense_claimants` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_expense_claims_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_expense_claims_posted_journal` FOREIGN KEY (`posted_journal_id`) REFERENCES `journals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_expense_claims_tax_year` FOREIGN KEY (`tax_year_id`) REFERENCES `tax_years` (`id`) ON UPDATE CASCADE,
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
  `tax_year_id` int(11) NOT NULL,
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
  UNIQUE KEY `uq_journal_entry_metadata_key` (`company_id`,`tax_year_id`,`journal_tag`,`journal_key`),
  KEY `idx_journal_entry_metadata_period` (`company_id`,`tax_year_id`,`journal_tag`),
  KEY `idx_journal_entry_metadata_related` (`related_journal_id`),
  KEY `fk_journal_entry_metadata_tax_year` (`tax_year_id`),
  KEY `fk_journal_entry_metadata_replacement_journal` (`replacement_of_journal_id`),
  CONSTRAINT `fk_journal_entry_metadata_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_journal_entry_metadata_journal` FOREIGN KEY (`journal_id`) REFERENCES `journals` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_journal_entry_metadata_related_journal` FOREIGN KEY (`related_journal_id`) REFERENCES `journals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_journal_entry_metadata_replacement_journal` FOREIGN KEY (`replacement_of_journal_id`) REFERENCES `journals` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_journal_entry_metadata_tax_year` FOREIGN KEY (`tax_year_id`) REFERENCES `tax_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
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
  `company_account_id` int(11) DEFAULT NULL,
  `debit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_journal_lines_journal` (`journal_id`),
  KEY `idx_journal_lines_nominal` (`nominal_account_id`),
  KEY `idx_journal_lines_company_account` (`company_account_id`),
  CONSTRAINT `fk_journal_lines_company_account` FOREIGN KEY (`company_account_id`) REFERENCES `company_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
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
  `tax_year_id` int(11) NOT NULL,
  `source_type` enum('bank_csv','director_loan_register','expense_register','manual') NOT NULL,
  `source_ref` varchar(255) DEFAULT NULL,
  `journal_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `is_posted` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_journals_company_source_ref` (`company_id`,`source_type`,`source_ref`),
  KEY `idx_journals_company_date` (`company_id`,`journal_date`),
  KEY `idx_journals_tax_year_date` (`tax_year_id`,`journal_date`),
  KEY `idx_journals_source_type` (`source_type`),
  CONSTRAINT `fk_journals_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_journals_tax_year` FOREIGN KEY (`tax_year_id`) REFERENCES `tax_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

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
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_nominal_code` (`code`),
  KEY `idx_nominal_type_active` (`account_type`,`is_active`,`sort_order`),
  KEY `idx_nominal_subtype` (`account_subtype_id`),
  CONSTRAINT `fk_nominal_accounts_subtype` FOREIGN KEY (`account_subtype_id`) REFERENCES `nominal_account_subtypes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

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
  `source_type` varchar(50) NOT NULL DEFAULT 'anna_money',
  `original_headers_json` longtext NOT NULL,
  `mapping_json` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_statement_import_mappings_upload` (`upload_id`),
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
  `tax_year_id` int(11) DEFAULT NULL,
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
  KEY `idx_statement_import_rows_tax_year` (`tax_year_id`),
  CONSTRAINT `fk_statement_import_rows_committed_transaction` FOREIGN KEY (`committed_transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_statement_import_rows_tax_year` FOREIGN KEY (`tax_year_id`) REFERENCES `tax_years` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
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
  `tax_year_id` int(11) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `source_type` varchar(50) NOT NULL DEFAULT 'anna_money',
  `workflow_status` enum('uploaded','mapped','staged','committed','completed') NOT NULL DEFAULT 'uploaded',
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
  KEY `idx_statement_uploads_company_taxyear_month` (`company_id`,`tax_year_id`,`statement_month`),
  KEY `fk_statement_uploads_tax_year` (`tax_year_id`),
  KEY `idx_statement_uploads_company_status` (`company_id`,`tax_year_id`,`workflow_status`,`uploaded_at`),
  KEY `idx_statement_uploads_account` (`account_id`),
  KEY `idx_statement_uploads_company_file_hash` (`company_id`,`file_sha256`),
  KEY `idx_statement_uploads_company_uploaded` (`company_id`,`uploaded_at`),
  CONSTRAINT `fk_statement_uploads_account` FOREIGN KEY (`account_id`) REFERENCES `company_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_statement_uploads_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_statement_uploads_tax_year` FOREIGN KEY (`tax_year_id`) REFERENCES `tax_years` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=194 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `origin_tax_year_id` int(11) NOT NULL,
  `amount_originated` decimal(12,2) NOT NULL,
  `amount_used` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_remaining` decimal(12,2) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tax_loss_origin` (`company_id`,`origin_tax_year_id`),
  KEY `fk_tax_loss_year` (`origin_tax_year_id`),
  CONSTRAINT `fk_tax_loss_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tax_loss_year` FOREIGN KEY (`origin_tax_year_id`) REFERENCES `tax_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
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
  `tax_year_id` int(11) NOT NULL,
  `computation_hash` varchar(64) NOT NULL,
  `loss_created` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loss_brought_forward` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loss_utilised` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loss_carried_forward` decimal(12,2) NOT NULL DEFAULT 0.00,
  `taxable_before_losses` decimal(12,2) NOT NULL DEFAULT 0.00,
  `taxable_profit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `computed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tax_loss_history_period` (`company_id`,`tax_year_id`,`computed_at`),
  KEY `idx_tax_loss_history_hash` (`company_id`,`tax_year_id`,`computation_hash`),
  KEY `fk_tax_loss_history_tax_year` (`tax_year_id`),
  CONSTRAINT `fk_tax_loss_history_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tax_loss_history_tax_year` FOREIGN KEY (`tax_year_id`) REFERENCES `tax_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1003 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tax_year_adjustments`
--

DROP TABLE IF EXISTS `tax_year_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_year_adjustments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `tax_year_id` int(11) NOT NULL,
  `type` varchar(64) NOT NULL,
  `direction` varchar(16) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `source_asset_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tax_year_adjustments_company_year` (`company_id`,`tax_year_id`,`type`),
  KEY `fk_tax_adjustments_year` (`tax_year_id`),
  CONSTRAINT `fk_tax_adjustments_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tax_adjustments_year` FOREIGN KEY (`tax_year_id`) REFERENCES `tax_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tax_years`
--

DROP TABLE IF EXISTS `tax_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `label` varchar(64) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_company_label` (`company_id`,`label`),
  UNIQUE KEY `uniq_company_period` (`company_id`,`period_start`,`period_end`),
  KEY `idx_tax_years_company_period` (`company_id`,`period_start`,`period_end`),
  CONSTRAINT `fk_tax_years_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `tax_year_id` int(11) NOT NULL,
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
  KEY `idx_transactions_tax_year_date` (`tax_year_id`,`txn_date`),
  KEY `idx_transactions_upload` (`statement_upload_id`),
  KEY `idx_transactions_nominal` (`nominal_account_id`),
  KEY `idx_transactions_category_status` (`category_status`),
  KEY `idx_transactions_company_month` (`company_id`,`txn_date`),
  KEY `idx_transactions_company_currency` (`company_id`,`tax_year_id`,`currency`),
  KEY `idx_transactions_company_document_hash` (`company_id`,`document_url_hash`),
  KEY `idx_transactions_document_status` (`document_download_status`),
  KEY `idx_transactions_account` (`account_id`),
  KEY `idx_transactions_auto_rule` (`auto_rule_id`),
  KEY `idx_transactions_auto_excluded` (`company_id`,`tax_year_id`,`is_auto_excluded`,`category_status`),
  KEY `idx_transactions_transfer_account` (`transfer_account_id`),
  KEY `idx_transactions_internal_transfer` (`company_id`,`tax_year_id`,`is_internal_transfer`),
  CONSTRAINT `fk_transactions_account` FOREIGN KEY (`account_id`) REFERENCES `company_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transactions_auto_rule` FOREIGN KEY (`auto_rule_id`) REFERENCES `categorisation_rules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transactions_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_transactions_nominal` FOREIGN KEY (`nominal_account_id`) REFERENCES `nominal_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transactions_tax_year` FOREIGN KEY (`tax_year_id`) REFERENCES `tax_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_transactions_transfer_account` FOREIGN KEY (`transfer_account_id`) REFERENCES `company_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_transactions_upload` FOREIGN KEY (`statement_upload_id`) REFERENCES `statement_uploads` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_transactions_amount_nonzero` CHECK (`amount` <> 0)
) ENGINE=InnoDB AUTO_INCREMENT=4099 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `action_type` enum('user_created','user_enabled','user_disabled','password_set_admin','password_changed_self','email_changed','display_name_changed','otp_reset_admin','otp_rotation_started','otp_rotation_completed','mfa_authenticated','role_changed') NOT NULL,
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
  `password_hash` varchar(255) NOT NULL,
  `current_session_token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `current_session_started_at` datetime DEFAULT NULL,
  `current_session_last_seen_at` datetime DEFAULT NULL,
  `current_session_device_id` varchar(64) DEFAULT NULL,
  `current_session_ip_address` varchar(45) DEFAULT NULL,
  `current_session_user_agent` varchar(1000) DEFAULT NULL,
  `current_session_browser_label` varchar(255) DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `confirmed_director` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
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
-- Table structure for table `year_end_audit_log`
--

DROP TABLE IF EXISTS `year_end_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `year_end_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `tax_year_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `action_by` varchar(100) NOT NULL,
  `action_at` datetime NOT NULL DEFAULT current_timestamp(),
  `old_value_json` longtext DEFAULT NULL,
  `new_value_json` longtext DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_year_end_audit_log_company_period` (`company_id`,`tax_year_id`),
  KEY `idx_year_end_audit_log_action_at` (`action_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `year_end_check_results`
--

DROP TABLE IF EXISTS `year_end_check_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `year_end_check_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `tax_year_id` int(11) NOT NULL,
  `check_code` varchar(100) NOT NULL,
  `severity` enum('info','warning','fail') NOT NULL DEFAULT 'info',
  `status` enum('pass','warning','fail','not_applicable') NOT NULL DEFAULT 'pass',
  `title` varchar(255) NOT NULL,
  `detail_text` text DEFAULT NULL,
  `metric_value` varchar(255) DEFAULT NULL,
  `action_url` varchar(500) DEFAULT NULL,
  `calculated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_year_end_check_results_company_period_code` (`company_id`,`tax_year_id`,`check_code`),
  KEY `idx_year_end_check_results_company_period` (`company_id`,`tax_year_id`)
) ENGINE=InnoDB AUTO_INCREMENT=241 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `tax_year_id` int(11) NOT NULL,
  `status` enum('not_started','in_progress','needs_attention','ready_for_review','locked') NOT NULL DEFAULT 'not_started',
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` varchar(100) DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `last_recalculated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_year_end_reviews_company_period` (`company_id`,`tax_year_id`),
  KEY `idx_year_end_reviews_company` (`company_id`),
  KEY `idx_year_end_reviews_tax_year` (`tax_year_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-17 20:26:45
