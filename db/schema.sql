-- ============================================================
--  HOPELINE — full database (schema + demo data)
--  Engine: InnoDB | Charset: utf8mb4 | Target: MariaDB 10.4+ (XAMPP)
--  Demo logins (password for all: password123)
--    admin@hopeline.local / manager@hopeline.local / user@hopeline.local
--
--  WARNING: this script DROPs and recreates every table.
--  For an existing database, use the ALTER statements instead.
-- ============================================================

CREATE DATABASE IF NOT EXISTS `hopeline`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `hopeline`;

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- Old view is no longer used by any page
DROP VIEW IF EXISTS `v_incident_timeline`;

-- 1. users
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`          int(11)       NOT NULL AUTO_INCREMENT,
  `name`        varchar(150)  NOT NULL,
  `email`       varchar(150)  NOT NULL,
  `password`    varchar(255)  NOT NULL,
  `role`        enum('admin','manager','user') NOT NULL DEFAULT 'user',
  `contact_no`  varchar(20)   DEFAULT NULL,
  `is_verified` tinyint(1)    NOT NULL DEFAULT 0,
  `archived_at` datetime      DEFAULT NULL,
  `created_at`  datetime      DEFAULT current_timestamp(),
  `updated_at`  datetime      DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_archived_at` (`archived_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. settings (settings.php also creates/seeds this on first visit)
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `setting_key`   varchar(100) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at`    datetime     DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. activity_log
--    NOTE: includes/activity-logger.php must INSERT INTO `activity_log`
--    (singular). It currently writes to `activity_logs`, which doesn't exist.
DROP TABLE IF EXISTS `activity_log`;
CREATE TABLE `activity_log` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`    int(11)      DEFAULT NULL,
  `email`      varchar(150) DEFAULT NULL,
  `action`     varchar(100) NOT NULL,
  `status`     varchar(20)  NOT NULL,
  `ip_address` varchar(45)  DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime     DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_activity_user` (`user_id`),
  KEY `idx_activity_created_at` (`created_at`),
  KEY `idx_activity_action` (`action`),
  KEY `idx_activity_email` (`email`),
  CONSTRAINT `fk_activity_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. clip_reports
DROP TABLE IF EXISTS `clip_reports`;
CREATE TABLE `clip_reports` (
  `id`                    int(11)       NOT NULL AUTO_INCREMENT,
  `clip_ref`              varchar(30)   NOT NULL,
  `caller_name`           varchar(150)  NOT NULL,
  `caller_contact`        varchar(20)   DEFAULT NULL,
  `barangay`              varchar(100)  NOT NULL,
  `sitio_purok`           varchar(150)  DEFAULT NULL,
  `landmark`              varchar(255)  DEFAULT NULL,
  `latitude`              decimal(10,7) DEFAULT NULL,
  `longitude`             decimal(10,7) DEFAULT NULL,
  `incident_type`         varchar(50)   NOT NULL,
  `severity`              enum('Critical','High','Moderate','Low') DEFAULT NULL,
  `problem_resources`     varchar(255)  NOT NULL,
  `problem_notes`         text          DEFAULT NULL,
  `predicted_eta_minutes` decimal(6,2)  DEFAULT NULL,   -- NEW: saved from the CLIP form's eta_minutes
  `status`                enum('pending','dispatched','resolved','cancelled') NOT NULL DEFAULT 'pending',
  `archived_at`           datetime      DEFAULT NULL,
  `reported_by`           int(11)       NOT NULL,
  `created_at`            datetime      DEFAULT current_timestamp(),
  `updated_at`            datetime      DEFAULT current_timestamp() ON UPDATE current_timestamp(),
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

-- 5. ptv_units (last_ping_at removed: code uses last_location_at)
DROP TABLE IF EXISTS `ptv_units`;
CREATE TABLE `ptv_units` (
  `id`               int(11)       NOT NULL AUTO_INCREMENT,
  `unit_name`        varchar(100)  NOT NULL,
  `plate_no`         varchar(20)   DEFAULT NULL,
  `driver_name`      varchar(150)  DEFAULT NULL,
  `responder_id`     int(11)       DEFAULT NULL,
  `status`           enum('Available','En Route','On Site','Returning','Offline') NOT NULL DEFAULT 'Available',
  `archived_at`      datetime      DEFAULT NULL,
  `current_lat`      decimal(10,7) DEFAULT NULL,
  `current_lng`      decimal(10,7) DEFAULT NULL,
  `last_location_at` datetime      DEFAULT NULL,
  `gps_accuracy`     float         DEFAULT NULL,
  `gps_heading`      float         DEFAULT NULL,
  `gps_speed`        float         DEFAULT NULL,
  `updated_at`       datetime      DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at`       datetime      DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_units_responder` (`responder_id`),
  KEY `idx_units_status` (`status`),
  KEY `idx_units_archived_at` (`archived_at`),
  CONSTRAINT `fk_units_responder`
    FOREIGN KEY (`responder_id`) REFERENCES `users` (`id`)
    ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. dispatch (distance_km removed: unused)
--    active_unit_key: equals unit_id while the dispatch is active, NULL once
--    resolved/cancelled. The UNIQUE index on it means one unit can never have
--    two active dispatches, which blocks the reload-duplicates bug at DB level.
--    (NULLs are ignored by unique indexes, so finished dispatches never clash.)
DROP TABLE IF EXISTS `dispatch`;
CREATE TABLE `dispatch` (
  `id`                    int(11)      NOT NULL AUTO_INCREMENT,
  `clip_report_id`        int(11)      NOT NULL,
  `unit_id`               int(11)      NOT NULL,
  `dispatched_by`         int(11)      NOT NULL,
  `status`                enum('assigned','en_route','on_site','returning','resolved','cancelled') NOT NULL DEFAULT 'assigned',
  `predicted_eta_minutes` decimal(6,2) DEFAULT NULL,
  `dispatched_at`         datetime     DEFAULT current_timestamp(),
  `departed_at`           datetime     DEFAULT NULL,
  `arrived_at`            datetime     DEFAULT NULL,
  `returned_at`           datetime     DEFAULT NULL,
  `resolved_at`           datetime     DEFAULT NULL,
  `patient_name`          varchar(150) DEFAULT NULL,
  `patient_age_group`     enum('Minor','Matured','Senior') DEFAULT NULL,
  `patient_sex`           enum('Male','Female') DEFAULT NULL,
  `victim_count`          int(11)      DEFAULT NULL,
  `vital_signs`           enum('Positive','Negative') DEFAULT NULL,
  `alcohol_breath`        enum('Positive','Negative','Not Tested') DEFAULT NULL,
  `closeout_remarks`      varchar(255) DEFAULT NULL,
  `incident_photo`        varchar(255) DEFAULT NULL,
  `incident_details`      text         DEFAULT NULL,
  `active_unit_key`       int(11)
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

-- 7. delay_logs
DROP TABLE IF EXISTS `delay_logs`;
CREATE TABLE `delay_logs` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `dispatch_id` int(11)      NOT NULL,
  `unit_id`     int(11)      NOT NULL,
  `reason`      enum(
                  'Road obstruction/traffic',
                  'Vehicle breakdown/mechanical issue',
                  'Weather/flooding',
                  'Wrong/unclear location',
                  'Fuel issue',
                  'Waiting for backup unit',
                  'Unit unreachable',
                  'Other'
                ) NOT NULL,
  `notes`       varchar(255) DEFAULT NULL,
  `logged_by`   int(11)      DEFAULT NULL,
  `is_manual`   tinyint(1)   NOT NULL DEFAULT 0,
  `started_at`  datetime     DEFAULT current_timestamp(),
  `resolved_at` datetime     DEFAULT NULL,
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
--  SEED: demo accounts + one PTV unit (password: password123)
--  Change or remove before production.
-- ============================================================
INSERT INTO `users` (`name`, `email`, `password`, `role`, `contact_no`, `is_verified`) VALUES
  ('System Administrator', 'admin@hopeline.local',
   '$2y$10$i/gPUBdIyWYHOw1CHaS.3.bMDfg7N4zjQ2qCQmdbQHHy5ytXTyPsG', 'admin',   '09170000001', 1),
  ('Dispatch Manager',     'manager@hopeline.local',
   '$2y$10$O/YbHs.v/iEzxCQHv/KMnuVEcoxXL/UdrS4Ka24gVTgUGIxNyKLHa', 'manager', '09170000002', 1),
  ('Responder One',        'user@hopeline.local',
   '$2y$10$TOA3EkeQZzdLt2mVJGLqEOS03MitUPr9.T.wdUObMGCE3SPDi0Dla', 'user',    '09170000003', 1);

-- Demo PTV unit linked to the demo responder, parked at the LDRRMO base
INSERT INTO `ptv_units` (`unit_name`, `plate_no`, `responder_id`, `status`, `current_lat`, `current_lng`)
SELECT 'PTV-1', 'LGU-1234', `id`, 'Available', 8.3717147, 124.8571756
FROM `users` WHERE `email` = 'user@hopeline.local';