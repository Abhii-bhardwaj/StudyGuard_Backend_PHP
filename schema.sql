-- ============================================================
-- StudyGuard — MySQL Schema (Phase 1)
-- Database: studyguard
-- Engine:   InnoDB (transactional, FK support)
-- Charset:  utf8mb4 (full Unicode)
-- ============================================================

-- Run this file in phpMyAdmin → SQL tab, or via CLI:
--   mysql -u root -p studyguard < schema.sql

CREATE DATABASE IF NOT EXISTS `studyguard`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `studyguard`;

-- ────────────────────────────────────────────────────────────────
-- 1. SESSIONS TABLE
--    One row per study session started by the Chrome Extension.
--    session_id = epoch‑ms timestamp that the extension generates.
-- ────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `sessions` (
  `id`                  INT          NOT NULL AUTO_INCREMENT,
  `user_id`             INT          NOT NULL DEFAULT 1,
  `session_id`          BIGINT       NOT NULL,
  `start_time`          DATETIME     NULL,
  `end_time`            DATETIME     NULL,
  `duration_min`        FLOAT        DEFAULT 0,
  `focus_score`         FLOAT        NULL,
  `patience_index`      FLOAT        NULL,
  `distraction_pct`     FLOAT        NULL,
  `intervention_count`  INT          DEFAULT 0,
  `longest_streak_min`  FLOAT        DEFAULT 0,
  `focus_time`          FLOAT        DEFAULT 0,
  `distraction_count`   INT          DEFAULT 0,
  `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_session_id` (`session_id`),
  INDEX `idx_session_user` (`user_id`),
  INDEX `idx_session_start` (`start_time`),
  INDEX `idx_session_focus`  (`focus_score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ────────────────────────────────────────────────────────────────
-- 2. SNAPSHOTS TABLE
--    One row per 30‑second behavioral snapshot.
--    Contains ALL 13 ONNX model features + DLS output + tier.
-- ────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snapshots` (
  `id`                   INT     NOT NULL AUTO_INCREMENT,
  `user_id`              INT     NOT NULL DEFAULT 1,
  `session_id`           BIGINT  NOT NULL,
  `timestamp`            BIGINT  NOT NULL,
  `dls`                  FLOAT   NOT NULL DEFAULT 0,
  `tier`                 INT     NOT NULL DEFAULT 0,
  `focus_score`          FLOAT   NULL,
  `is_distracted`        TINYINT NOT NULL DEFAULT 0,

  -- ONNX Feature [0]: tab switches per minute
  `tab_switch_freq`      FLOAT   NOT NULL DEFAULT 0,
  -- ONNX Feature [1]: average milliseconds between rapid tab switches
  `tab_switch_speed`     FLOAT   NOT NULL DEFAULT 0,
  -- ONNX Feature [2]: number of rapid‑burst tab switches
  `burst_switch_count`   FLOAT   NOT NULL DEFAULT 0,
  -- ONNX Feature [3]: fraction of idle time in window
  `idle_duration`        FLOAT   NOT NULL DEFAULT 0,
  -- ONNX Feature [4]: scroll speed standard deviation
  `scroll_irregularity`  FLOAT   NOT NULL DEFAULT 0,
  -- ONNX Feature [5]: rate of scroll acceleration changes
  `scroll_jerk`          FLOAT   NOT NULL DEFAULT 0,
  -- ONNX Feature [6]: typing interval standard deviation
  `keystroke_variance`   FLOAT   NOT NULL DEFAULT 0,
  -- ONNX Feature [7]: average mouse cursor speed
  `mouse_velocity`       FLOAT   NOT NULL DEFAULT 0,
  -- ONNX Feature [8]: re‑visits to known distraction domains
  `domain_revisit_freq`  FLOAT   NOT NULL DEFAULT 0,
  -- ONNX Feature [9]: reaction time to notifications
  `notif_open_speed`     FLOAT   NOT NULL DEFAULT 0,
  -- ONNX Feature [10]: circadian productivity weight
  `time_of_day_weight`   FLOAT   NOT NULL DEFAULT 0,
  -- ONNX Feature [11]: alt‑tab / window‑switch frequency
  `app_switch_freq`      FLOAT   NOT NULL DEFAULT 0,
  -- ONNX Feature [12]: total seconds away from study window
  `app_switch_duration`  FLOAT   NOT NULL DEFAULT 0,

  `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  INDEX `idx_snap_session_time` (`session_id`, `timestamp`),
  INDEX `idx_snap_timestamp` (`timestamp`),
  INDEX `idx_snap_dls`       (`dls`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ────────────────────────────────────────────────────────────────
-- 3. MODEL WEIGHTS TABLE
--    Stores fallback weighted‑sum coefficients used when ONNX
--    inference fails. Versioned: every update = new row.
-- ────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `model_weights` (
  `id`                   INT     NOT NULL AUTO_INCREMENT,
  `tab_switch_freq`      FLOAT   NOT NULL DEFAULT 0.30,
  `idle_duration`        FLOAT   NOT NULL DEFAULT 0.20,
  `scroll_irregularity`  FLOAT   NOT NULL DEFAULT 0.15,
  `keystroke_variance`   FLOAT   NOT NULL DEFAULT 0.15,
  `domain_revisit_freq`  FLOAT   NOT NULL DEFAULT 0.15,
  `time_of_day_weight`   FLOAT   NOT NULL DEFAULT 0.05,
  `updated_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default weights (sum = 1.00)
INSERT INTO `model_weights`
  (`tab_switch_freq`, `idle_duration`, `scroll_irregularity`,
   `keystroke_variance`, `domain_revisit_freq`, `time_of_day_weight`)
VALUES
  (0.30, 0.20, 0.15, 0.15, 0.15, 0.05);


-- ────────────────────────────────────────────────────────────────
-- 4. USERS TABLE
--    For Phase 3 admin authentication. Created now so schema
--    is complete and ready for the admin dashboard.
-- ────────────────────────────────────────────────────────────────

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

-- Seed a default admin user (password: admin123)
-- Generated via: password_hash('admin123', PASSWORD_BCRYPT)
INSERT INTO `users` (`username`, `password_hash`, `email`, `role`)
VALUES (
  'admin',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'admin@studyguard.local',
  'admin'
);

-- ────────────────────────────────────────────────────────────────
-- 5. AUTH TOKENS TABLE
--    For JWT Refresh token storage and revocation
-- ────────────────────────────────────────────────────────────────

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
