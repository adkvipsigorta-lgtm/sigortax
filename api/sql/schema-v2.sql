-- =============================================
-- Sigorta CRM - Veritabani Semasi v2
-- Sifirdan tasarim, temiz isimlendirme
-- =============================================
-- TABLO ESLESTIRME (eski -> yeni):
--   branch          -> branches
--   city            -> cities
--   district        -> districts
--   company         -> companies
--   insurance       -> insurance_types
--   customers       -> customers
--   policy          -> policies
--   users           -> users
--   options         -> settings
--   audit_log       -> audit_logs
--   login_sessions  -> sessions
--
--   country         -> countries
-- KALDIRILAN TABLOLAR:
--   calendar_tasks, reminders, activity_log
--
-- KALDIRILAN KOLONLAR (policies):
--   is_offer      -> KALDIRILDI (teklifler tasks sisteminde)
--   offer_no      -> KALDIRILDI
--   ref           -> KALDIRILDI
--
-- EKLENEN KOLONLAR (policies):
--   parent_id     -> Zeyil/yenileme ana police baglantisi
-- =============================================
-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Anamakine: localhost:3306
-- Üretim Zamanı: 06 May 2026, 10:57:28
-- Sunucu sürümü: 8.0.45-cll-lve
-- PHP Sürümü: 8.4.20

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Veritabanı: `omctekno_test`
--

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int UNSIGNED NOT NULL,
  `action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` int UNSIGNED DEFAULT NULL,
  `entity_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user_id` int UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `branches`
--

CREATE TABLE `branches` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `commission_rate` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `iban` varchar(34) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `cities`
--

CREATE TABLE `cities` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `country_id` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `companies`
--

CREATE TABLE `companies` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'primary',
  `logo_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `countries`
--

CREATE TABLE `countries` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `customers`
--

CREATE TABLE `customers` (
  `id` int UNSIGNED NOT NULL,
  `customer_type` enum('INDIVIDUAL','CORPORATE') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INDIVIDUAL',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `identity_no` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_office` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_alt` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_person` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marital_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `job` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dependents_count` smallint UNSIGNED DEFAULT NULL,
  `sector` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_id` int UNSIGNED DEFAULT NULL,
  `city_id` int UNSIGNED DEFAULT NULL,
  `district_id` int UNSIGNED DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `customer_categories`
--

CREATE TABLE `customer_categories` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'primary',
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `min_amount` decimal(12,2) DEFAULT NULL,
  `max_amount` decimal(12,2) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `districts`
--

CREATE TABLE `districts` (
  `id` int UNSIGNED NOT NULL,
  `city_id` int UNSIGNED NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `documents`
--

CREATE TABLE `documents` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `storage_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int UNSIGNED NOT NULL DEFAULT '0',
  `encrypted` tinyint(1) NOT NULL DEFAULT '0',
  `file_iv` varbinary(16) DEFAULT NULL,
  `file_auth_tag` varbinary(16) DEFAULT NULL,
  `dek_encrypted` varbinary(128) DEFAULT NULL,
  `dek_iv` varbinary(16) DEFAULT NULL,
  `dek_auth_tag` varbinary(16) DEFAULT NULL,
  `customer_id` int UNSIGNED DEFAULT NULL,
  `policy_id` int UNSIGNED DEFAULT NULL,
  `uploaded_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `insurance_types`
--

CREATE TABLE `insurance_types` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'OTHER',
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'primary',
  `default_comm_rate` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `extra_comm_rate` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `level` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'branch, category, subcategory',
  `parent_id` int UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `show_in_charts` tinyint(1) NOT NULL DEFAULT '0',
  `branch_group` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'DIGER',
  `external_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `renewal_days` int NOT NULL DEFAULT '15',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `messages`
--

CREATE TABLE `messages` (
  `id` int UNSIGNED NOT NULL,
  `channel` enum('sms','whatsapp','email') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sms',
  `recipient` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` int UNSIGNED DEFAULT NULL,
  `template_id` int UNSIGNED DEFAULT NULL,
  `status` enum('pending','sent','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `sent_by` int UNSIGNED DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `message_templates`
--

CREATE TABLE `message_templates` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` enum('sms','whatsapp','email') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sms',
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `notifications`
--

CREATE TABLE `notifications` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `policies`
--

CREATE TABLE `policies` (
  `id` int UNSIGNED NOT NULL,
  `parent_id` int UNSIGNED DEFAULT NULL COMMENT 'Ana police ID - zeyil/yenileme baglantisi',
  `production_type` enum('SELF','INCOMING','OUTGOING') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'SELF',
  `customer_id` int UNSIGNED NOT NULL,
  `insurance_type_id` int UNSIGNED NOT NULL,
  `company_id` int UNSIGNED DEFAULT NULL,
  `branch_id` int UNSIGNED DEFAULT NULL,
  `policy_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `insured_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `insured_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issued_at` date DEFAULT NULL,
  `starts_at` date DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `gross_premium` decimal(12,2) NOT NULL DEFAULT '0.00',
  `net_premium` decimal(12,2) NOT NULL DEFAULT '0.00',
  `company_comm_rate` tinyint UNSIGNED DEFAULT '0',
  `branch_comm_rate` tinyint UNSIGNED DEFAULT '0',
  `is_cancelled` tinyint(1) NOT NULL DEFAULT '0',
  `no_renewal_reminder` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Bu police icin otomatik yenileme gorevi olusturulmasin',
  `is_approved` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Mutabakat',
  `endorsement_no` int NOT NULL DEFAULT '1' COMMENT '1=ana, 2+=zeyil',
  `plate_no` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chassis_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `engine_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vehicle_brand` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vehicle_model` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vehicle_year` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uavt_code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dask_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `network` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `additional_insureds` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reference_source` int UNSIGNED DEFAULT NULL COMMENT 'FK -> reference_sources.id',
  `created_by` int UNSIGNED DEFAULT NULL COMMENT 'Olusturan kullanici',
  `sold_by` int UNSIGNED DEFAULT NULL COMMENT 'Policeyi satan kullanici',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `reference_sources`
--

CREATE TABLE `reference_sources` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `commission_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sessions`
--

CREATE TABLE `sessions` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `device` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `browser` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `os` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_code` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `language` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT '0',
  `is_revoked` tinyint(1) NOT NULL DEFAULT '0',
  `logged_in_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_active_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `settings`
--

CREATE TABLE `settings` (
  `id` int UNSIGNED NOT NULL,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `tasks`
--

CREATE TABLE `tasks` (
  `id` int UNSIGNED NOT NULL,
  `type` enum('RENEWAL','OFFER','OTHER','CROSS_SELL') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `policy_id` int UNSIGNED DEFAULT NULL,
  `customer_id` int UNSIGNED DEFAULT NULL,
  `assigned_to` int UNSIGNED DEFAULT NULL,
  `assigned_by` int UNSIGNED DEFAULT NULL,
  `created_by` int UNSIGNED DEFAULT NULL,
  `status` enum('PENDING','IN_PROGRESS','COMPLETED','EXPIRED','CANCELLED') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `priority` enum('LOW','MEDIUM','HIGH','URGENT') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'MEDIUM',
  `deadline` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `result` enum('RENEWED','NOT_RENEWED','OFFER_APPROVED','OFFER_REJECTED','DONE','FAILED') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `completed_by` int UNSIGNED DEFAULT NULL,
  `offer_data` json DEFAULT NULL COMMENT 'Teklif verileri (OFFER tipi gorevler icin)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `task_logs`
--

CREATE TABLE `task_logs` (
  `id` int UNSIGNED NOT NULL,
  `task_id` int UNSIGNED NOT NULL,
  `action` enum('CREATED','ASSIGNED','REASSIGNED','STATUS_CHANGED','COMPLETED','EXPIRED') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_user_id` int UNSIGNED DEFAULT NULL,
  `to_user_id` int UNSIGNED DEFAULT NULL,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `users`
--

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `role` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=kullanici, 1=admin, 2=acente',
  `branch_id` int UNSIGNED DEFAULT NULL,
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `country_id` int UNSIGNED DEFAULT NULL,
  `city_id` int UNSIGNED DEFAULT NULL,
  `district_id` int UNSIGNED DEFAULT NULL,
  `two_factor_secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `two_factor_recovery_codes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dökümü yapılmış tablolar için indeksler
--

--
-- Tablo için indeksler `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_action` (`action`),
  ADD KEY `idx_audit_entity` (`entity`),
  ADD KEY `idx_audit_user` (`user_id`),
  ADD KEY `idx_audit_date` (`created_at`);

--
-- Tablo için indeksler `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_branches_name` (`name`);

--
-- Tablo için indeksler `cities`
--
ALTER TABLE `cities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cities_name` (`name`),
  ADD KEY `idx_cities_country` (`country_id`);

--
-- Tablo için indeksler `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_companies_name` (`name`);

--
-- Tablo için indeksler `countries`
--
ALTER TABLE `countries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_countries_name` (`name`);

--
-- Tablo için indeksler `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cust_name` (`name`),
  ADD KEY `idx_cust_identity` (`identity_no`),
  ADD KEY `idx_cust_phone` (`phone`),
  ADD KEY `idx_cust_type` (`customer_type`);

--
-- Tablo için indeksler `customer_categories`
--
ALTER TABLE `customer_categories`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `districts`
--
ALTER TABLE `districts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_districts_city` (`city_id`),
  ADD KEY `idx_districts_name` (`name`);

--
-- Tablo için indeksler `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_documents_customer_id` (`customer_id`),
  ADD KEY `idx_documents_policy_id` (`policy_id`),
  ADD KEY `idx_documents_storage_hash` (`storage_hash`);

--
-- Tablo için indeksler `insurance_types`
--
ALTER TABLE `insurance_types`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ins_parent` (`parent_id`),
  ADD KEY `idx_ins_active` (`is_active`),
  ADD KEY `idx_ins_group` (`branch_group`),
  ADD KEY `idx_ins_code` (`code`);

--
-- Tablo için indeksler `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_msg_customer` (`customer_id`),
  ADD KEY `idx_msg_channel` (`channel`),
  ADD KEY `idx_msg_status` (`status`);

--
-- Tablo için indeksler `message_templates`
--
ALTER TABLE `message_templates`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_user` (`user_id`),
  ADD KEY `idx_notif_read` (`is_read`),
  ADD KEY `idx_notif_date` (`created_at`);

--
-- Tablo için indeksler `policies`
--
ALTER TABLE `policies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pol_parent` (`parent_id`),
  ADD KEY `idx_pol_customer` (`customer_id`),
  ADD KEY `idx_pol_insurance` (`insurance_type_id`),
  ADD KEY `idx_pol_company` (`company_id`),
  ADD KEY `idx_pol_branch` (`branch_id`),
  ADD KEY `idx_pol_number` (`policy_no`),
  ADD KEY `idx_pol_expires` (`expires_at`),
  ADD KEY `idx_pol_cancelled` (`is_cancelled`),
  ADD KEY `idx_pol_starts` (`starts_at`),
  ADD KEY `idx_pol_created` (`created_at`);

--
-- Tablo için indeksler `reference_sources`
--
ALTER TABLE `reference_sources`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sessions_user` (`user_id`),
  ADD KEY `idx_sessions_token` (`token_hash`),
  ADD KEY `idx_sessions_revoked` (`is_revoked`),
  ADD KEY `idx_sessions_logged` (`logged_in_at`);

--
-- Tablo için indeksler `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_settings_key` (`key`);

--
-- Tablo için indeksler `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tasks_status` (`status`),
  ADD KEY `idx_tasks_type` (`type`),
  ADD KEY `idx_tasks_assigned` (`assigned_to`),
  ADD KEY `idx_tasks_policy` (`policy_id`),
  ADD KEY `idx_tasks_customer` (`customer_id`),
  ADD KEY `idx_tasks_deadline` (`deadline`);

--
-- Tablo için indeksler `task_logs`
--
ALTER TABLE `task_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tl_task` (`task_id`),
  ADD KEY `idx_tl_action` (`action`);

--
-- Tablo için indeksler `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_users_email` (`email`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_branch` (`branch_id`);

--
-- Dökümü yapılmış tablolar için AUTO_INCREMENT değeri
--

--
-- Tablo için AUTO_INCREMENT değeri `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `cities`
--
ALTER TABLE `cities`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `countries`
--
ALTER TABLE `countries`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `customer_categories`
--
ALTER TABLE `customer_categories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `districts`
--
ALTER TABLE `districts`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `insurance_types`
--
ALTER TABLE `insurance_types`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `message_templates`
--
ALTER TABLE `message_templates`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `policies`
--
ALTER TABLE `policies`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `reference_sources`
--
ALTER TABLE `reference_sources`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `task_logs`
--
ALTER TABLE `task_logs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
