-- =====================================================================
-- Sateri Connect — 2026-10-07 WhatsApp webhook idempotency (safe to run any number of times)
--
--   messages.wa_inbound_id : virtual column = wa_message_id for inbound rows (NULL for outbound)
--   uniq_messages_wa_inbound_id : UNIQUE, so a Meta webhook retry can never store / process
--                                 the same inbound message twice
--
-- Outbound rows are not constrained (wa_inbound_id is NULL for them).
-- Same as the model self-heal (app/Database/Schema/tables.php → messages).
--
-- How to run: select the client (tenant) database → phpMyAdmin SQL tab → paste all → Go.
-- Step 1 removes existing duplicate inbound rows (keeps the oldest copy, repoints conversations).
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
-- 1. Remove duplicate inbound messages (same wa_message_id stored twice by webhook retries)
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS sc_dup_inbound;
CREATE TEMPORARY TABLE sc_dup_inbound AS
SELECT m.id AS dup_id, k.keep_id
FROM `messages` m
JOIN (
    SELECT wa_message_id, MIN(id) AS keep_id
    FROM `messages`
    WHERE direction = 'inbound' AND wa_message_id IS NOT NULL
    GROUP BY wa_message_id
    HAVING COUNT(*) > 1
) k ON k.wa_message_id = m.wa_message_id
WHERE m.direction = 'inbound' AND m.id <> k.keep_id;

UPDATE `conversations` c
JOIN sc_dup_inbound d ON d.dup_id = c.last_message_id
SET c.last_message_id = d.keep_id;

DELETE m FROM `messages` m
JOIN sc_dup_inbound d ON d.dup_id = m.id;

DROP TEMPORARY TABLE IF EXISTS sc_dup_inbound;

-- ---------------------------------------------------------------------
-- 2. Inbound-only id column + unique index
-- ---------------------------------------------------------------------
CALL sc_add_column('messages', 'wa_inbound_id', "varchar(191) COLLATE utf8mb4_general_ci GENERATED ALWAYS AS (if((`direction` = 'inbound'),`wa_message_id`,NULL)) VIRTUAL");
CALL sc_add_index('messages', 'uniq_messages_wa_inbound_id', 'UNIQUE KEY `uniq_messages_wa_inbound_id` (`wa_inbound_id`)');

DROP PROCEDURE IF EXISTS sc_add_column;
DROP PROCEDURE IF EXISTS sc_add_index;

-- ---------------------------------------------------------------------
-- Check (optional): one row for the column, one for the index
-- ---------------------------------------------------------------------
SELECT COLUMN_NAME, COLUMN_TYPE, GENERATION_EXPRESSION
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'wa_inbound_id';

SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND INDEX_NAME = 'uniq_messages_wa_inbound_id';
