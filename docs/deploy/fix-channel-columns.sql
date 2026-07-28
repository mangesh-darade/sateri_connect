-- =============================================================================
-- Live Chat fix: Unknown column 'channel' in 'where clause'
-- Database: your android_testing / production DB
-- Run in phpMyAdmin → SQL tab (one statement at a time if needed)
-- Ignore errors: "Duplicate column name"
-- =============================================================================

-- 1) contacts
ALTER TABLE `contacts`
  ADD COLUMN `channel` VARCHAR(20) NOT NULL DEFAULT 'whatsapp' AFTER `id`;

ALTER TABLE `contacts`
  ADD COLUMN `external_id` VARCHAR(191) NULL DEFAULT NULL AFTER `channel`;

UPDATE `contacts`
SET `channel` = 'whatsapp'
WHERE `channel` IS NULL OR `channel` = '';

UPDATE `contacts`
SET `external_id` = `mobile`
WHERE (`external_id` IS NULL OR `external_id` = '')
  AND `mobile` IS NOT NULL
  AND `mobile` != '';

-- 2) conversations  (this fixes the Live Chat toast error)
ALTER TABLE `conversations`
  ADD COLUMN `channel` VARCHAR(20) NOT NULL DEFAULT 'whatsapp' AFTER `contact_id`;

ALTER TABLE `conversations`
  ADD COLUMN `page_id` VARCHAR(64) NULL DEFAULT NULL AFTER `channel`;

UPDATE `conversations`
SET `channel` = 'whatsapp'
WHERE `channel` IS NULL OR `channel` = '';

-- 3) messages
ALTER TABLE `messages`
  ADD COLUMN `channel` VARCHAR(20) NOT NULL DEFAULT 'whatsapp' AFTER `conversation_id`;

ALTER TABLE `messages`
  ADD COLUMN `external_message_id` VARCHAR(191) NULL DEFAULT NULL AFTER `wamid`;

UPDATE `messages`
SET `channel` = 'whatsapp'
WHERE `channel` IS NULL OR `channel` = '';

UPDATE `messages`
SET `external_message_id` = COALESCE(NULLIF(`wamid`, ''), NULLIF(`wa_message_id`, ''))
WHERE (`external_message_id` IS NULL OR `external_message_id` = '');
