-- ============================================================
-- StudyGuard — Auth Migration Script
-- Run this in phpMyAdmin → SQL tab to add auth columns
-- to your EXISTING database tables.
-- ============================================================

USE `studyguard`;

-- 1. Create users table if it doesn't exist
CREATE TABLE IF NOT EXISTS `users` (
  `id`             INT          NOT NULL AUTO_INCREMENT,
  `username`       VARCHAR(50)  NOT NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `email`          VARCHAR(100) NULL,
  `role`           ENUM('user','admin','researcher') NOT NULL DEFAULT 'user',
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email`    (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1b. Update users table role enum
ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('user','admin','researcher') NOT NULL DEFAULT 'user';

-- 2. Add user_id to sessions (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
  WHERE TABLE_SCHEMA='studyguard' AND TABLE_NAME='sessions' AND COLUMN_NAME='user_id');
SET @sql = IF(@col_exists = 0, 
  'ALTER TABLE `sessions` ADD COLUMN `user_id` INT NOT NULL DEFAULT 1 AFTER `id`, ADD INDEX `idx_session_user` (`user_id`)', 
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Add focus_time to sessions (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
  WHERE TABLE_SCHEMA='studyguard' AND TABLE_NAME='sessions' AND COLUMN_NAME='focus_time');
SET @sql = IF(@col_exists = 0, 
  'ALTER TABLE `sessions` ADD COLUMN `focus_time` FLOAT DEFAULT 0 AFTER `longest_streak_min`', 
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Add distraction_count to sessions (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
  WHERE TABLE_SCHEMA='studyguard' AND TABLE_NAME='sessions' AND COLUMN_NAME='distraction_count');
SET @sql = IF(@col_exists = 0, 
  'ALTER TABLE `sessions` ADD COLUMN `distraction_count` INT DEFAULT 0 AFTER `focus_time`', 
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Add user_id to snapshots (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
  WHERE TABLE_SCHEMA='studyguard' AND TABLE_NAME='snapshots' AND COLUMN_NAME='user_id');
SET @sql = IF(@col_exists = 0, 
  'ALTER TABLE `snapshots` ADD COLUMN `user_id` INT NOT NULL DEFAULT 1 AFTER `id`', 
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 6. Add is_distracted to snapshots (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
  WHERE TABLE_SCHEMA='studyguard' AND TABLE_NAME='snapshots' AND COLUMN_NAME='is_distracted');
SET @sql = IF(@col_exists = 0, 
  'ALTER TABLE `snapshots` ADD COLUMN `is_distracted` TINYINT NOT NULL DEFAULT 0 AFTER `focus_score`', 
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 7. Add auth_tokens table
CREATE TABLE IF NOT EXISTS `auth_tokens` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_token_user` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Migration complete!' AS status;
