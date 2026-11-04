-- =====================================================================
-- HopeLine — full schema + demo data  (UPDATED to match current code)
-- Target: MariaDB 10.4+ / MySQL 8 (XAMPP), InnoDB, utf8mb4
-- Import into an empty database:  mysql -u root < hopeline_schema.sql
--
-- Already have a database with data? Don't re-import this file.
-- Run hopeline_migration.sql instead (adds only what is missing).
--
-- DEMO LOGINS (passwords are bcrypt-hashed below)
--   admin@hopeline.local     Admin@123       (role: admin)
--   manager@hopeline.local   Manager@123     (role: manager)
--   user@hopeline.local      Responder@123   (role: user, leads Team 1)
--   user2@hopeline.local     Responder@123   (role: user, leads Team 2)
-- Change these passwords before any real deployment.
--
-- NOTE: the status value 'resolved' is stored as-is. The UI shows it as
-- "Completed" through statusLabel() in config/functions.php.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS hopeline
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE hopeline;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. users
--    team_id has no FK: teams.leader_id already points back at users,
--    so a second FK would be circular.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id          INT(11)      NOT NULL AUTO_INCREMENT,
  name        VARCHAR(150) NOT NULL,
  email       VARCHAR(150) NOT NULL,
  password    VARCHAR(255) NOT NULL,
  role        ENUM('admin','manager','user') NOT NULL DEFAULT 'user',
  contact_no  VARCHAR(20)  DEFAULT NULL,
  is_verified TINYINT(1)   NOT NULL DEFAULT 0,
  archived_at DATETIME     DEFAULT NULL,
  created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  team_id     INT(11)      DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_team (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 2. teams / team_members
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS teams (
  id          INT(11)      NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  archived_at DATETIME     DEFAULT NULL,
  created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  leader_id   INT(11)      DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_teams_leader (leader_id),
  CONSTRAINT fk_teams_leader FOREIGN KEY (leader_id)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS team_members (
  id      INT(11)      NOT NULL AUTO_INCREMENT,
  team_id INT(11)      NOT NULL,
  name    VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_team_members_team (team_id),
  CONSTRAINT fk_team_members_team FOREIGN KEY (team_id)
    REFERENCES teams (id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 3. ptv_units  (every vehicle: Ambulance, PTV, Water Tanker, ...)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ptv_units (
  id               INT(11)      NOT NULL AUTO_INCREMENT,
  unit_name        VARCHAR(100) NOT NULL,
  plate_no         VARCHAR(20)  DEFAULT NULL,
  vehicle_type     VARCHAR(50)  NOT NULL DEFAULT 'Patient Transport Vehicle (PTV)',
  driver_name      VARCHAR(150) DEFAULT NULL,
  responder_id     INT(11)      DEFAULT NULL,
  status           ENUM('Available','Assigned','En Route','On Site','Returning','Offline') NOT NULL DEFAULT 'Available',
  archived_at      DATETIME     DEFAULT NULL,
  current_lat      DECIMAL(10,7) DEFAULT NULL,
  current_lng      DECIMAL(10,7) DEFAULT NULL,
  last_location_at DATETIME     DEFAULT NULL,
  gps_accuracy     FLOAT        DEFAULT NULL,
  gps_heading      FLOAT        DEFAULT NULL,
  gps_speed        FLOAT        DEFAULT NULL,
  created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_units_responder (responder_id),
  KEY idx_units_type_status (vehicle_type, status),
  CONSTRAINT fk_units_responder FOREIGN KEY (responder_id)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 4. clip_reports
--    NEW: report_source, geo_barangay, geo_purok, geo_address
--    sitio_purok is legacy. The forms no longer write it, but
--    api/incident-pins.php and live-map-incidents.js still read it,
--    so it stays (nullable) until those two are cleaned up.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clip_reports (
  id                    INT(11)      NOT NULL AUTO_INCREMENT,
  clip_ref              VARCHAR(30)  NOT NULL,
  caller_name           VARCHAR(150) NOT NULL,
  caller_contact        VARCHAR(20)  DEFAULT NULL,
  barangay              VARCHAR(100) NOT NULL,            -- holds the Purok name
  report_source         ENUM('Phone Call','Walk-in') NOT NULL DEFAULT 'Phone Call',
  sitio_purok           VARCHAR(150) DEFAULT NULL,        -- legacy
  landmark              VARCHAR(255) DEFAULT NULL,
  latitude              DECIMAL(10,7) DEFAULT NULL,
  longitude             DECIMAL(10,7) DEFAULT NULL,
  geo_barangay          VARCHAR(100) DEFAULT NULL,        -- reverse-geocode cache
  geo_purok             VARCHAR(100) DEFAULT NULL,
  geo_address           VARCHAR(255) DEFAULT NULL,
  incident_type         VARCHAR(50)  NOT NULL,
  severity              ENUM('Critical','High','Moderate','Low') DEFAULT NULL,
  problem_resources     VARCHAR(255) NOT NULL,            -- e.g. "Ambulance x1, Fire Truck x1"
  problem_notes         TEXT         DEFAULT NULL,
  predicted_eta_minutes DECIMAL(6,2) DEFAULT NULL,
  status                ENUM('pending','dispatched','resolved','cancelled') NOT NULL DEFAULT 'pending',
  archived_at           DATETIME     DEFAULT NULL,
  reported_by           INT(11)      NOT NULL,
  created_at            DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clip_ref (clip_ref),
  KEY idx_clip_status (status),
  KEY idx_clip_reported_by (reported_by),
  KEY idx_clip_created (created_at),
  KEY idx_clip_barangay (barangay),
  KEY idx_clip_archived (archived_at),
  CONSTRAINT fk_clip_reported_by FOREIGN KEY (reported_by)
    REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 5. dispatch  (one row per vehicle sent; team_id = the crew)
--    NEW: site photo + GPS columns, patient outcome / hospital flow.
--    active_unit_key: a unit may be on at most one open dispatch
--    (assigned / en_route / on_site / returning).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dispatch (
  id                    INT(11)      NOT NULL AUTO_INCREMENT,
  clip_report_id        INT(11)      NOT NULL,
  unit_id               INT(11)      NOT NULL,
  team_id               INT(11)      DEFAULT NULL,
  dispatched_by         INT(11)      NOT NULL,
  status                ENUM('assigned','en_route','on_site','returning','resolved','cancelled') NOT NULL DEFAULT 'assigned',
  predicted_eta_minutes DECIMAL(6,2) DEFAULT NULL,
  dispatched_at         DATETIME     DEFAULT CURRENT_TIMESTAMP,
  departed_at           DATETIME     DEFAULT NULL,
  arrived_at            DATETIME     DEFAULT NULL,
  returned_at           DATETIME     DEFAULT NULL,
  resolved_at           DATETIME     DEFAULT NULL,

  -- Case details (manager Incident History + admin Incident Records)
  patient_name          VARCHAR(150) DEFAULT NULL,
  patient_age_group     ENUM('Minor','Matured','Senior') DEFAULT NULL,
  patient_sex           ENUM('Male','Female') DEFAULT NULL,
  victim_count          INT(11)      DEFAULT NULL,
  vital_signs           ENUM('Positive','Negative') DEFAULT NULL,
  alcohol_breath        ENUM('Positive','Negative','Not Tested') DEFAULT NULL,
  closeout_remarks      VARCHAR(255) DEFAULT NULL,
  incident_details      TEXT         DEFAULT NULL,

  -- Site photo (responder camera) + where it was taken
  incident_photo        VARCHAR(255) DEFAULT NULL,
  photo_lat             DECIMAL(10,7) DEFAULT NULL,
  photo_lng             DECIMAL(10,7) DEFAULT NULL,
  photo_location        VARCHAR(255) DEFAULT NULL,
  photo_taken_at        DATETIME     DEFAULT NULL,
  photo_gps_source      ENUM('exif','device') DEFAULT NULL,

  -- Patient outcome / hospital hand-off
  site_outcome          ENUM('minor','hospital') DEFAULT NULL,
  outcome_at            DATETIME     DEFAULT NULL,
  hospital_name         VARCHAR(150) DEFAULT NULL,
  hospital_lat          DECIMAL(10,7) DEFAULT NULL,
  hospital_lng          DECIMAL(10,7) DEFAULT NULL,
  hospital_arrived_at   DATETIME     DEFAULT NULL,
  hospital_accepted_at  DATETIME     DEFAULT NULL,
  declined_hospitals    TEXT         DEFAULT NULL,        -- JSON array of hospital names

  active_unit_key       INT(11) GENERATED ALWAYS AS
    (CASE WHEN status IN ('assigned','en_route','on_site','returning') THEN unit_id ELSE NULL END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_active_unit (active_unit_key),
  KEY idx_dispatch_clip (clip_report_id),
  KEY idx_dispatch_unit (unit_id),
  KEY idx_dispatch_team (team_id),
  KEY idx_dispatch_by (dispatched_by),
  KEY idx_dispatch_status (status),
  KEY idx_dispatch_team_status (team_id, status),
  CONSTRAINT fk_dispatch_clip FOREIGN KEY (clip_report_id)
    REFERENCES clip_reports (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_dispatch_unit FOREIGN KEY (unit_id)
    REFERENCES ptv_units (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_dispatch_by FOREIGN KEY (dispatched_by)
    REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 6. delay_logs
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS delay_logs (
  id          INT(11) NOT NULL AUTO_INCREMENT,
  dispatch_id INT(11) NOT NULL,
  unit_id     INT(11) NOT NULL,
  reason      ENUM('Road obstruction/traffic','Vehicle breakdown/mechanical issue','Weather/flooding',
                   'Wrong/unclear location','Fuel issue','Waiting for backup unit','Unit unreachable','Other') NOT NULL,
  notes       VARCHAR(255) DEFAULT NULL,
  logged_by   INT(11)      DEFAULT NULL,
  is_manual   TINYINT(1)   NOT NULL DEFAULT 0,
  started_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_delay_dispatch (dispatch_id),
  KEY idx_delay_unit (unit_id),
  KEY idx_delay_logged_by (logged_by),
  KEY idx_delay_open (resolved_at, started_at),
  CONSTRAINT fk_delay_dispatch FOREIGN KEY (dispatch_id)
    REFERENCES dispatch (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_delay_unit FOREIGN KEY (unit_id)
    REFERENCES ptv_units (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_delay_logged_by FOREIGN KEY (logged_by)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 7. activity_log (+ activity_logs view used by includes/activity-logger.php)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
  id         INT(11)      NOT NULL AUTO_INCREMENT,
  user_id    INT(11)      DEFAULT NULL,
  email      VARCHAR(150) DEFAULT NULL,
  action     VARCHAR(100) NOT NULL,
  status     VARCHAR(20)  NOT NULL,
  ip_address VARCHAR(45)  DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activity_user (user_id),
  KEY idx_activity_lookup (action, status, created_at),
  KEY idx_activity_email (email),
  CONSTRAINT fk_activity_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE OR REPLACE VIEW activity_logs AS
  SELECT id, user_id, email, action, status, ip_address, user_agent, created_at
  FROM activity_log;

-- ---------------------------------------------------------------------
-- 8. settings  (setting_value is TEXT so the barangay list can't be cut off)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(100) NOT NULL,
  setting_value TEXT         NOT NULL,
  updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================================
-- DEMO DATA
-- =====================================================================

-- Accounts (all verified) ------------------------------------------------
INSERT INTO users (name, email, password, role, contact_no, is_verified) VALUES
('LDRRMO Admin',   'admin@hopeline.local',   '$2y$10$I/0Db0zNmO3TfLi7JZarsuD6ryXAdv01.h2c37xUhUC47bYZWgPKe', 'admin',   '09170000001', 1),
('Command Center', 'manager@hopeline.local', '$2y$10$80oPTDxXp7F10NvA9pkHJOE/NxWsu0ICcBJSkHBhdt8UlqHBM7hs6', 'manager', '09170000002', 1),
('Responder One',  'user@hopeline.local',    '$2y$10$uca3eTKxVlrRFn92ghyXmuktNMZE0qqfhs8mqEKRFM704CM2XoyM2', 'user',    '09170000003', 1),
('Responder Two',  'user2@hopeline.local',   '$2y$10$uca3eTKxVlrRFn92ghyXmuktNMZE0qqfhs8mqEKRFM704CM2XoyM2', 'user',    '09170000004', 1);

-- Teams: led by the two responder accounts -------------------------------
INSERT INTO teams (name, description, leader_id) VALUES
('Team 1', 'Primary response crew',   (SELECT id FROM users WHERE email = 'user@hopeline.local')),
('Team 2', 'Secondary response crew', (SELECT id FROM users WHERE email = 'user2@hopeline.local'));

UPDATE users SET team_id = (SELECT id FROM teams WHERE name = 'Team 1') WHERE email = 'user@hopeline.local';
UPDATE users SET team_id = (SELECT id FROM teams WHERE name = 'Team 2') WHERE email = 'user2@hopeline.local';

INSERT INTO team_members (team_id, name) VALUES
((SELECT id FROM teams WHERE name = 'Team 1'), 'Clyde'),
((SELECT id FROM teams WHERE name = 'Team 1'), 'Jamir'),
((SELECT id FROM teams WHERE name = 'Team 2'), 'Juan Dela Cruz'),
((SELECT id FROM teams WHERE name = 'Team 2'), 'Maria Santos');

-- Fleet: 2 Ambulance, 2 PTV, 1 Water Tanker, 1 Fire Truck, 1 Pick Up -----
-- All start at the LDRRMO base and are a shared pool (responder_id NULL).
INSERT INTO ptv_units (unit_name, plate_no, vehicle_type, status, current_lat, current_lng) VALUES
('Ambulance 1',    NULL,        'Ambulance',                       'Available', 8.3717147, 124.8571756),
('Ambulance 2',    NULL,        'Ambulance',                       'Available', 8.3717147, 124.8571756),
('PTV 1',          'LGU-PTV 1', 'Patient Transport Vehicle (PTV)', 'Available', 8.3717147, 124.8571756),
('PTV 2',          'LGU-PTV 2', 'Patient Transport Vehicle (PTV)', 'Available', 8.3717147, 124.8571756),
('Water Tanker 1', NULL,        'Water Tanker',                    'Available', 8.3717147, 124.8571756),
('Fire Truck 1',   NULL,        'Fire Truck',                      'Available', 8.3717147, 124.8571756),
('Pick Up 1',      NULL,        'Pick Up',                         'Available', 8.3717147, 124.8571756);