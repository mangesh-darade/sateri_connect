-- Master DB (sateri_master): Meta data-deletion callback audit trail.
-- Also auto-created by MetaDataDeletionService::ensureTable() and `php spark tenant:ensure-master`.
CREATE TABLE IF NOT EXISTS `data_deletion_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `confirmation_code` VARCHAR(40) NOT NULL,
  `fb_user_id` VARCHAR(64) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'received',
  `notes` TEXT NULL,
  `requested_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `confirmation_code` (`confirmation_code`),
  KEY `fb_user_id` (`fb_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
