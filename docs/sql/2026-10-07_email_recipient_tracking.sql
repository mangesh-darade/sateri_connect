-- =====================================================================
-- Sateri Connect — 2026-10-07 Email upgrade (safe to run any number of times)
--
--   1. email_recipient_events  : per-recipient Sent / Failed / Delivered / Bounced / Opened / Clicked
--   2. email_senders           : Amazon SES identity fields (provider, mail_from_domain, last_checked_at)
--   3. email_recipient_events  : message_id (SES MessageId → bounce matching)
--
-- Same as migrations:
--   2026-10-07-100000_CreateEmailRecipientEvents
--   2026-10-07-110000_AddSesIdentityFieldsToEmailSenders
--   2026-10-07-120000_AddMessageIdToEmailRecipientEvents
--
-- How to run: select the client (tenant) database → phpMyAdmin SQL tab → paste all → Go.
-- Missing tables / columns / indexes are created; existing ones are skipped (no error, no data loss).
-- =====================================================================

DROP PROCEDURE IF EXISTS sc_add_column;
DROP PROCEDURE IF EXISTS sc_add_index;

DELIMITER $$

CREATE PROCEDURE sc_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table)
       AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column) THEN
        SET @sc_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE sc_stmt FROM @sc_sql;
        EXECUTE sc_stmt;
        DEALLOCATE PREPARE sc_stmt;
    END IF;
END$$

CREATE PROCEDURE sc_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_definition TEXT)
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table)
       AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index) THEN
        SET @sc_sql = CONCAT('ALTER TABLE `', p_table, '` ADD ', p_definition);
        PREPARE sc_stmt FROM @sc_sql;
        EXECUTE sc_stmt;
        DEALLOCATE PREPARE sc_stmt;
    END IF;
END$$

DELIMITER ;

-- ---------------------------------------------------------------------
-- 1. Per-recipient email events
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_recipient_events` (
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

-- Table already existed (older version) → bring it up to date
CALL sc_add_column('email_recipient_events', 'message_id', "varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `detail`");
CALL sc_add_index('email_recipient_events', 'uniq_log_email_event', 'UNIQUE KEY `uniq_log_email_event` (`log_id`,`email`,`event_type`)');
CALL sc_add_index('email_recipient_events', 'campaign_id', 'KEY `campaign_id` (`campaign_id`)');
CALL sc_add_index('email_recipient_events', 'email_event_type', 'KEY `email_event_type` (`email`,`event_type`)');
CALL sc_add_index('email_recipient_events', 'message_id', 'KEY `message_id` (`message_id`)');

-- ---------------------------------------------------------------------
-- 2. Amazon SES identity fields on email_senders (skipped if table is missing)
-- ---------------------------------------------------------------------
CALL sc_add_column('email_senders', 'provider', "varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `type`");
CALL sc_add_column('email_senders', 'mail_from_domain', "varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `domain`");
CALL sc_add_column('email_senders', 'last_checked_at', "datetime DEFAULT NULL AFTER `is_default`");

-- ---------------------------------------------------------------------
-- 3. Mark migrations as applied so `php spark migrate` will not re-run them
-- ---------------------------------------------------------------------
SET @sc_batch = (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`);

INSERT INTO `migrations` (`version`, `class`, `group`, `namespace`, `time`, `batch`)
SELECT v.version, v.class, 'default', 'App', UNIX_TIMESTAMP(), @sc_batch
FROM (
    SELECT '2026-10-07-100000' AS version, 'App\\Database\\Migrations\\CreateEmailRecipientEvents' AS class
    UNION ALL SELECT '2026-10-07-110000', 'App\\Database\\Migrations\\AddSesIdentityFieldsToEmailSenders'
    UNION ALL SELECT '2026-10-07-120000', 'App\\Database\\Migrations\\AddMessageIdToEmailRecipientEvents'
) v
WHERE NOT EXISTS (SELECT 1 FROM `migrations` m WHERE m.version = v.version AND m.class = v.class);

DROP PROCEDURE IF EXISTS sc_add_column;
DROP PROCEDURE IF EXISTS sc_add_index;

-- ---------------------------------------------------------------------
-- Check (optional): should list every column / index above
-- ---------------------------------------------------------------------
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((TABLE_NAME = 'email_recipient_events')
    OR (TABLE_NAME = 'email_senders' AND COLUMN_NAME IN ('provider', 'mail_from_domain', 'last_checked_at')))
ORDER BY TABLE_NAME, ORDINAL_POSITION;
