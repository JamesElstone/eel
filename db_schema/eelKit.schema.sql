/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for FreeBSD14.3 (amd64)
--
-- Host: localhost    Database: eelKit
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
DROP TABLE IF EXISTS `role_card_permissions`;
DROP TABLE IF EXISTS `application_activity_flash_history`;
DROP TABLE IF EXISTS `user_account_audit`;
DROP TABLE IF EXISTS `user_login_rate_limits`;
DROP TABLE IF EXISTS `user_logon_history`;
DROP TABLE IF EXISTS `user_totp`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `mobile_country_codes`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `schema_migrations`;

--
-- Table structure for table `schema_migrations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `schema_migrations` (
  `migration` varchar(255) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mobile_country_codes`
--

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
INSERT INTO `mobile_country_codes` (`country_code`, `display_name`, `is_default`, `sort_order`) VALUES
  ('+1', 'NANP countries and territories', 0, 1),
  ('+7', 'Kazakhstan / Russian Federation', 0, 7),
  ('+20', 'Egypt', 0, 20),
  ('+27', 'South Africa', 0, 27),
  ('+30', 'Greece', 0, 30),
  ('+31', 'Netherlands', 0, 31),
  ('+32', 'Belgium', 0, 32),
  ('+33', 'France', 0, 33),
  ('+34', 'Spain', 0, 34),
  ('+36', 'Hungary', 0, 36),
  ('+39', 'Italy / Vatican City State', 0, 39),
  ('+40', 'Romania', 0, 40),
  ('+41', 'Switzerland', 0, 41),
  ('+43', 'Austria', 0, 43),
  ('+44', 'United Kingdom', 1, 44),
  ('+45', 'Denmark', 0, 45),
  ('+46', 'Sweden', 0, 46),
  ('+47', 'Norway', 0, 47),
  ('+48', 'Poland', 0, 48),
  ('+49', 'Germany', 0, 49),
  ('+51', 'Peru', 0, 51),
  ('+52', 'Mexico', 0, 52),
  ('+53', 'Cuba', 0, 53),
  ('+54', 'Argentina', 0, 54),
  ('+55', 'Brazil', 0, 55),
  ('+56', 'Chile', 0, 56),
  ('+57', 'Colombia', 0, 57),
  ('+58', 'Venezuela', 0, 58),
  ('+60', 'Malaysia', 0, 60),
  ('+61', 'Australia', 0, 61),
  ('+62', 'Indonesia', 0, 62),
  ('+63', 'Philippines', 0, 63),
  ('+64', 'New Zealand', 0, 64),
  ('+65', 'Singapore', 0, 65),
  ('+66', 'Thailand', 0, 66),
  ('+81', 'Japan', 0, 81),
  ('+82', 'Korea, Republic of', 0, 82),
  ('+84', 'Viet Nam', 0, 84),
  ('+86', 'China', 0, 86),
  ('+90', 'Turkey', 0, 90),
  ('+91', 'India', 0, 91),
  ('+92', 'Pakistan', 0, 92),
  ('+93', 'Afghanistan', 0, 93),
  ('+94', 'Sri Lanka', 0, 94),
  ('+95', 'Myanmar', 0, 95),
  ('+98', 'Iran', 0, 98),
  ('+211', 'South Sudan', 0, 211),
  ('+212', 'Morocco', 0, 212),
  ('+213', 'Algeria', 0, 213),
  ('+216', 'Tunisia', 0, 216),
  ('+218', 'Libya', 0, 218),
  ('+220', 'Gambia', 0, 220),
  ('+221', 'Senegal', 0, 221),
  ('+222', 'Mauritania', 0, 222),
  ('+223', 'Mali', 0, 223),
  ('+224', 'Guinea', 0, 224),
  ('+225', 'Cote d''Ivoire', 0, 225),
  ('+226', 'Burkina Faso', 0, 226),
  ('+227', 'Niger', 0, 227),
  ('+228', 'Togolese Republic', 0, 228),
  ('+229', 'Benin', 0, 229),
  ('+230', 'Mauritius', 0, 230),
  ('+231', 'Liberia', 0, 231),
  ('+232', 'Sierra Leone', 0, 232),
  ('+233', 'Ghana', 0, 233),
  ('+234', 'Nigeria', 0, 234),
  ('+235', 'Chad', 0, 235),
  ('+236', 'Central African Republic', 0, 236),
  ('+237', 'Cameroon', 0, 237),
  ('+238', 'Cabo Verde', 0, 238),
  ('+239', 'Sao Tome and Principe', 0, 239),
  ('+240', 'Equatorial Guinea', 0, 240),
  ('+241', 'Gabonese Republic', 0, 241),
  ('+242', 'Congo', 0, 242),
  ('+243', 'Democratic Republic of the Congo', 0, 243),
  ('+244', 'Angola', 0, 244),
  ('+245', 'Guinea-Bissau', 0, 245),
  ('+246', 'Diego Garcia', 0, 246),
  ('+247', 'Saint Helena, Ascension and Tristan da Cunha', 0, 247),
  ('+248', 'Seychelles', 0, 248),
  ('+249', 'Sudan', 0, 249),
  ('+250', 'Rwanda', 0, 250),
  ('+251', 'Ethiopia', 0, 251),
  ('+252', 'Somalia', 0, 252),
  ('+253', 'Djibouti', 0, 253),
  ('+254', 'Kenya', 0, 254),
  ('+255', 'Tanzania', 0, 255),
  ('+256', 'Uganda', 0, 256),
  ('+257', 'Burundi', 0, 257),
  ('+258', 'Mozambique', 0, 258),
  ('+260', 'Zambia', 0, 260),
  ('+261', 'Madagascar', 0, 261),
  ('+262', 'French Departments and Territories in the Indian Ocean', 0, 262),
  ('+263', 'Zimbabwe', 0, 263),
  ('+264', 'Namibia', 0, 264),
  ('+265', 'Malawi', 0, 265),
  ('+266', 'Lesotho', 0, 266),
  ('+267', 'Botswana', 0, 267),
  ('+268', 'Swaziland', 0, 268),
  ('+269', 'Comoros', 0, 269),
  ('+290', 'Saint Helena, Ascension and Tristan da Cunha', 0, 290),
  ('+291', 'Eritrea', 0, 291),
  ('+297', 'Aruba', 0, 297),
  ('+298', 'Faroe Islands', 0, 298),
  ('+299', 'Greenland', 0, 299),
  ('+350', 'Gibraltar', 0, 350),
  ('+351', 'Portugal', 0, 351),
  ('+352', 'Luxembourg', 0, 352),
  ('+353', 'Ireland', 0, 353),
  ('+354', 'Iceland', 0, 354),
  ('+355', 'Albania', 0, 355),
  ('+356', 'Malta', 0, 356),
  ('+357', 'Cyprus', 0, 357),
  ('+358', 'Finland', 0, 358),
  ('+359', 'Bulgaria', 0, 359),
  ('+370', 'Lithuania', 0, 370),
  ('+371', 'Latvia', 0, 371),
  ('+372', 'Estonia', 0, 372),
  ('+373', 'Moldova', 0, 373),
  ('+374', 'Armenia', 0, 374),
  ('+375', 'Belarus', 0, 375),
  ('+376', 'Andorra', 0, 376),
  ('+377', 'Monaco', 0, 377),
  ('+378', 'San Marino', 0, 378),
  ('+379', 'Vatican City State', 0, 379),
  ('+380', 'Ukraine', 0, 380),
  ('+381', 'Serbia', 0, 381),
  ('+382', 'Montenegro', 0, 382),
  ('+383', 'Kosovo', 0, 383),
  ('+385', 'Croatia', 0, 385),
  ('+386', 'Slovenia', 0, 386),
  ('+387', 'Bosnia and Herzegovina', 0, 387),
  ('+389', 'North Macedonia', 0, 389),
  ('+420', 'Czech Republic', 0, 420),
  ('+421', 'Slovak Republic', 0, 421),
  ('+423', 'Liechtenstein', 0, 423),
  ('+500', 'Falkland Islands', 0, 500),
  ('+501', 'Belize', 0, 501),
  ('+502', 'Guatemala', 0, 502),
  ('+503', 'El Salvador', 0, 503),
  ('+504', 'Honduras', 0, 504),
  ('+505', 'Nicaragua', 0, 505),
  ('+506', 'Costa Rica', 0, 506),
  ('+507', 'Panama', 0, 507),
  ('+508', 'Saint Pierre and Miquelon', 0, 508),
  ('+509', 'Haiti', 0, 509),
  ('+590', 'Guadeloupe', 0, 590),
  ('+591', 'Bolivia', 0, 591),
  ('+592', 'Guyana', 0, 592),
  ('+593', 'Ecuador', 0, 593),
  ('+594', 'French Guiana', 0, 594),
  ('+595', 'Paraguay', 0, 595),
  ('+596', 'Martinique', 0, 596),
  ('+597', 'Suriname', 0, 597),
  ('+598', 'Uruguay', 0, 598),
  ('+599', 'Bonaire, Sint Eustatius and Saba / Curacao', 0, 599),
  ('+670', 'Timor-Leste', 0, 670),
  ('+672', 'Australian External Territories', 0, 672),
  ('+673', 'Brunei Darussalam', 0, 673),
  ('+674', 'Nauru', 0, 674),
  ('+675', 'Papua New Guinea', 0, 675),
  ('+676', 'Tonga', 0, 676),
  ('+677', 'Solomon Islands', 0, 677),
  ('+678', 'Vanuatu', 0, 678),
  ('+679', 'Fiji', 0, 679),
  ('+680', 'Palau', 0, 680),
  ('+681', 'Wallis and Futuna', 0, 681),
  ('+682', 'Cook Islands', 0, 682),
  ('+683', 'Niue', 0, 683),
  ('+685', 'Samoa', 0, 685),
  ('+686', 'Kiribati', 0, 686),
  ('+687', 'New Caledonia', 0, 687),
  ('+688', 'Tuvalu', 0, 688),
  ('+689', 'French Polynesia', 0, 689),
  ('+690', 'Tokelau', 0, 690),
  ('+691', 'Micronesia', 0, 691),
  ('+692', 'Marshall Islands', 0, 692),
  ('+850', 'Democratic People''s Republic of Korea', 0, 850),
  ('+852', 'Hong Kong, China', 0, 852),
  ('+853', 'Macao, China', 0, 853),
  ('+855', 'Cambodia', 0, 855),
  ('+856', 'Lao People''s Democratic Republic', 0, 856),
  ('+880', 'Bangladesh', 0, 880),
  ('+886', 'Taiwan, China', 0, 886),
  ('+960', 'Maldives', 0, 960),
  ('+961', 'Lebanon', 0, 961),
  ('+962', 'Jordan', 0, 962),
  ('+963', 'Syrian Arab Republic', 0, 963),
  ('+964', 'Iraq', 0, 964),
  ('+965', 'Kuwait', 0, 965),
  ('+966', 'Saudi Arabia', 0, 966),
  ('+967', 'Yemen', 0, 967),
  ('+968', 'Oman', 0, 968),
  ('+971', 'United Arab Emirates', 0, 971),
  ('+972', 'Israel', 0, 972),
  ('+973', 'Bahrain', 0, 973),
  ('+974', 'Qatar', 0, 974),
  ('+975', 'Bhutan', 0, 975),
  ('+976', 'Mongolia', 0, 976),
  ('+977', 'Nepal', 0, 977),
  ('+992', 'Tajikistan', 0, 992),
  ('+993', 'Turkmenistan', 0, 993),
  ('+994', 'Azerbaijan', 0, 994),
  ('+995', 'Georgia', 0, 995),
  ('+996', 'Kyrgyz Republic', 0, 996),
  ('+998', 'Uzbekistan', 0, 998);

--
-- Table structure for table `users`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `display_name` varchar(255) NOT NULL,
  `email_address` varchar(255) NOT NULL,
  `mobile_number` varchar(32) DEFAULT NULL,
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
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `otp_required` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `role_id` int(11) NOT NULL DEFAULT -1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email_address` (`email_address`),
  UNIQUE KEY `uq_users_current_session_token_hash` (`current_session_token_hash`),
  KEY `idx_users_role_id` (`role_id`),
  CONSTRAINT `chk_users_email_address_not_blank` CHECK (`email_address` <> ''),
  CONSTRAINT `chk_users_role_id_reserved_or_positive` CHECK (`role_id` = -1 OR `role_id` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `role_card_permissions`
--

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
  CONSTRAINT `chk_role_card_permissions_card_key_not_blank` CHECK (`card_key` <> ''),
  CONSTRAINT `fk_role_card_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_login_rate_limits`
--

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
  CONSTRAINT `chk_user_login_rate_limits_email_address_not_blank` CHECK (`email_address` <> ''),
  CONSTRAINT `chk_user_login_rate_limits_scope_type_not_blank` CHECK (`scope_type` <> ''),
  CONSTRAINT `fk_user_login_rate_limits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `application_activity_flash_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `application_activity_flash_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `page_id` varchar(255) NOT NULL,
  `action_name` varchar(255) DEFAULT NULL,
  `card_action_name` varchar(255) DEFAULT NULL,
  `message_type` enum('success','error') NOT NULL,
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
-- Table structure for table `user_account_audit`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_account_audit` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `affected_user_id` int(11) NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `action_type` enum('user_created','user_enabled','user_disabled','password_set_admin','password_change_required_admin','password_changed_self','email_changed','display_name_changed','mobile_number_changed','otp_requirement_changed','otp_reset_admin','login_lockout_reset_admin','otp_rotation_started','otp_rotation_completed','mfa_authenticated','role_changed') NOT NULL,
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
  CONSTRAINT `fk_user_account_audit_actor_user` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_user_account_audit_affected_user` FOREIGN KEY (`affected_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_logon_history`
--

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
  CONSTRAINT `fk_user_logon_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_totp`
--

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
  CONSTRAINT `chk_user_totp_otp_digits` CHECK (`otp_digits` BETWEEN 6 AND 8),
  CONSTRAINT `chk_user_totp_pending_otp_digits` CHECK (`pending_otp_digits` IS NULL OR `pending_otp_digits` BETWEEN 6 AND 8),
  CONSTRAINT `chk_user_totp_otp_period_positive` CHECK (`otp_period` > 0),
  CONSTRAINT `chk_user_totp_pending_otp_period_positive` CHECK (`pending_otp_period` IS NULL OR `pending_otp_period` > 0),
  CONSTRAINT `fk_user_totp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
INSERT INTO `schema_migrations` (`migration`) VALUES
  ('2026_05_07_001_initial_schema.sql'),
  ('2026_05_08_001_schema_integrity.sql'),
  ('2026_05_08_002_force_password_change.sql'),
  ('2026_05_08_003_user_otp_optional.sql');
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-05-07 22:50:30
