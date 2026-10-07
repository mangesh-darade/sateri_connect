-- =====================================================================
-- Sateri Connect — 2026-10-07 Email compliance (safe to run any number of times)
--
--   email_html_campaigns.status : adds 'paused' (campaigns auto-paused when the
--                                 bounce rate > 5% or complaint rate > 0.1%)
--
-- The app also applies this automatically (EmailHtmlCampaignModel::ensureStatusEnum).
-- Settings key `email_company_address` (group 'email') needs no schema change.
--
-- How to run: select the client (tenant) database → phpMyAdmin SQL tab → paste all → Go.
-- =====================================================================

DROP PROCEDURE IF EXISTS sc_email_campaign_paused_status;

DELIMITER $$

CREATE PROCEDURE sc_email_campaign_paused_status()
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_html_campaigns'
                 AND COLUMN_NAME = 'status' AND COLUMN_TYPE NOT LIKE '%''paused''%') THEN
        ALTER TABLE `email_html_campaigns`
            MODIFY `status` ENUM('draft','queued','sending','sent','failed','cancelled','paused')
            NOT NULL DEFAULT 'draft';
    END IF;
END$$

DELIMITER ;

CALL sc_email_campaign_paused_status();
DROP PROCEDURE IF EXISTS sc_email_campaign_paused_status;
