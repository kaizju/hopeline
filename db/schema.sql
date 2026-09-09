-- =====================================================================
-- HopeLine Database Schema + Demo Data (FIXED)
-- LDRRMO Manolo Fortich — Incident & Disaster Response System
-- Engine: InnoDB | Charset: utf8mb4
--
-- FIX NOTES (what was wrong before):
--   1. The users seed used @example.com emails, but every other table
--      (ptv_units, clip_reports, dispatch, delay_logs, activity_log)
--      referenced @hopeline.local emails that did not exist yet. Those
--      subqueries returned NULL, which violated NOT NULL / FK
--      constraints -> error #1452 on clip_reports.reported_by.
--   2. Accounts referenced later (asddddd@hopeline.local, plus
--      responder2@hopeline.local and responder3@hopeline.local used by
--      ptv_units / delay_logs) were never created.
--   3. The stored "password" value was a 40-char SHA1-looking string,
--      not a real bcrypt hash, so password_verify() in PHP would never
--      match it anyway.
--   All emails are now consistently @hopeline.local, every referenced
--   account exists, and every account uses the SAME real bcrypt hash
--   for the password: password123
-- =====================================================================

CREATE DATABASE IF NOT EXISTS hopeline CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE hopeline;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. USERS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150)        NOT NULL,
    email         VARCHAR(150)        NOT NULL UNIQUE,
    password      VARCHAR(255)        NOT NULL,
    role          ENUM('admin','manager','user') NOT NULL DEFAULT 'user',
    contact_no    VARCHAR(20)         NULL,
    is_verified   TINYINT(1)          NOT NULL DEFAULT 0,
    created_at    DATETIME            DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME            DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 2. PTV UNITS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ptv_units (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    unit_name     VARCHAR(100)        NOT NULL,
    plate_no      VARCHAR(20)         NULL,
    responder_id  INT                 NULL,
    status        ENUM('Available','En Route','On Site','Returning','Offline') NOT NULL DEFAULT 'Available',
    current_lat   DECIMAL(10,7)       NULL,
    current_lng   DECIMAL(10,7)       NULL,
    last_ping_at  DATETIME            NULL,
    updated_at    DATETIME            DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at    DATETIME            DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_units_responder FOREIGN KEY (responder_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_units_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 3. CLIP REPORTS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clip_reports (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    clip_ref            VARCHAR(30)     NOT NULL UNIQUE,
    caller_name         VARCHAR(150)    NOT NULL,
    caller_contact      VARCHAR(20)     NULL,
    barangay            VARCHAR(100)    NOT NULL,
    sitio_purok         VARCHAR(150)    NULL,
    landmark            VARCHAR(255)    NULL,
    latitude            DECIMAL(10,7)   NULL,
    longitude           DECIMAL(10,7)   NULL,
    incident_type       VARCHAR(50)     NOT NULL,
    severity            ENUM('Critical','High','Moderate','Low') NULL DEFAULT NULL,
    problem_resources   VARCHAR(255)    NOT NULL,
    problem_notes       TEXT            NULL,
    status              ENUM('pending','dispatched','resolved','cancelled') NOT NULL DEFAULT 'pending',
    reported_by         INT             NOT NULL,
    created_at          DATETIME        DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_clip_reported_by FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_clip_status (status),
    INDEX idx_clip_severity (severity),
    INDEX idx_clip_barangay (barangay),
    INDEX idx_clip_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 3b. MIGRATION: make severity nullable (severity classification was
--     removed from the CLIP report form — column must accept NULL for
--     existing installs where clip_reports was already created before
--     this change). Safe/idempotent to re-run.
-- ---------------------------------------------------------------------
ALTER TABLE clip_reports MODIFY severity ENUM('Critical','High','Moderate','Low') NULL DEFAULT NULL;

-- ---------------------------------------------------------------------
-- 4. DISPATCH
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dispatch (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    clip_report_id          INT             NOT NULL,
    unit_id                 INT             NOT NULL,
    dispatched_by           INT             NOT NULL,
    status                  ENUM('assigned','en_route','on_site','resolved','cancelled') NOT NULL DEFAULT 'assigned',
    predicted_eta_minutes   DECIMAL(6,2)    NULL,
    distance_km             DECIMAL(6,2)    NULL,
    dispatched_at           DATETIME        DEFAULT CURRENT_TIMESTAMP,
    departed_at             DATETIME        NULL,
    arrived_at              DATETIME        NULL,
    resolved_at             DATETIME        NULL,
    CONSTRAINT fk_dispatch_clip FOREIGN KEY (clip_report_id) REFERENCES clip_reports(id) ON DELETE CASCADE,
    CONSTRAINT fk_dispatch_unit FOREIGN KEY (unit_id) REFERENCES ptv_units(id) ON DELETE RESTRICT,
    CONSTRAINT fk_dispatch_manager FOREIGN KEY (dispatched_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_dispatch_status (status),
    INDEX idx_dispatch_clip (clip_report_id),
    INDEX idx_dispatch_unit (unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 5. DELAY LOGS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS delay_logs (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    dispatch_id    INT             NOT NULL,
    unit_id        INT             NOT NULL,
    reason         ENUM(
                        'Road obstruction/traffic','Vehicle breakdown/mechanical issue','Weather/flooding',
                        'Wrong/unclear location','Fuel issue','Waiting for backup unit','Unit unreachable','Other'
                    ) NOT NULL,
    notes          VARCHAR(255)    NULL,
    logged_by      INT             NULL,
    is_manual      TINYINT(1)      NOT NULL DEFAULT 0,
    started_at     DATETIME        DEFAULT CURRENT_TIMESTAMP,
    resolved_at    DATETIME        NULL,
    CONSTRAINT fk_delay_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatch(id) ON DELETE CASCADE,
    CONSTRAINT fk_delay_unit FOREIGN KEY (unit_id) REFERENCES ptv_units(id) ON DELETE RESTRICT,
    CONSTRAINT fk_delay_logged_by FOREIGN KEY (logged_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_delay_dispatch (dispatch_id),
    INDEX idx_delay_resolved (resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 6. ACTIVITY LOG
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT             NULL,
    email         VARCHAR(150)    NULL,
    action        VARCHAR(100)    NOT NULL,
    status        VARCHAR(20)     NOT NULL,
    created_at    DATETIME        DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 7. SETTINGS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    setting_key     VARCHAR(100)    PRIMARY KEY,
    setting_value   VARCHAR(255)    NOT NULL,
    updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- VIEW
-- =====================================================================
CREATE OR REPLACE VIEW v_incident_timeline AS
SELECT
    c.id AS incident_id, c.clip_ref, c.caller_name, c.barangay, c.incident_type, c.severity,
    c.problem_resources, c.status AS incident_status, c.created_at AS report_received_at,
    d.id AS dispatch_id, d.status AS dispatch_status, u.unit_name, d.predicted_eta_minutes,
    d.dispatched_at, d.departed_at, d.arrived_at, d.resolved_at,
    TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at) AS actual_travel_seconds,
    TIMESTAMPDIFF(SECOND, c.created_at, d.arrived_at) AS total_response_seconds,
    (SELECT COUNT(*) FROM delay_logs dl WHERE dl.dispatch_id = d.id) AS delay_count
FROM clip_reports c
LEFT JOIN dispatch d ON d.clip_report_id = c.id
LEFT JOIN ptv_units u ON u.id = d.unit_id;

-- =====================================================================
-- SEED: SETTINGS
-- =====================================================================
INSERT INTO settings (setting_key, setting_value) VALUES
('default_avg_speed_kmh', '40'),
('delay_threshold_minutes', '15'),
('barangay_list', 'Agusan Canyon\nAlae\nDahilayan\nDamilag\nDalirig\nDiclum\nGuilang-guilang\nKalugmanan\nLindaban\nLingion\nLunocan\nMaluko\nMambatangan\nMampayag\nMinsuro\nSan Miguel\nSankanan\nSantiago\nTankulan (Poblacion)\nTicala')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- =====================================================================
-- SEED: ACCOUNTS
-- Every account below shares the SAME real bcrypt hash, for password:
--
--   password123
--
-- (verified working with PHP's password_verify() — PHP accepts $2b$
-- hashes the same as $2y$.)
--
--   Your account (Admin) ....... asddddd@hopeline.local  / password123
--   Admin demo .................. admin@hopeline.local    / password123
--   Manager / Dispatcher demo ... manager@hopeline.local  / password123
--   Responder demo 1 ............ user@hopeline.local     / password123
--   Responder demo 2 ............ responder2@hopeline.local / password123
--   Responder demo 3 ............ responder3@hopeline.local / password123
--
-- CHANGE THESE PASSWORDS before deploying anywhere outside local testing.
-- =====================================================================
INSERT INTO users (name, email, password, role, contact_no, is_verified) VALUES
('Asddddd',     'asddddd@hopeline.local',   '$2b$12$VC0Fx67VGiITH2LB5DZmROVW0IpITNTRHGuiuODo6xsfk7ZNcR1yW', 'admin',   '09170000000', 1),
('Admin User',  'admin@hopeline.local',     '$2b$12$VC0Fx67VGiITH2LB5DZmROVW0IpITNTRHGuiuODo6xsfk7ZNcR1yW', 'admin',   '09170000001', 1),
('Dispatcher',  'manager@hopeline.local',   '$2b$12$VC0Fx67VGiITH2LB5DZmROVW0IpITNTRHGuiuODo6xsfk7ZNcR1yW', 'manager', '09170000002', 1),
('Responder',   'user@hopeline.local',      '$2b$12$VC0Fx67VGiITH2LB5DZmROVW0IpITNTRHGuiuODo6xsfk7ZNcR1yW', 'user',    '09170000003', 1),
('Responder 2', 'responder2@hopeline.local','$2b$12$VC0Fx67VGiITH2LB5DZmROVW0IpITNTRHGuiuODo6xsfk7ZNcR1yW', 'user',    '09170000004', 1),
('Responder 3', 'responder3@hopeline.local','$2b$12$VC0Fx67VGiITH2LB5DZmROVW0IpITNTRHGuiuODo6xsfk7ZNcR1yW', 'user',    '09170000005', 1);

-- =====================================================================
-- SEED: PTV UNITS  (linked to the responder demo accounts above)
-- =====================================================================
INSERT INTO ptv_units (unit_name, plate_no, responder_id, status, current_lat, current_lng) VALUES
('PTV Alpha',   'LGU-101', (SELECT id FROM users WHERE email='user@hopeline.local'),       'En Route',  8.3745, 124.8670),
('PTV Bravo',   'LGU-102', (SELECT id FROM users WHERE email='responder2@hopeline.local'), 'Available', 8.3736, 124.8694),
('PTV Charlie', 'LGU-103', (SELECT id FROM users WHERE email='responder3@hopeline.local'), 'On Site',   8.3980, 124.8550),
('PTV Delta',   'LGU-104', NULL,                                                            'Available', 8.3600, 124.8800),
('PTV Echo',    'LGU-105', NULL,                                                            'Offline',   8.3690, 124.8610);

-- =====================================================================
-- SEED: CLIP REPORTS
-- =====================================================================
INSERT INTO clip_reports (clip_ref, caller_name, caller_contact, barangay, sitio_purok, landmark, latitude, longitude, incident_type, severity, problem_resources, problem_notes, status, reported_by, created_at) VALUES
('CLIP-20260815-A1B2C', 'Maria Santos',    '09171112222', 'Maluko',              'Purok 2', 'Beside the covered court, blue two-story house', 8.4310, 124.8420, 'Medical Emergency',    'Critical', 'Ambulance / PTV',                        'Patient having difficulty breathing.', 'dispatched', (SELECT id FROM users WHERE email='manager@hopeline.local'), NOW() - INTERVAL 8 MINUTE),
('CLIP-20260815-D4E5F', 'Pedro Reyes',     '09172223333', 'Damilag',             NULL,      'Near the national highway junction',              8.3990, 124.8480, 'Vehicular Accident',   'High',     'Ambulance / PTV, Extraction Team',       'Two motorcycles collided.',           'resolved',   (SELECT id FROM users WHERE email='manager@hopeline.local'), NOW() - INTERVAL 1 DAY - INTERVAL 2 HOUR),
('CLIP-20260814-Q1W2E', 'Ana Lim',         '09173334444', 'Dahilayan',           NULL,      'Near the zipline entrance road',                  8.1850, 124.8360, 'Flood / Landslide',    'Moderate', 'Rescue Team, Water Rescue / Rubber Boat','Small landslide blocking the road.',   'resolved',   (SELECT id FROM users WHERE email='manager@hopeline.local'), NOW() - INTERVAL 2 DAY - INTERVAL 3 HOUR),
('CLIP-20260813-M3N4O', 'Juan Dela Cruz',  '09174445555', 'Tankulan (Poblacion)','Purok 5', 'Beside the public market',                        8.3736, 124.8694, 'Fire',                 'Critical', 'Fire Truck, Rescue Team',                'Kitchen fire spreading to the roof.', 'resolved',   (SELECT id FROM users WHERE email='manager@hopeline.local'), NOW() - INTERVAL 3 DAY - INTERVAL 5 HOUR),
('CLIP-20260812-G7H8I', 'Rosa Ibarra',     '09175556666', 'Dalirig',             NULL,      'Sitio boundary near the river crossing',          8.3410, 124.9010, 'Flood / Landslide',    'Moderate', 'Rescue Team',                            'Rising floodwater, family stranded.', 'pending',    (SELECT id FROM users WHERE email='manager@hopeline.local'), NOW() - INTERVAL 40 MINUTE),
('CLIP-20260815-J9K1L', 'Ben Torres',      '09176667777', 'San Miguel',          NULL,      'Near the elementary school',                      8.4020, 124.8790, 'Fire',                 'Critical', 'Fire Truck',                             NULL,                                   'pending',    (SELECT id FROM users WHERE email='manager@hopeline.local'), NOW() - INTERVAL 2 MINUTE),
('CLIP-20260810-Z9Y8X', 'Lito Ramos',      '09177778888', 'Alae',                NULL,      'Along the provincial road',                       8.3560, 124.7990, 'Vehicular Accident',   'Low',      'Ambulance / PTV',                       'Minor bump, no injuries reported.',   'cancelled',  (SELECT id FROM users WHERE email='manager@hopeline.local'), NOW() - INTERVAL 5 DAY);

-- =====================================================================
-- SEED: DISPATCH  (three-timestamp pipeline for resolved/active incidents)
-- =====================================================================
INSERT INTO dispatch (clip_report_id, unit_id, dispatched_by, status, predicted_eta_minutes, distance_km, dispatched_at, departed_at, arrived_at, resolved_at) VALUES
((SELECT id FROM clip_reports WHERE clip_ref='CLIP-20260815-A1B2C'), (SELECT id FROM ptv_units WHERE unit_name='PTV Alpha'),   (SELECT id FROM users WHERE email='manager@hopeline.local'), 'en_route', 12.5, 8.2, NOW() - INTERVAL 7 MINUTE,              NOW() - INTERVAL 6 MINUTE,               NULL,                                   NULL),
((SELECT id FROM clip_reports WHERE clip_ref='CLIP-20260815-D4E5F'), (SELECT id FROM ptv_units WHERE unit_name='PTV Charlie'), (SELECT id FROM users WHERE email='manager@hopeline.local'), 'resolved', 9.0,  6.1, NOW() - INTERVAL 1 DAY - INTERVAL 2 HOUR, NOW() - INTERVAL 1 DAY - INTERVAL 1 HOUR - INTERVAL 55 MINUTE, NOW() - INTERVAL 1 DAY - INTERVAL 1 HOUR - INTERVAL 40 MINUTE, NOW() - INTERVAL 1 DAY - INTERVAL 1 HOUR - INTERVAL 20 MINUTE),
((SELECT id FROM clip_reports WHERE clip_ref='CLIP-20260814-Q1W2E'), (SELECT id FROM ptv_units WHERE unit_name='PTV Bravo'),   (SELECT id FROM users WHERE email='manager@hopeline.local'), 'resolved', 40.0, 22.4,NOW() - INTERVAL 2 DAY - INTERVAL 3 HOUR, NOW() - INTERVAL 2 DAY - INTERVAL 2 HOUR - INTERVAL 50 MINUTE, NOW() - INTERVAL 2 DAY - INTERVAL 1 HOUR - INTERVAL 30 MINUTE, NOW() - INTERVAL 2 DAY - INTERVAL 1 HOUR),
((SELECT id FROM clip_reports WHERE clip_ref='CLIP-20260813-M3N4O'), (SELECT id FROM ptv_units WHERE unit_name='PTV Alpha'),   (SELECT id FROM users WHERE email='manager@hopeline.local'), 'resolved', 4.0,  2.9, NOW() - INTERVAL 3 DAY - INTERVAL 5 HOUR, NOW() - INTERVAL 3 DAY - INTERVAL 4 HOUR - INTERVAL 58 MINUTE, NOW() - INTERVAL 3 DAY - INTERVAL 4 HOUR - INTERVAL 53 MINUTE, NOW() - INTERVAL 3 DAY - INTERVAL 4 HOUR - INTERVAL 30 MINUTE);

-- =====================================================================
-- SEED: DELAY LOGS
-- =====================================================================
INSERT INTO delay_logs (dispatch_id, unit_id, reason, notes, logged_by, is_manual, started_at, resolved_at) VALUES
((SELECT d.id FROM dispatch d JOIN clip_reports c ON c.id=d.clip_report_id WHERE c.clip_ref='CLIP-20260815-A1B2C'),
 (SELECT id FROM ptv_units WHERE unit_name='PTV Alpha'),
 'Road obstruction/traffic', 'Fallen tree blocking the main road near Purok 2', (SELECT id FROM users WHERE email='user@hopeline.local'), 0,
 NOW() - INTERVAL 6 MINUTE, NULL),

((SELECT d.id FROM dispatch d JOIN clip_reports c ON c.id=d.clip_report_id WHERE c.clip_ref='CLIP-20260815-D4E5F'),
 (SELECT id FROM ptv_units WHERE unit_name='PTV Charlie'),
 'Vehicle breakdown/mechanical issue', 'Flat tire, backup requested', (SELECT id FROM users WHERE email='responder3@hopeline.local'), 0,
 NOW() - INTERVAL 1 DAY - INTERVAL 1 HOUR - INTERVAL 50 MINUTE, NOW() - INTERVAL 1 DAY - INTERVAL 1 HOUR - INTERVAL 42 MINUTE),

((SELECT d.id FROM dispatch d JOIN clip_reports c ON c.id=d.clip_report_id WHERE c.clip_ref='CLIP-20260814-Q1W2E'),
 (SELECT id FROM ptv_units WHERE unit_name='PTV Bravo'),
 'Unit unreachable', 'No response via radio, manually flagged for reassignment', (SELECT id FROM users WHERE email='manager@hopeline.local'), 1,
 NOW() - INTERVAL 2 DAY - INTERVAL 2 HOUR - INTERVAL 20 MINUTE, NOW() - INTERVAL 2 DAY - INTERVAL 2 HOUR - INTERVAL 5 MINUTE);

-- =====================================================================
-- SEED: ACTIVITY LOG
-- =====================================================================
INSERT INTO activity_log (user_id, email, action, status, created_at) VALUES
((SELECT id FROM users WHERE email='asddddd@hopeline.local'), 'asddddd@hopeline.local', 'login', 'success', NOW() - INTERVAL 1 HOUR),
((SELECT id FROM users WHERE email='manager@hopeline.local'), 'manager@hopeline.local', 'clip_report_created', 'success', NOW() - INTERVAL 8 MINUTE),
((SELECT id FROM users WHERE email='manager@hopeline.local'), 'manager@hopeline.local', 'dispatch_assigned', 'success', NOW() - INTERVAL 7 MINUTE),
((SELECT id FROM users WHERE email='user@hopeline.local'),    'user@hopeline.local',    'departed_command_center', 'success', NOW() - INTERVAL 6 MINUTE),
((SELECT id FROM users WHERE email='user@hopeline.local'),    'user@hopeline.local',    'delay_reported', 'success', NOW() - INTERVAL 6 MINUTE),
(NULL, 'unknown@test.com', 'login', 'failed', NOW() - INTERVAL 3 HOUR);