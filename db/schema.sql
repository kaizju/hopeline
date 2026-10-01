-- ============================================================
--  HOPELINE — full MySQL schema (structure + demo ACCOUNTS only)
--  Engine: InnoDB | Charset: utf8mb4 | Target: MySQL 5.7+ / 8.x / MariaDB 10.4+
--
--  Demo logins (password for all: password123)
--    admin@hopeline.local
--    manager@hopeline.local
--    user@hopeline.local   (Responder; has no PTV unit until an admin creates one)
--
--  No incidents, units, delays, settings or logs are seeded.
--  (settings.php seeds its own defaults on first visit.)
--
--  WARNING: this script DROPs and recreates every table.
-- ============================================================

CREATE DATABASE IF NOT EXISTS `hopeline`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `hopeline`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW  IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `delay_logs`;
DROP TABLE IF EXISTS `dispatch`;
DROP TABLE IF EXISTS `ptv_units`;
DROP TABLE IF EXISTS `clip_reports`;
DROP TABLE IF EXISTS `activity_log`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `users`;

-- ------------------------------------------------------------
-- 1. users  (roles: admin | manager | user = Barangay Responder)
--    is_verified = 1 means "active" (login requires it)
--    archived_at IS NOT NULL means archived (login blocked)
-- ------------------------------------------------------------
CREATE TABLE `users` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(150) NOT NULL,
  `email`       VARCHAR(150) NOT NULL,
  `password`    VARCHAR(255) NOT NULL,
  `role`        ENUM('admin','manager','user') NOT NULL DEFAULT 'user',
  `contact_no`  VARCHAR(20)  DEFAULT NULL,
  `is_verified` TINYINT(1)   NOT NULL DEFAULT 0,
  `archived_at` DATETIME     DEFAULT NULL,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_archived_at` (`archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. settings  (key/value config used by admin/settings.php)
-- ------------------------------------------------------------
CREATE TABLE `settings` (
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` VARCHAR(255) NOT NULL,
  `updated_at`    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. activity_log  (audit trail; read by admin dashboard + audit-trail.php)
-- ------------------------------------------------------------
CREATE TABLE `activity_log` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `user_id`    INT          DEFAULT NULL,
  `email`      VARCHAR(150) DEFAULT NULL,
  `action`     VARCHAR(100) NOT NULL,
  `status`     VARCHAR(20)  NOT NULL,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_user` (`user_id`),
  KEY `idx_activity_created_at` (`created_at`),
  KEY `idx_activity_action` (`action`),
  KEY `idx_activity_email` (`email`),
  CONSTRAINT `fk_activity_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- includes/activity-logger.php writes to `activity_logs` (plural) while the
-- admin pages read `activity_log` (singular). This simple, insertable view
-- makes both names work without touching the PHP.
CREATE VIEW `activity_logs` AS SELECT * FROM `activity_log`;

-- ------------------------------------------------------------
-- 4. clip_reports  (Caller · Location · Incident · Problem)
-- ------------------------------------------------------------
CREATE TABLE `clip_reports` (
  `id`                    INT           NOT NULL AUTO_INCREMENT,
  `clip_ref`              VARCHAR(30)   NOT NULL,
  `caller_name`           VARCHAR(150)  NOT NULL,
  `caller_contact`        VARCHAR(20)   DEFAULT NULL,
  `barangay`              VARCHAR(100)  NOT NULL,
  `sitio_purok`           VARCHAR(150)  DEFAULT NULL,
  `landmark`              VARCHAR(255)  DEFAULT NULL,
  `latitude`              DECIMAL(10,7) DEFAULT NULL,
  `longitude`             DECIMAL(10,7) DEFAULT NULL,
  `incident_type`         VARCHAR(50)   NOT NULL,
  `severity`              ENUM('Critical','High','Moderate','Low') DEFAULT NULL,
  `problem_resources`     VARCHAR(255)  NOT NULL,
  `problem_notes`         TEXT          DEFAULT NULL,
  `predicted_eta_minutes` DECIMAL(6,2)  DEFAULT NULL,
  `status`                ENUM('pending','dispatched','resolved','cancelled') NOT NULL DEFAULT 'pending',
  `archived_at`           DATETIME      DEFAULT NULL,
  `reported_by`           INT           NOT NULL,
  `created_at`            DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clip_ref` (`clip_ref`),
  KEY `idx_clip_reported_by` (`reported_by`),
  KEY `idx_clip_status` (`status`),
  KEY `idx_clip_barangay` (`barangay`),
  KEY `idx_clip_created_at` (`created_at`),
  KEY `idx_clip_archived_at` (`archived_at`),
  KEY `idx_clip_severity` (`severity`),
  CONSTRAINT `fk_clip_reported_by`
    FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. ptv_units
--    'Assigned' is included because active-incidents.php sets it on dispatch.
-- ------------------------------------------------------------
CREATE TABLE `ptv_units` (
  `id`               INT           NOT NULL AUTO_INCREMENT,
  `unit_name`        VARCHAR(100)  NOT NULL,
  `plate_no`         VARCHAR(20)   DEFAULT NULL,
  `driver_name`      VARCHAR(150)  DEFAULT NULL,
  `responder_id`     INT           DEFAULT NULL,
  `status`           ENUM('Available','Assigned','En Route','On Site','Returning','Offline') NOT NULL DEFAULT 'Available',
  `archived_at`      DATETIME      DEFAULT NULL,
  `current_lat`      DECIMAL(10,7) DEFAULT NULL,
  `current_lng`      DECIMAL(10,7) DEFAULT NULL,
  `last_location_at` DATETIME      DEFAULT NULL,
  `gps_accuracy`     FLOAT         DEFAULT NULL,
  `gps_heading`      FLOAT         DEFAULT NULL,
  `gps_speed`        FLOAT         DEFAULT NULL,
  `created_at`       DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_units_responder` (`responder_id`),
  KEY `idx_units_status` (`status`),
  KEY `idx_units_archived_at` (`archived_at`),
  CONSTRAINT `fk_units_responder`
    FOREIGN KEY (`responder_id`) REFERENCES `users` (`id`)
    ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. dispatch
--    active_unit_key = unit_id while the dispatch is active, NULL once
--    resolved/cancelled. The UNIQUE index means a unit can never have two
--    active dispatches (blocks reload-duplicate dispatches at DB level).
-- ------------------------------------------------------------
CREATE TABLE `dispatch` (
  `id`                    INT          NOT NULL AUTO_INCREMENT,
  `clip_report_id`        INT          NOT NULL,
  `unit_id`               INT          NOT NULL,
  `dispatched_by`         INT          NOT NULL,
  `status`                ENUM('assigned','en_route','on_site','returning','resolved','cancelled') NOT NULL DEFAULT 'assigned',
  `predicted_eta_minutes` DECIMAL(6,2) DEFAULT NULL,
  `dispatched_at`         DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `departed_at`           DATETIME     DEFAULT NULL,
  `arrived_at`            DATETIME     DEFAULT NULL,
  `returned_at`           DATETIME     DEFAULT NULL,
  `resolved_at`           DATETIME     DEFAULT NULL,
  `patient_name`          VARCHAR(150) DEFAULT NULL,
  `patient_age_group`     ENUM('Minor','Matured','Senior') DEFAULT NULL,
  `patient_sex`           ENUM('Male','Female') DEFAULT NULL,
  `victim_count`          INT          DEFAULT NULL,
  `vital_signs`           ENUM('Positive','Negative') DEFAULT NULL,
  `alcohol_breath`        ENUM('Positive','Negative','Not Tested') DEFAULT NULL,
  `closeout_remarks`      VARCHAR(255) DEFAULT NULL,
  `incident_details`      TEXT         DEFAULT NULL,
  `incident_photo`        VARCHAR(255) DEFAULT NULL,
  `active_unit_key`       INT
      AS (IF(`status` IN ('assigned','en_route','on_site','returning'), `unit_id`, NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dispatch_active_unit` (`active_unit_key`),
  KEY `idx_dispatch_unit_status` (`unit_id`, `status`),
  KEY `idx_dispatch_clip_status` (`clip_report_id`, `status`),
  KEY `idx_dispatch_by` (`dispatched_by`),
  KEY `idx_dispatch_status` (`status`),
  KEY `idx_dispatch_dispatched_at` (`dispatched_at`),
  CONSTRAINT `fk_dispatch_clip_report`
    FOREIGN KEY (`clip_report_id`) REFERENCES `clip_reports` (`id`)
    ON UPDATE RESTRICT ON DELETE CASCADE,
  CONSTRAINT `fk_dispatch_unit`
    FOREIGN KEY (`unit_id`) REFERENCES `ptv_units` (`id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_dispatch_user`
    FOREIGN KEY (`dispatched_by`) REFERENCES `users` (`id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. delay_logs
-- ------------------------------------------------------------
CREATE TABLE `delay_logs` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `dispatch_id` INT          NOT NULL,
  `unit_id`     INT          NOT NULL,
  `reason`      ENUM(
                  'Road obstruction/traffic',
                  'Vehicle breakdown/mechanical issue',
                  'Weather/flooding',
                  'Wrong/unclear location',
                  'Fuel issue',
                  'Waiting for backup unit',
                  'Unit unreachable',
                  'Other'
                ) NOT NULL,
  `notes`       VARCHAR(255) DEFAULT NULL,
  `logged_by`   INT          DEFAULT NULL,
  `is_manual`   TINYINT(1)   NOT NULL DEFAULT 0,
  `started_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_delay_dispatch_resolved` (`dispatch_id`, `resolved_at`),
  KEY `idx_delay_unit` (`unit_id`),
  KEY `idx_delay_logged_by` (`logged_by`),
  KEY `idx_delay_started_at` (`started_at`),
  CONSTRAINT `fk_delay_dispatch`
    FOREIGN KEY (`dispatch_id`) REFERENCES `dispatch` (`id`)
    ON UPDATE RESTRICT ON DELETE CASCADE,
  CONSTRAINT `fk_delay_unit`
    FOREIGN KEY (`unit_id`) REFERENCES `ptv_units` (`id`)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `fk_delay_logged_by`
    FOREIGN KEY (`logged_by`) REFERENCES `users` (`id`)
    ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  SEED: demo accounts ONLY (password: password123)
-- ============================================================
INSERT INTO `users` (`name`, `email`, `password`, `role`, `contact_no`, `is_verified`) VALUES
  ('System Administrator', 'admin@hopeline.local',
   '$2y$10$i/gPUBdIyWYHOw1CHaS.3.bMDfg7N4zjQ2qCQmdbQHHy5ytXTyPsG', 'admin',   '09170000001', 1),
  ('Dispatch Manager',     'manager@hopeline.local',
   '$2y$10$O/YbHs.v/iEzxCQHv/KMnuVEcoxXL/UdrS4Ka24gVTgUGIxNyKLHa', 'manager', '09170000002', 1),
  ('Responder One',        'user@hopeline.local',
   '$2y$10$TOA3EkeQZzdLt2mVJGLqEOS03MitUPr9.T.wdUObMGCE3SPDi0Dla', 'user',    '09170000003', 1);