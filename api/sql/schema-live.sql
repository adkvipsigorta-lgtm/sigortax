
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
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(50) NOT NULL,
  `entity` varchar(50) NOT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `entity_name` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_entity` (`entity`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branches` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `commission_rate` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `iban` varchar(34) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_branches_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cities` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `country_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cities_name` (`name`),
  KEY `idx_cities_country` (`country_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5228 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `companies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `color` varchar(20) NOT NULL DEFAULT 'primary',
  `logo_url` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_companies_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `countries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_countries_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=251 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `color` varchar(50) NOT NULL DEFAULT 'primary',
  `description` varchar(255) DEFAULT NULL,
  `min_amount` decimal(12,2) DEFAULT NULL,
  `max_amount` decimal(12,2) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'NOTE',
  `note` text NOT NULL,
  `task_id` int(10) unsigned DEFAULT NULL,
  `policy_id` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_customer_notes_customer` (`customer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `customer_type` enum('INDIVIDUAL','CORPORATE') NOT NULL DEFAULT 'INDIVIDUAL',
  `name` varchar(255) NOT NULL,
  `identity_no` varchar(20) DEFAULT NULL,
  `tax_office` varchar(100) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `phone_alt` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `marital_status` varchar(20) DEFAULT NULL,
  `job` varchar(100) DEFAULT NULL,
  `dependents_count` smallint(5) unsigned DEFAULT NULL,
  `sector` varchar(255) DEFAULT NULL,
  `country_id` int(10) unsigned DEFAULT NULL,
  `city_id` int(10) unsigned DEFAULT NULL,
  `district_id` int(10) unsigned DEFAULT NULL,
  `address` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `identity_no_active` varchar(50) GENERATED ALWAYS AS (if(`deleted_at` is null and `identity_no` is not null and `identity_no` <> '',`identity_no`,NULL)) VIRTUAL,
  PRIMARY KEY (`id`),
  KEY `idx_cust_name` (`name`),
  KEY `idx_cust_identity` (`identity_no`),
  KEY `idx_cust_phone` (`phone`),
  KEY `idx_cust_type` (`customer_type`)
) ENGINE=InnoDB AUTO_INCREMENT=5005 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `districts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `districts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `city_id` int(10) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_districts_city` (`city_id`),
  KEY `idx_districts_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=153662 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL DEFAULT '',
  `storage_hash` varchar(64) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(10) unsigned NOT NULL DEFAULT 0,
  `encrypted` tinyint(1) NOT NULL DEFAULT 0,
  `file_iv` varbinary(16) DEFAULT NULL,
  `file_auth_tag` varbinary(16) DEFAULT NULL,
  `dek_encrypted` varbinary(128) DEFAULT NULL,
  `dek_iv` varbinary(16) DEFAULT NULL,
  `dek_auth_tag` varbinary(16) DEFAULT NULL,
  `customer_id` int(10) unsigned DEFAULT NULL,
  `policy_id` int(10) unsigned DEFAULT NULL,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_documents_customer_id` (`customer_id`),
  KEY `idx_documents_policy_id` (`policy_id`),
  KEY `idx_documents_storage_hash` (`storage_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `import_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `import_mappings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `mapping_json` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_im_user_name` (`user_id`,`name`),
  KEY `idx_im_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `insurance_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `insurance_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `code` varchar(15) NOT NULL DEFAULT 'OTHER',
  `color` varchar(20) NOT NULL DEFAULT 'primary',
  `default_comm_rate` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `extra_comm_rate` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `level` varchar(20) DEFAULT NULL COMMENT 'branch, category, subcategory',
  `parent_id` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `show_in_charts` tinyint(1) NOT NULL DEFAULT 0,
  `is_renewable` tinyint(1) NOT NULL DEFAULT 1,
  `branch_group` varchar(40) NOT NULL DEFAULT 'DIGER',
  `external_code` varchar(10) DEFAULT NULL,
  `renewal_days` int(11) NOT NULL DEFAULT 15,
  `follow_up_call_eligible` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ins_parent` (`parent_id`),
  KEY `idx_ins_active` (`is_active`),
  KEY `idx_ins_group` (`branch_group`),
  KEY `idx_ins_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leave_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_balances` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `leave_type_id` int(10) unsigned NOT NULL,
  `year` int(11) NOT NULL,
  `total_days` decimal(4,1) NOT NULL DEFAULT 0.0,
  `used_days` decimal(4,1) NOT NULL DEFAULT 0.0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_type_year` (`user_id`,`leave_type_id`,`year`),
  KEY `leave_type_id` (`leave_type_id`),
  CONSTRAINT `leave_balances_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `leave_balances_ibfk_2` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leave_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `leave_type_id` int(10) unsigned NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days` decimal(4,1) NOT NULL,
  `note` text DEFAULT NULL,
  `status` enum('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `approved_by` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `leave_type_id` (`leave_type_id`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `leave_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `leave_requests_ibfk_2` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`),
  CONSTRAINT `leave_requests_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leave_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `default_days` int(11) NOT NULL DEFAULT 0,
  `is_paid` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lost_policy_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lost_policy_actions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `policy_id` int(10) unsigned NOT NULL,
  `status` enum('PENDING','CALLED','OFFERED','WON','ABANDONED','VEHICLE_SOLD') DEFAULT 'PENDING',
  `note` text DEFAULT NULL,
  `expected_date` date DEFAULT NULL,
  `registration_no` varchar(50) DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_policy` (`policy_id`),
  UNIQUE KEY `uniq_lost_policy_policy` (`policy_id`)
) ENGINE=InnoDB AUTO_INCREMENT=83 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lost_policy_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lost_policy_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `policy_id` int(10) unsigned NOT NULL,
  `customer_id` int(10) unsigned NOT NULL,
  `insurance_type_id` int(10) unsigned NOT NULL,
  `plate_no` varchar(20) DEFAULT NULL,
  `expires_at` date NOT NULL,
  `lost_at` date NOT NULL,
  `status` enum('LOST','RECOVERED','ABANDONED','VEHICLE_SOLD') NOT NULL DEFAULT 'LOST',
  `recovered_policy_id` int(10) unsigned DEFAULT NULL,
  `recovered_at` date DEFAULT NULL,
  `registration_no` varchar(50) DEFAULT NULL,
  `expected_date` date DEFAULT NULL,
  `note` text DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_policy_event` (`policy_id`),
  KEY `idx_customer_type_status` (`customer_id`,`insurance_type_id`,`status`),
  KEY `idx_plate_type_status` (`plate_no`,`insurance_type_id`,`status`),
  KEY `idx_status_lost_at` (`status`,`lost_at`),
  KEY `idx_recovered` (`status`,`recovered_at`),
  KEY `idx_recovered_policy` (`recovered_policy_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1753 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `message_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `message_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `channel` enum('sms','whatsapp','email') NOT NULL DEFAULT 'sms',
  `subject` varchar(255) DEFAULT NULL,
  `content` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `channel` enum('sms','whatsapp','email') NOT NULL DEFAULT 'sms',
  `recipient` varchar(255) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `content` text NOT NULL,
  `customer_id` int(10) unsigned DEFAULT NULL,
  `template_id` int(10) unsigned DEFAULT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `sent_by` int(10) unsigned DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_msg_customer` (`customer_id`),
  KEY `idx_msg_channel` (`channel`),
  KEY `idx_msg_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `filename` varchar(255) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `data` varchar(255) DEFAULT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'info',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`),
  KEY `idx_notif_read` (`is_read`),
  KEY `idx_notif_date` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=475 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) NOT NULL,
  `group` varchar(50) NOT NULL,
  `group_label` varchar(100) NOT NULL,
  `label` varchar(100) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`),
  UNIQUE KEY `uq_key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `policies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(10) unsigned DEFAULT NULL COMMENT 'Ana police ID - zeyil/yenileme baglantisi',
  `production_type` enum('SELF','INCOMING','OUTGOING') NOT NULL DEFAULT 'SELF',
  `customer_id` int(10) unsigned NOT NULL,
  `insurance_type_id` int(10) unsigned NOT NULL,
  `company_id` int(10) unsigned DEFAULT NULL,
  `branch_id` int(10) unsigned DEFAULT NULL,
  `policy_no` varchar(60) NOT NULL,
  `insured_name` varchar(255) DEFAULT NULL,
  `insured_no` varchar(50) DEFAULT NULL,
  `issued_at` date DEFAULT NULL,
  `starts_at` date DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `gross_premium` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_premium` decimal(12,2) NOT NULL DEFAULT 0.00,
  `company_comm_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `company_comm_amount` decimal(12,2) DEFAULT NULL,
  `branch_comm_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `branch_comm_amount` decimal(12,2) DEFAULT NULL,
  `is_cancelled` tinyint(1) NOT NULL DEFAULT 0,
  `no_renewal_reminder` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Bu police icin otomatik yenileme gorevi olusturulmasin',
  `is_approved` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Mutabakat',
  `endorsement_no` int(11) NOT NULL DEFAULT 1 COMMENT '1=ana, 2+=zeyil',
  `plate_no` varchar(20) DEFAULT NULL,
  `chassis_no` varchar(50) DEFAULT NULL,
  `engine_no` varchar(50) DEFAULT NULL,
  `registration_no` varchar(50) DEFAULT NULL,
  `vehicle_brand` varchar(100) DEFAULT NULL,
  `vehicle_model` varchar(100) DEFAULT NULL,
  `vehicle_year` varchar(10) DEFAULT NULL,
  `uavt_code` varchar(30) DEFAULT NULL,
  `risk_address` text DEFAULT NULL,
  `dask_no` varchar(50) DEFAULT NULL,
  `network` varchar(100) DEFAULT NULL,
  `additional_insureds` text DEFAULT NULL,
  `reference_source` int(10) unsigned DEFAULT NULL COMMENT 'FK -> reference_sources.id',
  `created_by` int(10) unsigned DEFAULT NULL COMMENT 'Olusturan kullanici',
  `sold_by` int(10) unsigned DEFAULT NULL COMMENT 'Policeyi satan kullanici',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `reconciliation_status` varchar(20) NOT NULL DEFAULT 'PENDING',
  `policy_active_hash` varchar(255) GENERATED ALWAYS AS (if(`deleted_at` is null,concat(`policy_no`,'_',`endorsement_no`),NULL)) VIRTUAL,
  PRIMARY KEY (`id`),
  KEY `idx_pol_parent` (`parent_id`),
  KEY `idx_pol_customer` (`customer_id`),
  KEY `idx_pol_insurance` (`insurance_type_id`),
  KEY `idx_pol_company` (`company_id`),
  KEY `idx_pol_branch` (`branch_id`),
  KEY `idx_pol_number` (`policy_no`),
  KEY `idx_pol_expires` (`expires_at`),
  KEY `idx_pol_cancelled` (`is_cancelled`),
  KEY `idx_pol_starts` (`starts_at`),
  KEY `idx_pol_created` (`created_at`),
  KEY `idx_plate_type_expires` (`plate_no`,`insurance_type_id`,`expires_at`),
  KEY `idx_cust_type_expires` (`customer_id`,`insurance_type_id`,`expires_at`),
  KEY `idx_expires_cancelled_deleted` (`expires_at`,`is_cancelled`,`deleted_at`),
  KEY `idx_pol_no_endorsement` (`policy_no`,`endorsement_no`)
) ENGINE=InnoDB AUTO_INCREMENT=13414 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reference_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reference_sources` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `commission_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `device` varchar(100) DEFAULT NULL,
  `browser` varchar(100) DEFAULT NULL,
  `os` varchar(100) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `country_code` varchar(5) DEFAULT NULL,
  `language` varchar(50) DEFAULT NULL,
  `token_hash` varchar(64) DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `is_revoked` tinyint(1) NOT NULL DEFAULT 0,
  `logged_in_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_active_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sessions_user` (`user_id`),
  KEY `idx_sessions_token` (`token_hash`),
  KEY `idx_sessions_revoked` (`is_revoked`),
  KEY `idx_sessions_logged` (`logged_in_at`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_settings_key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=259 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `task_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `task_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int(10) unsigned NOT NULL,
  `action` enum('CREATED','ASSIGNED','REASSIGNED','STATUS_CHANGED','COMPLETED','EXPIRED') NOT NULL,
  `from_user_id` int(10) unsigned DEFAULT NULL,
  `to_user_id` int(10) unsigned DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tl_task` (`task_id`),
  KEY `idx_tl_action` (`action`)
) ENGINE=InnoDB AUTO_INCREMENT=10592 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `task_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `task_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_id` bigint(20) unsigned NOT NULL,
  `note` text NOT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task_notes_task_id` (`task_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tasks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(30) NOT NULL DEFAULT 'OTHER',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `policy_id` int(10) unsigned DEFAULT NULL,
  `customer_id` int(10) unsigned DEFAULT NULL,
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `assigned_by` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `status` enum('PENDING','IN_PROGRESS','COMPLETED','EXPIRED','CANCELLED') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `priority` enum('LOW','MEDIUM','HIGH','URGENT') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'MEDIUM',
  `deadline` datetime DEFAULT NULL,
  `scheduled_for` date DEFAULT NULL COMMENT 'FOLLOW_UP_CALL aktivasyon tarihi (starts_at + stageDays)',
  `offer_expires_at` date DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `result` varchar(30) DEFAULT NULL,
  `result_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completed_by` int(10) unsigned DEFAULT NULL,
  `offer_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Teklif verileri (OFFER tipi gorevler icin)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tasks_status` (`status`),
  KEY `idx_tasks_type` (`type`),
  KEY `idx_tasks_assigned` (`assigned_to`),
  KEY `idx_tasks_policy` (`policy_id`),
  KEY `idx_tasks_customer` (`customer_id`),
  KEY `idx_tasks_deadline` (`deadline`),
  KEY `idx_tasks_offer_expires_at` (`offer_expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=8941 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `permission_key` varchar(50) NOT NULL,
  `allowed` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_perm` (`user_id`,`permission_key`),
  UNIQUE KEY `uq_user_perm` (`user_id`,`permission_key`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `role` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=kullanici, 1=admin, 2=acente',
  `branch_id` int(10) unsigned DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `tc_no` varchar(11) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `address` text DEFAULT NULL,
  `country_id` int(10) unsigned DEFAULT NULL,
  `city_id` int(10) unsigned DEFAULT NULL,
  `district_id` int(10) unsigned DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `termination_date` date DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `emergency_contact_name` varchar(100) DEFAULT NULL,
  `emergency_contact_phone` varchar(30) DEFAULT NULL,
  `emergency_contact_relation` varchar(50) DEFAULT NULL,
  `two_factor_secret` varchar(255) DEFAULT NULL,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_recovery_codes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_branch` (`branch_id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

