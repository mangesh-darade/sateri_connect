-- =====================================================================
-- Sateri Connect (WhatsApp & Omnichannel Automation Platform)
-- Clean Database Creation & Setup Script for Direct Import
-- Generated: 2026-10-06
-- Framework: CodeIgniter 4 | Database Engine: MySQL 8.x / MariaDB 10.x
-- Encoding: UTF-8 Unicode (utf8mb4)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `sateri_connect` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `sateri_connect`;

SET NAMES utf8mb4;
SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT;
SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS;
SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';
SET @OLD_TIME_ZONE=@@TIME_ZONE, TIME_ZONE='+00:00';


-- =====================================================
-- PART 1: TABLE STRUCTURES (41 Tables)
-- =====================================================

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `module` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `action` (`action`),
  KEY `module` (`module`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `activity_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=230 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_copilot_logs`
--

DROP TABLE IF EXISTS `ai_copilot_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_copilot_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `screen` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `page_url` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `prompt` text COLLATE utf8mb4_general_ci,
  `reply` mediumtext COLLATE utf8mb4_general_ci,
  `thinking` text COLLATE utf8mb4_general_ci,
  `action_type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `action_data` text COLLATE utf8mb4_general_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id_is_deleted` (`user_id`,`is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `api_tokens`
--

DROP TABLE IF EXISTS `api_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `token_hash` varchar(191) COLLATE utf8mb4_general_ci NOT NULL,
  `abilities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `user_id` (`user_id`),
  KEY `expires_at` (`expires_at`),
  CONSTRAINT `api_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_delayed_jobs`
--

DROP TABLE IF EXISTS `automation_delayed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_delayed_jobs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `automation_id` int unsigned NOT NULL,
  `contact_id` int unsigned DEFAULT NULL,
  `resume_rule_id` int unsigned DEFAULT NULL,
  `context_json` longtext COLLATE utf8mb4_general_ci,
  `run_at` datetime NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status_run_at` (`status`,`run_at`),
  KEY `automation_id` (`automation_id`)
) ENGINE=MyISAM AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automation_rules`
--

DROP TABLE IF EXISTS `automation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_rules` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `automation_id` int unsigned NOT NULL,
  `step_order` int unsigned NOT NULL DEFAULT '1',
  `rule_type` enum('condition','action') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'action',
  `action_type` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `next_on_true` int unsigned DEFAULT NULL,
  `next_on_false` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `automation_id` (`automation_id`),
  KEY `step_order` (`step_order`),
  KEY `rule_type` (`rule_type`),
  CONSTRAINT `automation_rules_automation_id_foreign` FOREIGN KEY (`automation_id`) REFERENCES `automations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=301 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `automations`
--

DROP TABLE IF EXISTS `automations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_general_ci NOT NULL,
  `trigger_type` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `trigger_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `flow_graph` longtext COLLATE utf8mb4_general_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `priority` int NOT NULL DEFAULT '5',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `trigger_type` (`trigger_type`),
  KEY `is_active` (`is_active`),
  KEY `priority` (`priority`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `automations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=131 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `campaign_contacts`
--

DROP TABLE IF EXISTS `campaign_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `campaign_contacts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` int unsigned NOT NULL,
  `contact_id` int unsigned NOT NULL,
  `status` enum('pending','queued','sent','delivered','read','failed') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `wa_message_id` varchar(191) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_general_ci,
  `sent_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `campaign_id_contact_id` (`campaign_id`,`contact_id`),
  KEY `campaign_id` (`campaign_id`),
  KEY `contact_id` (`contact_id`),
  KEY `status` (`status`),
  KEY `wa_message_id` (`wa_message_id`),
  CONSTRAINT `campaign_contacts_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `campaign_contacts_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `campaigns`
--

DROP TABLE IF EXISTS `campaigns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `campaigns` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_general_ci NOT NULL,
  `template_id` int unsigned DEFAULT NULL,
  `status` enum('draft','scheduled','running','paused','completed','cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'draft',
  `message_type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'template',
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `variables` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `scheduled_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `total_contacts` int unsigned NOT NULL DEFAULT '0',
  `sent_count` int unsigned NOT NULL DEFAULT '0',
  `delivered_count` int unsigned NOT NULL DEFAULT '0',
  `read_count` int unsigned NOT NULL DEFAULT '0',
  `failed_count` int unsigned NOT NULL DEFAULT '0',
  `reply_count` int unsigned NOT NULL DEFAULT '0',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `template_id` (`template_id`),
  KEY `status` (`status`),
  KEY `scheduled_at` (`scheduled_at`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `campaigns_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `campaigns_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `templates` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_attributes`
--

DROP TABLE IF EXISTS `contact_attributes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_attributes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `attr_key` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `label` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'text',
  `options` text COLLATE utf8mb4_general_ci,
  `default_value` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attr_key` (`attr_key`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_tags`
--

DROP TABLE IF EXISTS `contact_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_tags` (
  `contact_id` int unsigned NOT NULL,
  `tag_id` int unsigned NOT NULL,
  PRIMARY KEY (`contact_id`,`tag_id`),
  KEY `contact_tags_tag_id_foreign` (`tag_id`),
  CONSTRAINT `contact_tags_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `contact_tags_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contacts`
--

DROP TABLE IF EXISTS `contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contacts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `channel` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'whatsapp',
  `external_id` varchar(191) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `name` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mobile` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `country` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(191) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `status` enum('active','inactive','blocked') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `last_message_at` datetime DEFAULT NULL,
  `last_reply_at` datetime DEFAULT NULL,
  `assigned_to` int unsigned DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `custom_fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `wa_opted_in` tinyint(1) DEFAULT NULL,
  `wa_opt_in_source` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `wa_opt_in_at` datetime DEFAULT NULL,
  `wa_opted_out` tinyint(1) DEFAULT NULL,
  `wa_opt_out_at` datetime DEFAULT NULL,
  `wa_suppress_reason` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `wa_consent_requested_at` datetime DEFAULT NULL,
  `wa_opt_in` tinyint(1) NOT NULL DEFAULT '0',
  `wa_opted_out_at` datetime DEFAULT NULL,
  `wa_suppressed_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `channel_external_id` (`channel`,`external_id`),
  KEY `status` (`status`),
  KEY `assigned_to` (`assigned_to`),
  KEY `last_message_at` (`last_message_at`),
  KEY `deleted_at` (`deleted_at`),
  KEY `channel` (`channel`),
  KEY `mobile` (`mobile`),
  KEY `contacts_wa_opt_in_idx` (`wa_opt_in`),
  KEY `contacts_wa_opted_out_at_idx` (`wa_opted_out_at`),
  KEY `contacts_wa_suppressed_until_idx` (`wa_suppressed_until`),
  CONSTRAINT `contacts_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10766 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `conversations`
--

DROP TABLE IF EXISTS `conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `conversations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` int unsigned NOT NULL,
  `channel` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'whatsapp',
  `page_id` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_message_id` int unsigned DEFAULT NULL,
  `unread_count` int unsigned NOT NULL DEFAULT '0',
  `assigned_to` int unsigned DEFAULT NULL,
  `status` varchar(32) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'open',
  `last_message_at` datetime DEFAULT NULL,
  `frt_due_at` datetime DEFAULT NULL,
  `intervened_at` datetime DEFAULT NULL,
  `ctwa_referral` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_id` (`contact_id`),
  UNIQUE KEY `contact_channel` (`contact_id`,`channel`),
  KEY `last_message_id` (`last_message_id`),
  KEY `assigned_to` (`assigned_to`),
  KEY `status` (`status`),
  KEY `last_message_at` (`last_message_at`),
  KEY `channel` (`channel`),
  CONSTRAINT `conversations_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `conversations_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `conversations_last_message_id_foreign` FOREIGN KEY (`last_message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `countries`
--

DROP TABLE IF EXISTS `countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `countries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `iso2` varchar(2) COLLATE utf8mb4_general_ci NOT NULL,
  `dial_code` varchar(10) COLLATE utf8mb4_general_ci NOT NULL,
  `min_digits` tinyint unsigned NOT NULL DEFAULT '10',
  `max_digits` tinyint unsigned NOT NULL DEFAULT '10',
  `phone_digits` tinyint unsigned NOT NULL DEFAULT '10',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `iso2` (`iso2`),
  KEY `dial_code` (`dial_code`),
  KEY `is_active_is_deleted` (`is_active`,`is_deleted`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_builders`
--

DROP TABLE IF EXISTS `email_builders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_builders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `html_content` longtext COLLATE utf8mb4_unicode_ci,
  `attachment_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attachment_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cheerio_builder_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('draft','active','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `cheerio_builder_id` (`cheerio_builder_id`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_drip_steps`
--

DROP TABLE IF EXISTS `email_drip_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_drip_steps` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `drip_id` int unsigned NOT NULL,
  `step_order` int NOT NULL DEFAULT '1',
  `delay_hours` int NOT NULL DEFAULT '0',
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `html_content` longtext COLLATE utf8mb4_unicode_ci,
  `builder_id` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email_drip_steps_builder_id_foreign` (`builder_id`),
  KEY `drip_id_step_order` (`drip_id`,`step_order`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_drips`
--

DROP TABLE IF EXISTS `email_drips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_drips` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `trigger_type` enum('manual','on_subscribe','on_tag') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `trigger_value` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('draft','active','paused','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status` (`status`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_html_campaigns`
--

DROP TABLE IF EXISTS `email_html_campaigns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_html_campaigns` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `html_content` longtext COLLATE utf8mb4_unicode_ci,
  `attachment_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attachment_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `builder_id` int unsigned DEFAULT NULL,
  `cheerio_builder_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mode` enum('recipients','label') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'recipients',
  `label_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipients_json` longtext COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','queued','sending','sent','failed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `sent_count` int NOT NULL DEFAULT '0',
  `failed_count` int NOT NULL DEFAULT '0',
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `sent_at` datetime DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email_html_campaigns_builder_id_foreign` (`builder_id`),
  KEY `status` (`status`)
) ENGINE=MyISAM AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_logs`
--

DROP TABLE IF EXISTS `email_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `kind` enum('single','bulk','campaign','drip','test') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single',
  `provider` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('sent','failed','queued') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `opened_at` datetime DEFAULT NULL,
  `open_count` int unsigned DEFAULT '0',
  `clicked_at` datetime DEFAULT NULL,
  `click_count` int unsigned DEFAULT '0',
  `builder_id` int unsigned DEFAULT NULL,
  `html_campaign_id` int unsigned DEFAULT NULL,
  `drip_id` int unsigned DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `meta_json` text COLLATE utf8mb4_unicode_ci,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `kind` (`kind`),
  KEY `created_at` (`created_at`),
  KEY `provider` (`provider`)
) ENGINE=MyISAM AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_senders`
--

DROP TABLE IF EXISTS `email_senders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_senders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('sender','domain') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sender',
  `provider` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domain` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mail_from_domain` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cheerio_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'External Cheerio Sender/Domain ID',
  `status` enum('pending','verified','failed','disabled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `dns_records` text COLLATE utf8mb4_unicode_ci COMMENT 'JSON SPF/DKIM/DMARC notes',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `last_checked_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `type` (`type`),
  KEY `status` (`status`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_unsubscribes`
--

DROP TABLE IF EXISTS `email_unsubscribes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_unsubscribes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(191) COLLATE utf8mb4_general_ci NOT NULL,
  `campaign_id` int unsigned DEFAULT NULL,
  `reason` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email` (`email`),
  KEY `idx_campaign_id` (`campaign_id`),
  KEY `idx_is_deleted` (`is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_recipient_events`
--

DROP TABLE IF EXISTS `email_recipient_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_recipient_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `log_id` int unsigned NOT NULL DEFAULT '0',
  `campaign_id` int unsigned DEFAULT NULL,
  `email` varchar(191) COLLATE utf8mb4_general_ci NOT NULL,
  `event_type` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `detail` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `message_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `event_count` int unsigned NOT NULL DEFAULT '1',
  `first_at` datetime DEFAULT NULL,
  `last_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_log_email_event` (`log_id`,`email`,`event_type`),
  KEY `campaign_id` (`campaign_id`),
  KEY `email_event_type` (`email`,`event_type`),
  KEY `message_id` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_verifications`
--

DROP TABLE IF EXISTS `email_verifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_verifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('valid','invalid','risky','unknown') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `syntax_ok` tinyint(1) NOT NULL DEFAULT '0',
  `mx_ok` tinyint(1) NOT NULL DEFAULT '0',
  `disposable` tinyint(1) NOT NULL DEFAULT '0',
  `checks_json` text COLLATE utf8mb4_unicode_ci,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email` (`email`),
  KEY `status` (`status`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `internal_notes`
--

DROP TABLE IF EXISTS `internal_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `internal_notes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `note` text COLLATE utf8mb4_general_ci NOT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_id` (`contact_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `internal_notes_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `internal_notes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `keywords`
--

DROP TABLE IF EXISTS `keywords`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `keywords` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `keyword` varchar(191) COLLATE utf8mb4_general_ci NOT NULL,
  `match_type` enum('exact','contains','starts_with') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'exact',
  `response_type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'text',
  `response_content` text COLLATE utf8mb4_general_ci,
  `response_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `parent_id` int unsigned DEFAULT NULL,
  `menu_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `keyword` (`keyword`),
  KEY `match_type` (`match_type`),
  KEY `parent_id` (`parent_id`),
  KEY `is_active` (`is_active`),
  CONSTRAINT `keywords_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `keywords` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `media`
--

DROP TABLE IF EXISTS `media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `media` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `size` bigint unsigned NOT NULL DEFAULT '0',
  `path` varchar(500) COLLATE utf8mb4_general_ci NOT NULL,
  `wa_media_id` varchar(191) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `url` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `uploaded_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `wa_media_id` (`wa_media_id`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `media_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `message_queue`
--

DROP TABLE IF EXISTS `message_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_queue` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` int unsigned DEFAULT NULL,
  `contact_id` int unsigned NOT NULL,
  `message_type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `priority` int NOT NULL DEFAULT '5',
  `status` enum('pending','processing','sent','failed','cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `max_attempts` int unsigned NOT NULL DEFAULT '3',
  `scheduled_at` datetime DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `wa_message_id` varchar(191) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_general_ci,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `campaign_id` (`campaign_id`),
  KEY `contact_id` (`contact_id`),
  KEY `status` (`status`),
  KEY `priority` (`priority`),
  KEY `scheduled_at` (`scheduled_at`),
  CONSTRAINT `message_queue_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `message_queue_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=304 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `message_sequences`
--

DROP TABLE IF EXISTS `message_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_sequences` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_general_ci NOT NULL,
  `channel` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'whatsapp',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `exit_on_reply` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` int unsigned NOT NULL,
  `campaign_id` int unsigned DEFAULT NULL,
  `direction` enum('inbound','outbound') COLLATE utf8mb4_general_ci NOT NULL,
  `message_type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'text',
  `wa_message_id` varchar(191) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `wamid` varchar(191) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `external_message_id` varchar(191) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `content` text COLLATE utf8mb4_general_ci,
  `media_url` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `media_id` varchar(191) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `status` enum('pending','sent','delivered','read','failed','received') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `error_code` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_general_ci,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `conversation_id` int unsigned DEFAULT NULL,
  `channel` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'whatsapp',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_id` (`contact_id`),
  KEY `campaign_id` (`campaign_id`),
  KEY `direction` (`direction`),
  KEY `wa_message_id` (`wa_message_id`),
  KEY `wamid` (`wamid`),
  KEY `status` (`status`),
  KEY `conversation_id` (`conversation_id`),
  KEY `created_at` (`created_at`),
  KEY `channel` (`channel`),
  KEY `external_message_id` (`external_message_id`),
  CONSTRAINT `messages_campaign_id_foreign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `messages_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=394 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `class` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `group` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `namespace` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `time` int NOT NULL,
  `batch` int unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `message` text COLLATE utf8mb4_general_ci,
  `type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'info',
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `link` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `is_read` (`is_read`),
  KEY `type` (`type`),
  CONSTRAINT `notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=314 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `email` varchar(191) COLLATE utf8mb4_general_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime DEFAULT NULL,
  KEY `email` (`email`),
  KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `slug` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `module` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `module` (`module`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `quick_replies`
--

DROP TABLE IF EXISTS `quick_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quick_replies` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `shortcut` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `title` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `message` text COLLATE utf8mb4_general_ci NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shortcut` (`shortcut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rate_limits`
--

DROP TABLE IF EXISTS `rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rate_limits` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(191) COLLATE utf8mb4_general_ci NOT NULL,
  `hits` int unsigned NOT NULL DEFAULT '0',
  `window_start` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`),
  KEY `window_start` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `role_id` int unsigned NOT NULL,
  `permission_id` int unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `role_permissions_permission_id_foreign` (`permission_id`),
  CONSTRAINT `role_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `role_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `slug` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sequence_enrollments`
--

DROP TABLE IF EXISTS `sequence_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sequence_enrollments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sequence_id` int unsigned NOT NULL,
  `contact_id` int unsigned NOT NULL,
  `current_step` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `next_run_at` datetime DEFAULT NULL,
  `last_sent_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sequence_id_contact_id` (`sequence_id`,`contact_id`),
  KEY `status_next_run_at` (`status`,`next_run_at`)
) ENGINE=MyISAM AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sequence_steps`
--

DROP TABLE IF EXISTS `sequence_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sequence_steps` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sequence_id` int unsigned NOT NULL,
  `step_order` int unsigned NOT NULL DEFAULT '1',
  `delay_minutes` int unsigned NOT NULL DEFAULT '0',
  `message_type` varchar(30) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'text',
  `template_name` varchar(191) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `language` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'en',
  `body_text` text COLLATE utf8mb4_general_ci,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sequence_id_step_order` (`sequence_id`,`step_order`)
) ENGINE=MyISAM AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(191) COLLATE utf8mb4_general_ci NOT NULL,
  `value` text COLLATE utf8mb4_general_ci,
  `group` varchar(100) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'general',
  `is_encrypted` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`),
  KEY `group` (`group`)
) ENGINE=InnoDB AUTO_INCREMENT=139 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tags`
--

DROP TABLE IF EXISTS `tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tags` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `color` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT '#6B7280',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `templates`
--

DROP TABLE IF EXISTS `templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `waba_id` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `meta_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `name` varchar(191) COLLATE utf8mb4_general_ci NOT NULL,
  `language` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'en',
  `category` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `template_type` varchar(30) COLLATE utf8mb4_general_ci DEFAULT 'default',
  `status` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'PENDING',
  `rejected_reason` text COLLATE utf8mb4_general_ci,
  `header_type` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `header_content` text COLLATE utf8mb4_general_ci,
  `body` text COLLATE utf8mb4_general_ci,
  `footer` text COLLATE utf8mb4_general_ci,
  `buttons` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `variables` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `raw_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `synced_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `templates_waba_meta_unique` (`waba_id`,`meta_id`),
  UNIQUE KEY `templates_waba_name_lang_unique` (`waba_id`,`name`,`language`),
  KEY `meta_id` (`meta_id`),
  KEY `name` (`name`),
  KEY `status` (`status`),
  KEY `templates_waba_id_idx` (`waba_id`)
) ENGINE=InnoDB AUTO_INCREMENT=142 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int unsigned NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `email_verification_token` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email_verification_sent_at` datetime DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `phone` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `avatar` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `users_email_verification_token_unique` (`email_verification_token`),
  KEY `role_id` (`role_id`),
  KEY `status` (`status`),
  KEY `deleted_at` (`deleted_at`),
  KEY `users_email_verified_at_index` (`email_verified_at`),
  CONSTRAINT `users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `webhook_logs`
--

DROP TABLE IF EXISTS `webhook_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `payload` longtext COLLATE utf8mb4_general_ci,
  `headers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `signature_valid` tinyint(1) NOT NULL DEFAULT '0',
  `processed` tinyint(1) NOT NULL DEFAULT '0',
  `error_message` text COLLATE utf8mb4_general_ci,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `event_type` (`event_type`),
  KEY `processed` (`processed`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=791 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- =====================================================
-- PART 2: MASTER SEED DATA (Roles, Perms, Users, Countries, Settings, Tags, Migrations)
-- =====================================================

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Super Admin','super-admin','Full system access with unrestricted privileges.','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `roles` VALUES (2,'Admin','admin','Administrative access to manage platform operations.','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `roles` VALUES (3,'Manager','manager','Manages campaigns, contacts, chat, and reports.','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `roles` VALUES (4,'Agent','agent','Handles chat conversations and views contacts/campaigns.','2026-07-25 07:59:43','2026-07-25 07:59:43');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'View Dashboard','dashboard.view','dashboard','Access the main dashboard','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (2,'View Contacts','contacts.view','contacts','View contact list and details','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (3,'Create Contacts','contacts.create','contacts','Create new contacts','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (4,'Edit Contacts','contacts.edit','contacts','Edit existing contacts','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (5,'Delete Contacts','contacts.delete','contacts','Delete contacts','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (6,'Import Contacts','contacts.import','contacts','Import contacts from CSV/Excel','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (7,'Export Contacts','contacts.export','contacts','Export contacts','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (8,'View Campaigns','campaigns.view','campaigns','View campaigns','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (9,'Create Campaigns','campaigns.create','campaigns','Create new campaigns','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (10,'Edit Campaigns','campaigns.edit','campaigns','Edit campaigns','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (11,'Delete Campaigns','campaigns.delete','campaigns','Delete campaigns','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (12,'Start Campaigns','campaigns.start','campaigns','Start, pause, or cancel campaigns','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (13,'View Templates','templates.view','templates','View message templates','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (14,'Create Templates','templates.create','templates','Create templates','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (15,'Edit Templates','templates.edit','templates','Edit templates','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (16,'Delete Templates','templates.delete','templates','Delete templates','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (17,'Sync Templates','templates.sync','templates','Sync templates from Cheerio','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (18,'View Chat','chat.view','chat','Access inbox and conversations','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (19,'Send Messages','chat.send','chat','Send chat messages','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (20,'Assign Conversations','chat.assign','chat','Assign conversations to agents','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (21,'Close Conversations','chat.close','chat','Close conversations','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (22,'View Automations','automations.view','automations','View automations','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (23,'Create Automations','automations.create','automations','Create automations','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (24,'Edit Automations','automations.edit','automations','Edit automations','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (25,'Delete Automations','automations.delete','automations','Delete automations','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (26,'View Keywords','keywords.view','keywords','View keyword replies','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (27,'Create Keywords','keywords.create','keywords','Create keyword replies','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (28,'Edit Keywords','keywords.edit','keywords','Edit keyword replies','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (29,'Delete Keywords','keywords.delete','keywords','Delete keyword replies','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (30,'View Reports','reports.view','reports','View reports and analytics','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (31,'Export Reports','reports.export','reports','Export reports','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (32,'View Settings','settings.view','settings','View application settings','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (33,'Edit Settings','settings.edit','settings','Update application settings','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (34,'View Users','users.view','users','View users','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (35,'Create Users','users.create','users','Create users','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (36,'Edit Users','users.edit','users','Edit users','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (37,'Delete Users','users.delete','users','Delete users','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (38,'View Roles','roles.view','roles','View roles and permissions','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (39,'Create Roles','roles.create','roles','Create roles','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (40,'Edit Roles','roles.edit','roles','Edit roles and assign permissions','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (41,'Delete Roles','roles.delete','roles','Delete roles','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (42,'View Queue','queue.view','queue','View message queue','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (43,'Manage Queue','queue.manage','queue','Retry, cancel, or clear queue items','2026-07-25 07:59:43','2026-07-25 07:59:43');
INSERT INTO `permissions` VALUES (44,'View Emails','emails.view','emails','Access email compose screens','2026-07-27 13:12:00','2026-07-27 13:12:00');
INSERT INTO `permissions` VALUES (45,'Send Emails','emails.send','emails','Send single and bulk emails','2026-07-27 13:12:00','2026-07-27 13:12:00');
INSERT INTO `permissions` VALUES (46,'View Sequences','sequences.view','sequences','View message sequences','2026-07-29 04:12:14','2026-07-29 04:12:14');
INSERT INTO `permissions` VALUES (47,'Create Sequences','sequences.create','sequences','Create message sequences','2026-07-29 04:12:14','2026-07-29 04:12:14');
INSERT INTO `permissions` VALUES (48,'Edit Sequences','sequences.edit','sequences','Edit sequences and enroll contacts','2026-07-29 04:12:14','2026-07-29 04:12:14');
INSERT INTO `permissions` VALUES (49,'Delete Sequences','sequences.delete','sequences','Delete message sequences','2026-07-29 04:12:14','2026-07-29 04:12:14');
INSERT INTO `permissions` VALUES (50,'View Guides','guide.view','guide','View setup and product guides','2026-07-29 04:12:14','2026-07-29 04:12:14');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES (1,1);
INSERT INTO `role_permissions` VALUES (2,1);
INSERT INTO `role_permissions` VALUES (3,1);
INSERT INTO `role_permissions` VALUES (4,1);
INSERT INTO `role_permissions` VALUES (1,2);
INSERT INTO `role_permissions` VALUES (2,2);
INSERT INTO `role_permissions` VALUES (3,2);
INSERT INTO `role_permissions` VALUES (4,2);
INSERT INTO `role_permissions` VALUES (1,3);
INSERT INTO `role_permissions` VALUES (2,3);
INSERT INTO `role_permissions` VALUES (3,3);
INSERT INTO `role_permissions` VALUES (1,4);
INSERT INTO `role_permissions` VALUES (2,4);
INSERT INTO `role_permissions` VALUES (3,4);
INSERT INTO `role_permissions` VALUES (1,5);
INSERT INTO `role_permissions` VALUES (2,5);
INSERT INTO `role_permissions` VALUES (3,5);
INSERT INTO `role_permissions` VALUES (1,6);
INSERT INTO `role_permissions` VALUES (2,6);
INSERT INTO `role_permissions` VALUES (3,6);
INSERT INTO `role_permissions` VALUES (1,7);
INSERT INTO `role_permissions` VALUES (2,7);
INSERT INTO `role_permissions` VALUES (3,7);
INSERT INTO `role_permissions` VALUES (1,8);
INSERT INTO `role_permissions` VALUES (2,8);
INSERT INTO `role_permissions` VALUES (3,8);
INSERT INTO `role_permissions` VALUES (4,8);
INSERT INTO `role_permissions` VALUES (1,9);
INSERT INTO `role_permissions` VALUES (2,9);
INSERT INTO `role_permissions` VALUES (3,9);
INSERT INTO `role_permissions` VALUES (1,10);
INSERT INTO `role_permissions` VALUES (2,10);
INSERT INTO `role_permissions` VALUES (3,10);
INSERT INTO `role_permissions` VALUES (1,11);
INSERT INTO `role_permissions` VALUES (2,11);
INSERT INTO `role_permissions` VALUES (3,11);
INSERT INTO `role_permissions` VALUES (1,12);
INSERT INTO `role_permissions` VALUES (2,12);
INSERT INTO `role_permissions` VALUES (3,12);
INSERT INTO `role_permissions` VALUES (1,13);
INSERT INTO `role_permissions` VALUES (2,13);
INSERT INTO `role_permissions` VALUES (3,13);
INSERT INTO `role_permissions` VALUES (4,13);
INSERT INTO `role_permissions` VALUES (1,14);
INSERT INTO `role_permissions` VALUES (2,14);
INSERT INTO `role_permissions` VALUES (3,14);
INSERT INTO `role_permissions` VALUES (1,15);
INSERT INTO `role_permissions` VALUES (2,15);
INSERT INTO `role_permissions` VALUES (3,15);
INSERT INTO `role_permissions` VALUES (1,16);
INSERT INTO `role_permissions` VALUES (2,16);
INSERT INTO `role_permissions` VALUES (3,16);
INSERT INTO `role_permissions` VALUES (1,17);
INSERT INTO `role_permissions` VALUES (2,17);
INSERT INTO `role_permissions` VALUES (3,17);
INSERT INTO `role_permissions` VALUES (1,18);
INSERT INTO `role_permissions` VALUES (2,18);
INSERT INTO `role_permissions` VALUES (3,18);
INSERT INTO `role_permissions` VALUES (4,18);
INSERT INTO `role_permissions` VALUES (1,19);
INSERT INTO `role_permissions` VALUES (2,19);
INSERT INTO `role_permissions` VALUES (3,19);
INSERT INTO `role_permissions` VALUES (4,19);
INSERT INTO `role_permissions` VALUES (1,20);
INSERT INTO `role_permissions` VALUES (2,20);
INSERT INTO `role_permissions` VALUES (3,20);
INSERT INTO `role_permissions` VALUES (1,21);
INSERT INTO `role_permissions` VALUES (2,21);
INSERT INTO `role_permissions` VALUES (3,21);
INSERT INTO `role_permissions` VALUES (4,21);
INSERT INTO `role_permissions` VALUES (1,22);
INSERT INTO `role_permissions` VALUES (2,22);
INSERT INTO `role_permissions` VALUES (3,22);
INSERT INTO `role_permissions` VALUES (4,22);
INSERT INTO `role_permissions` VALUES (1,23);
INSERT INTO `role_permissions` VALUES (2,23);
INSERT INTO `role_permissions` VALUES (3,23);
INSERT INTO `role_permissions` VALUES (1,24);
INSERT INTO `role_permissions` VALUES (2,24);
INSERT INTO `role_permissions` VALUES (3,24);
INSERT INTO `role_permissions` VALUES (1,25);
INSERT INTO `role_permissions` VALUES (2,25);
INSERT INTO `role_permissions` VALUES (3,25);
INSERT INTO `role_permissions` VALUES (1,26);
INSERT INTO `role_permissions` VALUES (2,26);
INSERT INTO `role_permissions` VALUES (3,26);
INSERT INTO `role_permissions` VALUES (4,26);
INSERT INTO `role_permissions` VALUES (1,27);
INSERT INTO `role_permissions` VALUES (2,27);
INSERT INTO `role_permissions` VALUES (3,27);
INSERT INTO `role_permissions` VALUES (1,28);
INSERT INTO `role_permissions` VALUES (2,28);
INSERT INTO `role_permissions` VALUES (3,28);
INSERT INTO `role_permissions` VALUES (1,29);
INSERT INTO `role_permissions` VALUES (2,29);
INSERT INTO `role_permissions` VALUES (3,29);
INSERT INTO `role_permissions` VALUES (1,30);
INSERT INTO `role_permissions` VALUES (2,30);
INSERT INTO `role_permissions` VALUES (3,30);
INSERT INTO `role_permissions` VALUES (4,30);
INSERT INTO `role_permissions` VALUES (1,31);
INSERT INTO `role_permissions` VALUES (2,31);
INSERT INTO `role_permissions` VALUES (3,31);
INSERT INTO `role_permissions` VALUES (1,32);
INSERT INTO `role_permissions` VALUES (2,32);
INSERT INTO `role_permissions` VALUES (3,32);
INSERT INTO `role_permissions` VALUES (1,33);
INSERT INTO `role_permissions` VALUES (2,33);
INSERT INTO `role_permissions` VALUES (1,34);
INSERT INTO `role_permissions` VALUES (2,34);
INSERT INTO `role_permissions` VALUES (1,35);
INSERT INTO `role_permissions` VALUES (2,35);
INSERT INTO `role_permissions` VALUES (1,36);
INSERT INTO `role_permissions` VALUES (2,36);
INSERT INTO `role_permissions` VALUES (1,37);
INSERT INTO `role_permissions` VALUES (2,37);
INSERT INTO `role_permissions` VALUES (1,38);
INSERT INTO `role_permissions` VALUES (2,38);
INSERT INTO `role_permissions` VALUES (1,39);
INSERT INTO `role_permissions` VALUES (2,39);
INSERT INTO `role_permissions` VALUES (1,40);
INSERT INTO `role_permissions` VALUES (2,40);
INSERT INTO `role_permissions` VALUES (1,41);
INSERT INTO `role_permissions` VALUES (2,41);
INSERT INTO `role_permissions` VALUES (1,42);
INSERT INTO `role_permissions` VALUES (2,42);
INSERT INTO `role_permissions` VALUES (3,42);
INSERT INTO `role_permissions` VALUES (1,43);
INSERT INTO `role_permissions` VALUES (2,43);
INSERT INTO `role_permissions` VALUES (3,43);
INSERT INTO `role_permissions` VALUES (1,44);
INSERT INTO `role_permissions` VALUES (2,44);
INSERT INTO `role_permissions` VALUES (3,44);
INSERT INTO `role_permissions` VALUES (4,44);
INSERT INTO `role_permissions` VALUES (1,45);
INSERT INTO `role_permissions` VALUES (2,45);
INSERT INTO `role_permissions` VALUES (3,45);
INSERT INTO `role_permissions` VALUES (1,46);
INSERT INTO `role_permissions` VALUES (2,46);
INSERT INTO `role_permissions` VALUES (3,46);
INSERT INTO `role_permissions` VALUES (4,46);
INSERT INTO `role_permissions` VALUES (1,47);
INSERT INTO `role_permissions` VALUES (2,47);
INSERT INTO `role_permissions` VALUES (3,47);
INSERT INTO `role_permissions` VALUES (1,48);
INSERT INTO `role_permissions` VALUES (2,48);
INSERT INTO `role_permissions` VALUES (3,48);
INSERT INTO `role_permissions` VALUES (1,49);
INSERT INTO `role_permissions` VALUES (2,49);
INSERT INTO `role_permissions` VALUES (3,49);
INSERT INTO `role_permissions` VALUES (1,50);
INSERT INTO `role_permissions` VALUES (2,50);
INSERT INTO `role_permissions` VALUES (3,50);
INSERT INTO `role_permissions` VALUES (4,50);
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,1,'Super Admin','sateri.mangesh1@gmail.com','66580335d547704068d335fa3d38fadce0ba9180',NULL,NULL,'2026-07-25 13:29:44',NULL,NULL,'active','2026-10-01 12:25:04',NULL,'2026-07-25 13:29:44','2026-10-01 12:25:04',NULL);
INSERT INTO `users` VALUES (9,1,'Admin','admin@gmail.com','$2y$12$BO5Qa3ZA0pB1XAuVXaH6YOlYNg8xKxNKZ7wJdNbQPGqQ/eOFxPyNO',NULL,NULL,'2026-10-06 09:06:58',NULL,NULL,'active',NULL,NULL,'2026-10-06 09:06:58','2026-10-06 09:06:58',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `countries`
--

LOCK TABLES `countries` WRITE;
/*!40000 ALTER TABLE `countries` DISABLE KEYS */;
INSERT INTO `countries` VALUES (1,'India','IN','91',10,10,10,1,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (2,'United States','US','1',10,10,10,2,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (3,'United Kingdom','GB','44',10,11,10,3,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (4,'United Arab Emirates','AE','971',9,9,9,4,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (5,'Saudi Arabia','SA','966',9,9,9,5,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (6,'Canada','CA','1',10,10,10,6,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (7,'Australia','AU','61',9,9,9,7,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (8,'Singapore','SG','65',8,8,8,8,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (9,'Qatar','QA','974',8,8,8,9,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (10,'Kuwait','KW','965',8,8,8,10,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (11,'Oman','OM','968',8,8,8,11,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (12,'Bahrain','BH','973',8,8,8,12,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (13,'Malaysia','MY','60',9,10,10,13,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (14,'Germany','DE','49',10,11,10,14,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (15,'France','FR','33',9,9,9,15,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (16,'Italy','IT','39',10,10,10,16,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (17,'Bangladesh','BD','880',10,10,10,17,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (18,'Pakistan','PK','92',10,10,10,18,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (19,'Sri Lanka','LK','94',9,9,9,19,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (20,'Nepal','NP','977',10,10,10,20,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (21,'South Africa','ZA','27',9,9,9,21,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (22,'Philippines','PH','63',10,10,10,22,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (23,'Indonesia','ID','62',9,12,10,23,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (24,'New Zealand','NZ','64',8,10,9,24,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (25,'China','CN','86',11,11,11,25,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (26,'Japan','JP','81',10,10,10,26,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (27,'Brazil','BR','55',10,11,11,27,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (28,'Spain','ES','34',9,9,9,28,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (29,'Netherlands','NL','31',9,9,9,29,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (30,'Switzerland','CH','41',9,9,9,30,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (31,'Nigeria','NG','234',10,10,10,31,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (32,'Kenya','KE','254',9,9,9,32,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (33,'Egypt','EG','20',10,10,10,33,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (34,'Turkey','TR','90',10,10,10,34,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (35,'Thailand','TH','66',9,9,9,35,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (36,'Vietnam','VN','84',9,9,9,36,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (37,'Russia','RU','7',10,10,10,37,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (38,'Ireland','IE','353',9,9,9,38,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (39,'Hong Kong','HK','852',8,8,8,39,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
INSERT INTO `countries` VALUES (40,'Sweden','SE','46',9,9,9,40,1,0,'2026-10-06 12:40:07','2026-10-06 12:40:07');
/*!40000 ALTER TABLE `countries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (69,'cheerio_webhook_verify_token','whstapp_7bbea4643acd3bc5016d9206','cheerio',0,'2026-08-01 06:21:10','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (70,'webhook_public_base','https://demoelintommetaapi.elintpos.in','whatsapp',0,'2026-08-01 06:21:10','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (71,'whatsapp_provider','meta','whatsapp',0,'2026-08-01 06:21:12','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (72,'email_provider','ses','email',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (73,'cheerio_email_campaign_name','app-direct','email',0,'2026-08-01 06:22:39','2026-10-06 11:41:38');
INSERT INTO `settings` VALUES (74,'meta_access_token','enc:8hTWiXIb/ZJMfr3WrwAuXWwqeffUjkpQ3qME0kmI/L7fSrJxBS/kQ2PCj9Kic6PbBjV217+0hOoRMMonj9AEfxwlpBkuT5pxkeqh2L3wvkFSYXfRd4Kcg55Pw1JyqPUGoYsecd8ogTQ9lpwqjdKl+bLr8ViB8h7sK3WQr65+//Ymf3V0cnJa420BlZ7jssc72WrNzjrQscKOaZfX3pXy8vb2agixKu9UFG2YhcrEIg3arukeA20TiJQkR5Myus4wedJvMA9nE8JRB/DQh3IlNKB61V16K42mIYPhw3KuTpZgxRADcw0JlNxrV0xAW6OIsRCmbuhg9aNJUS9OYvwbvUiPLFaBx0PvBBPJA6BWk+NvpJ4N1xALtqLQ4KE=','meta',1,'2026-08-01 06:22:39','2026-08-01 06:22:39');
INSERT INTO `settings` VALUES (75,'meta_phone_number_id','1271937152662298','meta',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (76,'meta_waba_id','2224778918307465','meta',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (77,'meta_webhook_secret','enc:6hdTja380hKJdtUuzUsUl30MS0IYE9IHSBOt+rZCAp+CpKABbjdpbSbnStg8bUPoehWrBq6VylP9M6UahGNxhvTZONjt9C9AtRdxNMGHo7YQ2SD1i4tbL4K1oyvaBzdtsQxlh9mc8hRK5FEBFOinEA==','meta',1,'2026-08-01 06:22:39','2026-08-01 06:22:39');
INSERT INTO `settings` VALUES (78,'meta_app_id','1389328076628792','meta',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (79,'meta_embedded_config_id','27628410963477907','meta',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (80,'meta_api_version','v21.0','meta',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (81,'inbox_instagram_enabled','0','meta',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (82,'inbox_messenger_enabled','0','meta',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (83,'app_name','swasthe_testing2','general',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (84,'app_tagline','Automation console','general',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (85,'app_timezone','Asia/Kolkata','general',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (86,'timezone','Asia/Kolkata','general',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (87,'app_email','','general',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (88,'app_url','https://demoelintommetaapi.elintpos.in/index.php','general',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (89,'smtp_host','','smtp',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (90,'smtp_port','587','smtp',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (91,'smtp_user','','smtp',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (92,'smtp_encryption','tls','smtp',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (93,'smtp_from_email','','smtp',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (94,'smtp_from_name','','smtp',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (95,'meta_webhook_verify_token','whstapp_28a66de187db406aeea50bc3','meta',0,'2026-08-01 06:22:39','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (96,'wa_display_name','swasthe_testing2','whatsapp',0,'2026-08-16 12:18:10','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (97,'meta_verified_name','swasthe_testing2','meta',0,'2026-08-16 12:18:10','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (98,'wa_display_phone','917709930738','whatsapp',0,'2026-08-16 12:18:10','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (99,'meta_display_phone','917709930738','meta',0,'2026-08-16 12:18:10','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (100,'wa_identity_fetched_at','1791275794','whatsapp',0,'2026-08-16 12:18:10','2026-10-06 08:36:34');
INSERT INTO `settings` VALUES (101,'cheerio_api_key','','cheerio',1,'2026-08-16 13:19:23','2026-08-16 13:19:23');
INSERT INTO `settings` VALUES (102,'cheerio_webhook_secret','','cheerio',1,'2026-08-16 13:19:23','2026-08-16 13:19:23');
INSERT INTO `settings` VALUES (103,'meta_business_id','','meta',0,'2026-08-16 13:19:23','2026-10-06 09:04:25');
INSERT INTO `settings` VALUES (104,'meta_two_step_pin','','meta',1,'2026-08-16 13:19:23','2026-08-16 13:19:23');
INSERT INTO `settings` VALUES (105,'site_logo','','general',0,'2026-08-16 13:19:23','2026-08-16 13:19:23');
INSERT INTO `settings` VALUES (106,'site_favicon','','general',0,'2026-08-16 13:19:23','2026-08-16 13:19:23');
INSERT INTO `settings` VALUES (107,'smtp_pass','','smtp',1,'2026-08-16 13:19:23','2026-08-16 13:19:23');
INSERT INTO `settings` VALUES (108,'sendgrid_api_key','','email',1,'2026-08-16 13:19:23','2026-08-16 13:19:23');
INSERT INTO `settings` VALUES (109,'sendgrid_from_email','','email',0,'2026-08-16 13:19:23','2026-08-16 13:19:23');
INSERT INTO `settings` VALUES (110,'sendgrid_from_name','WhatsApp Automation Platform','email',0,'2026-08-16 13:19:23','2026-10-05 05:56:48');
INSERT INTO `settings` VALUES (111,'sendgrid_sender_id','','email',0,'2026-08-16 13:19:23','2026-08-16 13:19:23');
INSERT INTO `settings` VALUES (112,'sendgrid_suppression_group_id','','email',0,'2026-08-16 13:19:23','2026-08-16 13:19:23');
INSERT INTO `settings` VALUES (113,'sendgrid_custom_unsubscribe_url','','email',0,'2026-08-16 13:19:23','2026-08-16 13:19:23');
INSERT INTO `settings` VALUES (114,'sendgrid_ip_pool','','email',0,'2026-08-16 13:19:23','2026-08-16 13:19:23');
INSERT INTO `settings` VALUES (115,'app_installed','0','general',0,'2026-08-16 13:19:23','2026-08-16 13:19:23');
INSERT INTO `settings` VALUES (116,'elintom_base_url','https://devdinein.elintpos.in','elintom',0,'2026-08-16 15:36:28','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (117,'elintom_api_private_key','enc:dsXncr0FpVFD9Mr3N+zyRtMD7NeCRZ0Ag9ygOiZbCz2jfmsU8E6XjIpoK0xwQgwJSfdOwBBrVHX4K+82dD9/heKlRyBx3zO+PveU1XGfTfmNGVRMC1ctwSBg8Hdo4RQxMN9R8Rr+gC2AF9laUFJJGw==','elintom',1,'2026-08-16 15:36:28','2026-08-16 15:36:28');
INSERT INTO `settings` VALUES (118,'powered_by_name','','general',0,'2026-10-05 05:50:44','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (119,'powered_by_url','','general',0,'2026-10-05 05:50:44','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (120,'powered_by_enabled','1','general',0,'2026-10-05 05:50:44','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (121,'ai_enabled','0','ai',0,'2026-10-05 05:50:44','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (122,'ai_provider','gemini','ai',0,'2026-10-05 05:50:44','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (123,'ai_model','gemini-flash-latest','ai',0,'2026-10-05 05:50:44','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (124,'ai_system_prompt','You are an official, polite WhatsApp AI assistant for our business.\r\nAnswer customer questions concisely in English, Marathi, or Hindi based on the language they use.\r\nKeep answers within 2-3 sentences max.','ai',0,'2026-10-05 05:50:44','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (125,'ai_business_name','Swasthe','ai',0,'2026-10-05 05:50:44','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (126,'ai_max_consecutive_replies','3','ai',0,'2026-10-05 05:50:44','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (127,'ai_cooldown_seconds','3','ai',0,'2026-10-05 05:50:44','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (128,'ai_human_keywords','human,agent,support,representative,manus,madat,manushya,call','ai',0,'2026-10-05 05:50:44','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (129,'cheerio_phone_number_id','1271937152662298','cheerio',0,'2026-10-05 05:52:51','2026-10-05 05:52:51');
INSERT INTO `settings` VALUES (130,'cheerio_display_phone','917709930738','cheerio',0,'2026-10-05 05:52:51','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (131,'wa_quality_checked_at','2026-10-06 07:39:17','whatsapp',0,'2026-10-05 06:03:30','2026-10-06 07:39:17');
INSERT INTO `settings` VALUES (132,'wa_messaging_limit','TIER_2K','whatsapp',0,'2026-10-05 06:03:31','2026-10-06 07:39:18');
INSERT INTO `settings` VALUES (133,'wa_quality_rating','GREEN','whatsapp',0,'2026-10-05 06:03:31','2026-10-06 07:39:18');
INSERT INTO `settings` VALUES (134,'ses_access_key','AKIAXP2MZNCQ7PGFAP3S','email',0,'2026-10-06 08:11:45','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (135,'ses_secret_key','enc:5HqItQDR5dW8PRUT8+udvj1YnD+87TUf2ORPriDIAk60XEk6qxeS7IeGcT9cgcRE+PbodILzYMr6DiQy+qr5F59R2/wRVSjaGYm/sju8lb0vwaFwSFpB/nlL4tgKfDgPEby4MImxTmOeaa37xOtUKTLX9QmkGOEY','email',1,'2026-10-06 08:11:45','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (136,'ses_region','us-east-1','email',0,'2026-10-06 08:11:45','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (137,'ses_from_email','marketing@elintom.in','email',0,'2026-10-06 08:11:45','2026-10-06 09:08:03');
INSERT INTO `settings` VALUES (138,'ses_from_name','Elintom Marketing','email',0,'2026-10-06 08:11:45','2026-10-06 09:08:03');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `tags`
--

LOCK TABLES `tags` WRITE;
/*!40000 ALTER TABLE `tags` DISABLE KEYS */;
INSERT INTO `tags` VALUES (45,'office','#6B7280','2026-10-05 06:40:58','2026-10-05 06:40:58');
INSERT INTO `tags` VALUES (46,'Test','#6B7280','2026-10-05 11:22:13','2026-10-05 11:22:13');
INSERT INTO `tags` VALUES (47,'testing2','#6B7280','2026-10-06 07:36:58','2026-10-06 07:36:58');
/*!40000 ALTER TABLE `tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `templates`
--

LOCK TABLES `templates` WRITE;
/*!40000 ALTER TABLE `templates` DISABLE KEYS */;
INSERT INTO `templates` VALUES (128,'2224778918307465','1570690461452883','mangesh_darade22','en_US','MARKETING','default','APPROVED',NULL,'image','https://scontent.whatsapp.net/v/t61.29466-34/620375329_1570690464786216_8859512334761623389_n.png?ccb=1-7&_nc_sid=8b1bef&_nc_ohc=XpZqbAhho-MQ7kNvwFYQzJa&_nc_oc=AdoNLGduJK3GKR_NNCObrhIIEZL86A4JsoXxDxGfQ4LCQ7jQ6DQLkzw4p-UwA446zro&_nc_zt=3&_nc_ht=scontent.whatsapp.net&edm=AH51TzQEAAAA&_nc_gid=0O5IDvrg_AiSUD3w2sdlCw&_nc_tpa=Q5bMBQI6_E_T-gSrBNVLkhWdJVIynM0uNHEpmUIkaGE9scG31UZiJQwVUgrbFuEaSdsNlEnipuzDNX4-pQ&oh=01_Q5Aa5wHgb5YfRXqczCLqJACqaU4wWPH8BT80GvtUtxV_JY6B-A&oe=6AEC2771','hi {{1}} please check this link',NULL,NULL,'[\"1\"]','{\"name\":\"mangesh_darade22\",\"parameter_format\":\"POSITIONAL\",\"components\":[{\"type\":\"HEADER\",\"format\":\"IMAGE\",\"example\":{\"header_handle\":[\"https:\\/\\/scontent.whatsapp.net\\/v\\/t61.29466-34\\/620375329_1570690464786216_8859512334761623389_n.png?ccb=1-7&_nc_sid=8b1bef&_nc_ohc=XpZqbAhho-MQ7kNvwFYQzJa&_nc_oc=AdoNLGduJK3GKR_NNCObrhIIEZL86A4JsoXxDxGfQ4LCQ7jQ6DQLkzw4p-UwA446zro&_nc_zt=3&_nc_ht=scontent.whatsapp.net&edm=AH51TzQEAAAA&_nc_gid=0O5IDvrg_AiSUD3w2sdlCw&_nc_tpa=Q5bMBQI6_E_T-gSrBNVLkhWdJVIynM0uNHEpmUIkaGE9scG31UZiJQwVUgrbFuEaSdsNlEnipuzDNX4-pQ&oh=01_Q5Aa5wHgb5YfRXqczCLqJACqaU4wWPH8BT80GvtUtxV_JY6B-A&oe=6AEC2771\"]}},{\"type\":\"BODY\",\"text\":\"hi {{1}} please check this link\",\"example\":{\"body_text\":[[\"Darade\"]]}}],\"language\":\"en_US\",\"status\":\"APPROVED\",\"category\":\"MARKETING\",\"disable_ios_autofill\":false,\"is_primary_device_delivery_only\":false,\"id\":\"1570690461452883\"}','2026-10-06 08:08:49','2026-08-01 06:22:57','2026-10-06 08:08:49');
INSERT INTO `templates` VALUES (129,'2224778918307465','2112955712589094','mangesh_darade','en_US','MARKETING','default','APPROVED',NULL,'image','https://scontent.whatsapp.net/v/t61.29466-34/660704677_2112955742589091_5976704567480832843_n.png?ccb=1-7&_nc_sid=8b1bef&_nc_ohc=aH_54CRSqtsQ7kNvwGVo99l&_nc_oc=AdozlcPR2xo5_nsqlj3k3fBHXcqDeRbHI7BMOft62mn3t5oJvmMgFUJesXD3SEHWXRo&_nc_zt=3&_nc_ht=scontent.whatsapp.net&edm=AH51TzQEAAAA&_nc_gid=0O5IDvrg_AiSUD3w2sdlCw&_nc_tpa=Q5bMBQJWgCwy2IqgM-sk37D33l43mer87TCqIMPvfS5I3GCgTnLRcpXeZRsx8XUb7MBjoLLGjAvm9GAo2g&oh=01_Q5Aa5wGNlYMb5eZwpHAQ8bG7PtpFnrdeDoIZujV3PPCLJy89tg&oe=6AEC0BB9','hey {{1}}  this is {{2}} software for customer.',NULL,NULL,'[\"1\",\"2\"]','{\"name\":\"mangesh_darade\",\"parameter_format\":\"POSITIONAL\",\"components\":[{\"type\":\"HEADER\",\"format\":\"IMAGE\",\"example\":{\"header_handle\":[\"https:\\/\\/scontent.whatsapp.net\\/v\\/t61.29466-34\\/660704677_2112955742589091_5976704567480832843_n.png?ccb=1-7&_nc_sid=8b1bef&_nc_ohc=aH_54CRSqtsQ7kNvwGVo99l&_nc_oc=AdozlcPR2xo5_nsqlj3k3fBHXcqDeRbHI7BMOft62mn3t5oJvmMgFUJesXD3SEHWXRo&_nc_zt=3&_nc_ht=scontent.whatsapp.net&edm=AH51TzQEAAAA&_nc_gid=0O5IDvrg_AiSUD3w2sdlCw&_nc_tpa=Q5bMBQJWgCwy2IqgM-sk37D33l43mer87TCqIMPvfS5I3GCgTnLRcpXeZRsx8XUb7MBjoLLGjAvm9GAo2g&oh=01_Q5Aa5wGNlYMb5eZwpHAQ8bG7PtpFnrdeDoIZujV3PPCLJy89tg&oe=6AEC0BB9\"]}},{\"type\":\"BODY\",\"text\":\"hey {{1}}  this is {{2}} software for customer.\",\"example\":{\"body_text\":[[\"Mangesh\",\"ElintOM\"]]}}],\"language\":\"en_US\",\"status\":\"APPROVED\",\"category\":\"MARKETING\",\"disable_ios_autofill\":false,\"is_primary_device_delivery_only\":false,\"id\":\"2112955712589094\"}','2026-10-06 08:08:49','2026-08-01 06:22:57','2026-10-06 08:08:49');
INSERT INTO `templates` VALUES (130,'2224778918307465','1035542615913966','without_var_with_img_new','en_US','MARKETING','default','APPROVED',NULL,'image','https://scontent.whatsapp.net/v/t61.29466-34/758657195_1035542619247299_1624345449477777329_n.jpg?ccb=1-7&_nc_sid=8b1bef&_nc_ohc=IbbZFcuzSJ4Q7kNvwEKicZ8&_nc_oc=AdoC6KQpwZYWGmy9l4pJuHWuvmk_zcAm-D-BayUhJcshRH-nCHX7l-2IYmZMcPBCY9E&_nc_zt=3&_nc_ht=scontent.whatsapp.net&edm=AH51TzQEAAAA&_nc_gid=0O5IDvrg_AiSUD3w2sdlCw&_nc_tpa=Q5bMBQLZ9eFm3NYaqvDfpqUQlro0DdmXPJNdp460aJuq8-Hu-fz_8DRzGDqRXTMOq106BV-nhC_7n7r_9w&oh=01_Q5Aa5wGjl50hV4ka6_GbijFuGwhDRq2yxfV9VOsPI0han69dUw&oe=6AEC3571','Hello customer\r\nHappy Diwali',NULL,NULL,NULL,'{\"name\":\"without_var_with_img_new\",\"parameter_format\":\"POSITIONAL\",\"components\":[{\"type\":\"HEADER\",\"format\":\"IMAGE\",\"example\":{\"header_handle\":[\"https:\\/\\/scontent.whatsapp.net\\/v\\/t61.29466-34\\/758657195_1035542619247299_1624345449477777329_n.jpg?ccb=1-7&_nc_sid=8b1bef&_nc_ohc=IbbZFcuzSJ4Q7kNvwEKicZ8&_nc_oc=AdoC6KQpwZYWGmy9l4pJuHWuvmk_zcAm-D-BayUhJcshRH-nCHX7l-2IYmZMcPBCY9E&_nc_zt=3&_nc_ht=scontent.whatsapp.net&edm=AH51TzQEAAAA&_nc_gid=0O5IDvrg_AiSUD3w2sdlCw&_nc_tpa=Q5bMBQLZ9eFm3NYaqvDfpqUQlro0DdmXPJNdp460aJuq8-Hu-fz_8DRzGDqRXTMOq106BV-nhC_7n7r_9w&oh=01_Q5Aa5wGjl50hV4ka6_GbijFuGwhDRq2yxfV9VOsPI0han69dUw&oe=6AEC3571\"]}},{\"type\":\"BODY\",\"text\":\"Hello customer\\r\\nHappy Diwali\"}],\"language\":\"en_US\",\"status\":\"APPROVED\",\"category\":\"MARKETING\",\"disable_ios_autofill\":false,\"is_primary_device_delivery_only\":false,\"id\":\"1035542615913966\"}','2026-10-06 08:08:49','2026-08-01 06:22:57','2026-10-06 08:08:49');
INSERT INTO `templates` VALUES (131,'2224778918307465','2130193604204111','without_var_with_img','en_US','MARKETING','default','APPROVED',NULL,'image','https://scontent.whatsapp.net/v/t61.29466-34/759676312_2130193610870777_1609559057607795675_n.jpg?ccb=1-7&_nc_sid=8b1bef&_nc_ohc=I9Gbvvr-TMgQ7kNvwEocUmn&_nc_oc=AdrJfrOunCaJdS0akLWnyNKK5oiV8XdL7MY1wFgdkpHm0RomsdrbZR2EL8ENdqvq7BI&_nc_zt=3&_nc_ht=scontent.whatsapp.net&edm=AH51TzQEAAAA&_nc_gid=0O5IDvrg_AiSUD3w2sdlCw&_nc_tpa=Q5bMBQLi0JESj2lDCgW7x8nlcLY02sfnYRUSDgjROnsHOa4Cd-yUJsjGnyG1Y7IS58oktYU9Jch9V823SA&oh=01_Q5Aa5wEDgieiBFX2WGjoeUcr-kG32wLZkRw08rQlrJn_OP0X_Q&oe=6AEC2B87','Hello, {{1}}\r\nHappy {{2}}\r\nThank You Very Much.',NULL,NULL,'[\"1\",\"2\"]','{\"name\":\"without_var_with_img\",\"parameter_format\":\"POSITIONAL\",\"components\":[{\"type\":\"HEADER\",\"format\":\"IMAGE\",\"example\":{\"header_handle\":[\"https:\\/\\/scontent.whatsapp.net\\/v\\/t61.29466-34\\/759676312_2130193610870777_1609559057607795675_n.jpg?ccb=1-7&_nc_sid=8b1bef&_nc_ohc=I9Gbvvr-TMgQ7kNvwEocUmn&_nc_oc=AdrJfrOunCaJdS0akLWnyNKK5oiV8XdL7MY1wFgdkpHm0RomsdrbZR2EL8ENdqvq7BI&_nc_zt=3&_nc_ht=scontent.whatsapp.net&edm=AH51TzQEAAAA&_nc_gid=0O5IDvrg_AiSUD3w2sdlCw&_nc_tpa=Q5bMBQLi0JESj2lDCgW7x8nlcLY02sfnYRUSDgjROnsHOa4Cd-yUJsjGnyG1Y7IS58oktYU9Jch9V823SA&oh=01_Q5Aa5wEDgieiBFX2WGjoeUcr-kG32wLZkRw08rQlrJn_OP0X_Q&oe=6AEC2B87\"]}},{\"type\":\"BODY\",\"text\":\"Hello, {{1}}\\r\\nHappy {{2}}\\r\\nThank You Very Much.\",\"example\":{\"body_text\":[[\"name\",\"Diwali\"]]}}],\"language\":\"en_US\",\"status\":\"APPROVED\",\"category\":\"MARKETING\",\"disable_ios_autofill\":false,\"is_primary_device_delivery_only\":false,\"id\":\"2130193604204111\"}','2026-10-06 08:08:49','2026-08-01 06:22:57','2026-10-06 08:08:49');
INSERT INTO `templates` VALUES (132,'2224778918307465','1552837119854240','vipin_img_mrk','en_US','MARKETING','default','APPROVED',NULL,'image','https://scontent.whatsapp.net/v/t61.29466-34/759610807_1552837123187573_1994703378256857798_n.jpg?ccb=1-7&_nc_sid=8b1bef&_nc_ohc=VEYH_tBtKggQ7kNvwEGUrWA&_nc_oc=Adq9c4PIAqFmH_pb5mzUoSGOpy1EglMVBAe_Rc-saXbZ4cF3iYh8J9q2aaM4M_FNV1E&_nc_zt=3&_nc_ht=scontent.whatsapp.net&edm=AH51TzQEAAAA&_nc_gid=0O5IDvrg_AiSUD3w2sdlCw&_nc_tpa=Q5bMBQKrjYnAk2ouagV4VrVbnk9reyxQgnMORzhIzGA_rWhGCdBi7EcP64CZrCi7DO5KWs3kAbDdC8D2jA&oh=01_Q5Aa5wHBj78YKzg7v6IBAWhDHkgN4hAoKf1E5kPtevnsz3naig&oe=6AEC07CF','Hello {{1}}\r\nwould you like to purchase our {{2}}\r\nhere is the link {{3}}\r\nThank You','Team Merchant','[{\"type\":\"QUICK_REPLY\",\"text\":\"YES\"},{\"type\":\"URL\",\"text\":\"Visit Website\",\"url\":\"https:\\/\\/www.amazon.in\\/\"}]','[\"1\",\"2\",\"3\"]','{\"name\":\"vipin_img_mrk\",\"parameter_format\":\"POSITIONAL\",\"components\":[{\"type\":\"HEADER\",\"format\":\"IMAGE\",\"example\":{\"header_handle\":[\"https:\\/\\/scontent.whatsapp.net\\/v\\/t61.29466-34\\/759610807_1552837123187573_1994703378256857798_n.jpg?ccb=1-7&_nc_sid=8b1bef&_nc_ohc=VEYH_tBtKggQ7kNvwEGUrWA&_nc_oc=Adq9c4PIAqFmH_pb5mzUoSGOpy1EglMVBAe_Rc-saXbZ4cF3iYh8J9q2aaM4M_FNV1E&_nc_zt=3&_nc_ht=scontent.whatsapp.net&edm=AH51TzQEAAAA&_nc_gid=0O5IDvrg_AiSUD3w2sdlCw&_nc_tpa=Q5bMBQKrjYnAk2ouagV4VrVbnk9reyxQgnMORzhIzGA_rWhGCdBi7EcP64CZrCi7DO5KWs3kAbDdC8D2jA&oh=01_Q5Aa5wHBj78YKzg7v6IBAWhDHkgN4hAoKf1E5kPtevnsz3naig&oe=6AEC07CF\"]}},{\"type\":\"BODY\",\"text\":\"Hello {{1}}\\r\\nwould you like to purchase our {{2}}\\r\\nhere is the link {{3}}\\r\\nThank You\",\"example\":{\"body_text\":[[\"Name\",\"Shampoo\",\"amazon.in\\/StacPro-Coasters-Glasses-Protector-Kitchen\\/dp\\/B0FQ\"]]}},{\"type\":\"FOOTER\",\"text\":\"Team Merchant\"},{\"type\":\"BUTTONS\",\"buttons\":[{\"type\":\"QUICK_REPLY\",\"text\":\"YES\"},{\"type\":\"URL\",\"text\":\"Visit Website\",\"url\":\"https:\\/\\/www.amazon.in\\/\"}]}],\"language\":\"en_US\",\"status\":\"APPROVED\",\"category\":\"MARKETING\",\"disable_ios_autofill\":false,\"is_primary_device_delivery_only\":false,\"id\":\"1552837119854240\"}','2026-10-06 08:08:49','2026-08-01 06:22:57','2026-10-06 08:08:49');
INSERT INTO `templates` VALUES (133,'2224778918307465','1284067020320363','mangesh_test2','en_US','MARKETING','default','APPROVED',NULL,'image','https://scontent.whatsapp.net/v/t61.29466-34/758969420_1284067026987029_6868358406492729641_n.png?ccb=1-7&_nc_sid=8b1bef&_nc_ohc=hdpC7z7YTZUQ7kNvwGUZYnX&_nc_oc=Adr7kWWtmG7HOOoariEj-TKbwb2wRIpGnek1VkrPwj-aw-rU5MB2TWv5f7Wi3lCDxvM&_nc_zt=3&_nc_ht=scontent.whatsapp.net&edm=AH51TzQEAAAA&_nc_gid=0O5IDvrg_AiSUD3w2sdlCw&_nc_tpa=Q5bMBQKREVCMWBL7yW9Z9PoKO-oIlIsCWFuBcJtSroYRHoMzGiLZ_jCEar6zy5Hi8ICC79PFSm7MC4LhLw&oh=01_Q5Aa5wG4vXKKG3y42OQ_eToJnjI1cHRIUwYdgXWx_fE5DFLsXA&oe=6AEC1570','mangesh_test2  mangesh_test2 done',NULL,NULL,NULL,'{\"name\":\"mangesh_test2\",\"parameter_format\":\"POSITIONAL\",\"components\":[{\"type\":\"HEADER\",\"format\":\"IMAGE\",\"example\":{\"header_handle\":[\"https:\\/\\/scontent.whatsapp.net\\/v\\/t61.29466-34\\/758969420_1284067026987029_6868358406492729641_n.png?ccb=1-7&_nc_sid=8b1bef&_nc_ohc=hdpC7z7YTZUQ7kNvwGUZYnX&_nc_oc=Adr7kWWtmG7HOOoariEj-TKbwb2wRIpGnek1VkrPwj-aw-rU5MB2TWv5f7Wi3lCDxvM&_nc_zt=3&_nc_ht=scontent.whatsapp.net&edm=AH51TzQEAAAA&_nc_gid=0O5IDvrg_AiSUD3w2sdlCw&_nc_tpa=Q5bMBQKREVCMWBL7yW9Z9PoKO-oIlIsCWFuBcJtSroYRHoMzGiLZ_jCEar6zy5Hi8ICC79PFSm7MC4LhLw&oh=01_Q5Aa5wG4vXKKG3y42OQ_eToJnjI1cHRIUwYdgXWx_fE5DFLsXA&oe=6AEC1570\"]}},{\"type\":\"BODY\",\"text\":\"mangesh_test2  mangesh_test2 done\"}],\"language\":\"en_US\",\"status\":\"APPROVED\",\"category\":\"MARKETING\",\"disable_ios_autofill\":false,\"is_primary_device_delivery_only\":false,\"id\":\"1284067020320363\"}','2026-10-06 08:08:49','2026-08-01 06:22:57','2026-10-06 08:08:49');
INSERT INTO `templates` VALUES (134,'2224778918307465','1064521989234523','mangesh_testing','en_US','MARKETING','default','APPROVED',NULL,'video','https://scontent.whatsapp.net/v/t61.29466-34/759676300_1064521992567856_6517731314450127305_n.mp4?ccb=1-7&_nc_sid=8b1bef&_nc_ohc=FoHbEo0BpdMQ7kNvwH9xTVW&_nc_oc=AdoAqLD6kMElG1iSdrWG5AX1mqst4d7yTx2JBFN7K6f34a6BCxPZN7uo2R_h8yPewbM&_nc_zt=28&_nc_ht=scontent.whatsapp.net&edm=AH51TzQEAAAA&_nc_gid=0O5IDvrg_AiSUD3w2sdlCw&_nc_tpa=Q5bMBQJQ5vbW_Whb3P93B_3EXfvHohK3_XGiOn-coDD_zAWRI3OA9tSVlPSfaVa69ZKgXQ1gVP2Daen9Ng&oh=01_Q5Aa5wGbKV63r3Yre-M2wA-smbZ6S_eGJthCl06TgTvKC677Pg&oe=6AEC036A','mangesh_testing {{1}} mangesh_testing Demo',NULL,NULL,'[\"1\"]','{\"name\":\"mangesh_testing\",\"parameter_format\":\"POSITIONAL\",\"components\":[{\"type\":\"HEADER\",\"format\":\"VIDEO\",\"example\":{\"header_handle\":[\"https:\\/\\/scontent.whatsapp.net\\/v\\/t61.29466-34\\/759676300_1064521992567856_6517731314450127305_n.mp4?ccb=1-7&_nc_sid=8b1bef&_nc_ohc=FoHbEo0BpdMQ7kNvwH9xTVW&_nc_oc=AdoAqLD6kMElG1iSdrWG5AX1mqst4d7yTx2JBFN7K6f34a6BCxPZN7uo2R_h8yPewbM&_nc_zt=28&_nc_ht=scontent.whatsapp.net&edm=AH51TzQEAAAA&_nc_gid=0O5IDvrg_AiSUD3w2sdlCw&_nc_tpa=Q5bMBQJQ5vbW_Whb3P93B_3EXfvHohK3_XGiOn-coDD_zAWRI3OA9tSVlPSfaVa69ZKgXQ1gVP2Daen9Ng&oh=01_Q5Aa5wGbKV63r3Yre-M2wA-smbZ6S_eGJthCl06TgTvKC677Pg&oe=6AEC036A\"]}},{\"type\":\"BODY\",\"text\":\"mangesh_testing {{1}} mangesh_testing Demo\",\"example\":{\"body_text\":[[\"darade\"]]}}],\"language\":\"en_US\",\"status\":\"APPROVED\",\"category\":\"MARKETING\",\"disable_ios_autofill\":false,\"is_primary_device_delivery_only\":false,\"id\":\"1064521989234523\"}','2026-10-06 08:08:49','2026-08-01 06:22:57','2026-10-06 08:08:49');
INSERT INTO `templates` VALUES (135,'2224778918307465','1655345095569164','my_number','en_US','MARKETING','default','APPROVED',NULL,'text','my_number','my_number',NULL,NULL,NULL,'{\"name\":\"my_number\",\"parameter_format\":\"POSITIONAL\",\"components\":[{\"type\":\"HEADER\",\"format\":\"TEXT\",\"text\":\"my_number\"},{\"type\":\"BODY\",\"text\":\"my_number\"}],\"language\":\"en_US\",\"status\":\"APPROVED\",\"category\":\"MARKETING\",\"disable_ios_autofill\":false,\"is_primary_device_delivery_only\":false,\"id\":\"1655345095569164\"}','2026-10-06 08:08:49','2026-08-01 06:22:57','2026-10-06 08:08:49');
INSERT INTO `templates` VALUES (136,'2224778918307465','1598684538538248','hello_world','en_US','UTILITY','default','APPROVED',NULL,'text','Hello World','Welcome and congratulations!! This message demonstrates your ability to send a WhatsApp message notification from the Cloud API, hosted by Meta. Thank you for taking the time to test with us.','WhatsApp Business Platform sample message',NULL,NULL,'{\"name\":\"hello_world\",\"parameter_format\":\"POSITIONAL\",\"components\":[{\"type\":\"HEADER\",\"format\":\"TEXT\",\"text\":\"Hello World\"},{\"type\":\"BODY\",\"text\":\"Welcome and congratulations!! This message demonstrates your ability to send a WhatsApp message notification from the Cloud API, hosted by Meta. Thank you for taking the time to test with us.\"},{\"type\":\"FOOTER\",\"text\":\"WhatsApp Business Platform sample message\"}],\"language\":\"en_US\",\"status\":\"APPROVED\",\"category\":\"UTILITY\",\"disable_ios_autofill\":false,\"is_primary_device_delivery_only\":false,\"id\":\"1598684538538248\"}','2026-10-06 08:08:49','2026-08-01 06:22:57','2026-10-06 08:08:49');
INSERT INTO `templates` VALUES (137,'2224778918307465','1971427973814810','invoice_without_award_points','en_US','UTILITY','default','APPROVED',NULL,'text','Invoice','Hello {{1}}, thank you for shopping at {{2}}. Your invoice total is {{3}}. You can download or view your invoice using this link: {{4}}. Regards from {{5}}. Thank you for your business.',NULL,NULL,'[\"1\",\"2\",\"3\",\"4\",\"5\"]','{\"name\":\"invoice_without_award_points\",\"parameter_format\":\"POSITIONAL\",\"components\":[{\"type\":\"HEADER\",\"format\":\"TEXT\",\"text\":\"Invoice\"},{\"type\":\"BODY\",\"text\":\"Hello {{1}}, thank you for shopping at {{2}}. Your invoice total is {{3}}. You can download or view your invoice using this link: {{4}}. Regards from {{5}}. Thank you for your business.\",\"example\":{\"body_text\":[[\"Customer name\",\"Site \\/ company name\",\"Rs 300.00\",\"https:\\/\\/example.com\\/invoice\",\"Site name again\"]]}}],\"language\":\"en_US\",\"status\":\"APPROVED\",\"category\":\"UTILITY\",\"disable_ios_autofill\":false,\"is_primary_device_delivery_only\":false,\"id\":\"1971427973814810\"}','2026-10-06 08:08:49','2026-08-03 15:58:28','2026-10-06 08:08:49');
INSERT INTO `templates` VALUES (138,'2224778918307465','4363605613861111','payment_reminder','en_US','UTILITY','default','APPROVED',NULL,'text','Payment Reminder','Dear {{1}}, your total bill amount is {{2}}. You have paid {{3}} and the balance due is {{4}}. Please clear the pending amount at the earliest. Regards from {{5}}. Thank you.',NULL,NULL,'[\"1\",\"2\",\"3\",\"4\",\"5\"]','{\"name\":\"payment_reminder\",\"parameter_format\":\"POSITIONAL\",\"components\":[{\"type\":\"HEADER\",\"format\":\"TEXT\",\"text\":\"Payment Reminder\"},{\"type\":\"BODY\",\"text\":\"Dear {{1}}, your total bill amount is {{2}}. You have paid {{3}} and the balance due is {{4}}. Please clear the pending amount at the earliest. Regards from {{5}}. Thank you.\",\"example\":{\"body_text\":[[\"Customer name\",\"Rs 1000.00\",\"Rs 400.00\",\"Rs 600.00\",\"Demo company\"]]}}],\"language\":\"en_US\",\"status\":\"APPROVED\",\"category\":\"UTILITY\",\"disable_ios_autofill\":false,\"is_primary_device_delivery_only\":false,\"id\":\"4363605613861111\"}','2026-10-06 08:08:49','2026-08-03 16:00:13','2026-10-06 08:08:49');
INSERT INTO `templates` VALUES (139,'2224778918307465','1094352519640967','custom_text_message','en_US','UTILITY','default','APPROVED',NULL,'text','Message','Hello, here is your update from our store. {{1}} Thank you for choosing us.','Thank you',NULL,'[\"1\"]','{\"name\":\"custom_text_message\",\"parameter_format\":\"POSITIONAL\",\"components\":[{\"type\":\"HEADER\",\"format\":\"TEXT\",\"text\":\"Message\"},{\"type\":\"BODY\",\"text\":\"Hello, here is your update from our store. {{1}} Thank you for choosing us.\",\"example\":{\"body_text\":[[\"Your service request has been updated.\"]]}},{\"type\":\"FOOTER\",\"text\":\"Thank you\"}],\"language\":\"en_US\",\"status\":\"APPROVED\",\"category\":\"UTILITY\",\"disable_ios_autofill\":false,\"is_primary_device_delivery_only\":false,\"id\":\"1094352519640967\"}','2026-10-06 08:08:49','2026-08-04 06:32:09','2026-10-06 08:08:49');
INSERT INTO `templates` VALUES (140,'2224778918307465','1356079719460805','elintom_webshop_order','en_US','UTILITY','default','APPROVED',NULL,NULL,NULL,'Hello {{7}},\r\n\r\n✅ Your order at *{{8}}* is confirmed.\r\n\r\n*Order Summary*\r\n• Items: {{1}}\r\n• Total: *{{2}}*\r\n• Payment: {{3}}\r\n• Address: {{4}}\r\n\r\n*Quick links*\r\n🧾 Receipt:\r\n{{5}}\r\n\r\n📍 Track / Order QR:\r\n{{6}}\r\n\r\n🌐 Shop again:\r\n{{10}}\r\n\r\nSupport: {{9}}\r\n\r\nThank you!',NULL,NULL,'[\"7\",\"8\",\"1\",\"2\",\"3\",\"4\",\"5\",\"6\",\"10\",\"9\"]','{\"name\":\"elintom_webshop_order\",\"parameter_format\":\"POSITIONAL\",\"components\":[{\"type\":\"BODY\",\"text\":\"Hello {{7}},\\r\\n\\r\\n\\u2705 Your order at *{{8}}* is confirmed.\\r\\n\\r\\n*Order Summary*\\r\\n\\u2022 Items: {{1}}\\r\\n\\u2022 Total: *{{2}}*\\r\\n\\u2022 Payment: {{3}}\\r\\n\\u2022 Address: {{4}}\\r\\n\\r\\n*Quick links*\\r\\n\\ud83e\\uddfe Receipt:\\r\\n{{5}}\\r\\n\\r\\n\\ud83d\\udccd Track \\/ Order QR:\\r\\n{{6}}\\r\\n\\r\\n\\ud83c\\udf10 Shop again:\\r\\n{{10}}\\r\\n\\r\\nSupport: {{9}}\\r\\n\\r\\nThank you!\",\"example\":{\"body_text\":[[\"Items list\",\"Total amount\",\"Payment status\",\"Address\",\"Receipt URL\",\"Track URL + Order QR\",\"Customer (Order REF)\",\"Brand \\/ site name\",\"Support phone\",\"Storefront URL\"]]}}],\"language\":\"en_US\",\"status\":\"APPROVED\",\"category\":\"UTILITY\",\"disable_ios_autofill\":false,\"is_primary_device_delivery_only\":false,\"id\":\"1356079719460805\"}','2026-10-06 08:08:49','2026-08-10 10:03:31','2026-10-06 08:08:49');
INSERT INTO `templates` VALUES (141,'2224778918307465','1446137200706646','wa_consent_request','en_US','MARKETING','default','APPROVED',NULL,NULL,NULL,'Hello! Would you like to receive updates and offers from us on WhatsApp? Tap Agree to subscribe or Stop to opt out. You can unsubscribe anytime.',NULL,'[{\"type\":\"QUICK_REPLY\",\"text\":\"Agree\"},{\"type\":\"QUICK_REPLY\",\"text\":\"Stop\"}]',NULL,'{\"name\":\"wa_consent_request\",\"parameter_format\":\"POSITIONAL\",\"components\":[{\"type\":\"BODY\",\"text\":\"Hello! Would you like to receive updates and offers from us on WhatsApp? Tap Agree to subscribe or Stop to opt out. You can unsubscribe anytime.\"},{\"type\":\"BUTTONS\",\"buttons\":[{\"type\":\"QUICK_REPLY\",\"text\":\"Agree\"},{\"type\":\"QUICK_REPLY\",\"text\":\"Stop\"}]}],\"language\":\"en_US\",\"status\":\"APPROVED\",\"category\":\"MARKETING\",\"disable_ios_autofill\":false,\"is_primary_device_delivery_only\":false,\"id\":\"1446137200706646\"}','2026-10-06 08:08:49','2026-09-30 10:48:03','2026-10-06 08:08:49');
/*!40000 ALTER TABLE `templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'2024-01-01-000001','App\\Database\\Migrations\\CreateRoles','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (2,'2024-01-01-000002','App\\Database\\Migrations\\CreatePermissions','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (3,'2024-01-01-000003','App\\Database\\Migrations\\CreateRolePermissions','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (4,'2024-01-01-000004','App\\Database\\Migrations\\CreateUsers','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (5,'2024-01-01-000005','App\\Database\\Migrations\\CreatePasswordResets','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (6,'2024-01-01-000006','App\\Database\\Migrations\\CreateTags','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (7,'2024-01-01-000007','App\\Database\\Migrations\\CreateContacts','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (8,'2024-01-01-000008','App\\Database\\Migrations\\CreateContactTags','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (9,'2024-01-01-000009','App\\Database\\Migrations\\CreateTemplates','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (10,'2024-01-01-000010','App\\Database\\Migrations\\CreateCampaigns','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (11,'2024-01-01-000011','App\\Database\\Migrations\\CreateCampaignContacts','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (12,'2024-01-01-000012','App\\Database\\Migrations\\CreateMessageQueue','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (13,'2024-01-01-000013','App\\Database\\Migrations\\CreateMessages','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (14,'2024-01-01-000014','App\\Database\\Migrations\\CreateMedia','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (15,'2024-01-01-000015','App\\Database\\Migrations\\CreateAutomations','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (16,'2024-01-01-000016','App\\Database\\Migrations\\CreateAutomationRules','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (17,'2024-01-01-000017','App\\Database\\Migrations\\CreateKeywords','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (18,'2024-01-01-000018','App\\Database\\Migrations\\CreateWebhookLogs','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (19,'2024-01-01-000019','App\\Database\\Migrations\\CreateSettings','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (20,'2024-01-01-000020','App\\Database\\Migrations\\CreateActivityLogs','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (21,'2024-01-01-000021','App\\Database\\Migrations\\CreateNotifications','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (22,'2024-01-01-000022','App\\Database\\Migrations\\CreateApiTokens','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (23,'2024-01-01-000023','App\\Database\\Migrations\\CreateConversations','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (24,'2024-01-01-000024','App\\Database\\Migrations\\CreateInternalNotes','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (25,'2024-01-01-000025','App\\Database\\Migrations\\CreateRateLimits','default','App',1784967049,1);
INSERT INTO `migrations` VALUES (26,'2026-07-24-000001','App\\Database\\Migrations\\AddFlowGraphToAutomations','default','App',1784967050,1);
INSERT INTO `migrations` VALUES (27,'2026-07-27-000001','App\\Database\\Migrations\\CreateEmailManagerTables','default','App',1785145236,2);
INSERT INTO `migrations` VALUES (28,'2026-07-27-000002','App\\Database\\Migrations\\AddOmnichannelInboxFields','default','App',1785153444,3);
INSERT INTO `migrations` VALUES (29,'2026-07-28-151800','App\\Database\\Migrations\\AddEmailVerificationToUsers','default','App',1785232182,4);
INSERT INTO `migrations` VALUES (30,'2026-07-28-154200','App\\Database\\Migrations\\AddTemplateTypeToTemplates','default','App',1785233502,5);
INSERT INTO `migrations` VALUES (31,'2026-07-28-165500','App\\Database\\Migrations\\AddScheduledAtToEmailHtmlCampaigns','default','App',1785238430,6);
INSERT INTO `migrations` VALUES (32,'2026-07-29-090000','App\\Database\\Migrations\\ExpandConversationInboxStatuses','default','App',1785297446,7);
INSERT INTO `migrations` VALUES (33,'2026-07-29-100000','App\\Database\\Migrations\\CreateAutomationDelayedJobsAndSequences','default','App',1785297922,8);
INSERT INTO `migrations` VALUES (34,'2026-08-11-210000','App\\Database\\Migrations\\AddWabaUniqueToTemplates','default','App',1786886363,9);
INSERT INTO `migrations` VALUES (35,'2026-09-28-100000','App\\Database\\Migrations\\AddWhatsAppConsentToContacts','default','App',1791277617,10);
INSERT INTO `migrations` VALUES (36,'2026-09-28-110000','App\\Database\\Migrations\\AddWaConsentRequestedAtToContacts','default','App',1791277617,10);
INSERT INTO `migrations` VALUES (37,'2026-09-30-100000','App\\Database\\Migrations\\CreateContactAttributesAndQuickReplies','default','App',1791277617,10);
INSERT INTO `migrations` VALUES (38,'2026-10-06-100000','App\\Database\\Migrations\\AddEmailTrackingAndUnsubscribes','default','App',1791277617,10);
INSERT INTO `migrations` VALUES (39,'2026-10-06-120000','App\\Database\\Migrations\\AddAttachmentsToEmailCampaignsAndBuilders','default','App',1791286267,11);
INSERT INTO `migrations` VALUES (40,'2026-10-06-130000','App\\Database\\Migrations\\CreateCountriesTable','default','App',1791290407,12);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;


SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT;
SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS;
SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION;
SET TIME_ZONE=@OLD_TIME_ZONE;
SET SQL_MODE=@OLD_SQL_MODE;

-- =====================================================================
-- End of Sateri Connect Setup Script (Ready for Login & Production Use)
-- =====================================================================
