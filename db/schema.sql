-- =====================================================================
-- HopeLine Database Schema
-- LDRRMO Manolo Fortich — Incident & Disaster Response System
-- Covers: users, PTV units, CLIP reports, dispatch, delay logs, activity log
-- Engine: InnoDB | Charset: utf8mb4
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. USERS  (Barangay Responder = 'user', LDRRMO Personnel = 'manager', LDRRMO Admin = 'admin')
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150)        NOT NULL,
    email         VARCHAR(150)        NOT NULL UNIQUE,
    password      VARCHAR(255)        NOT NULL,               -- password_hash()
    role          ENUM('admin','manager','user') NOT NULL DEFAULT 'user',
    contact_no    VARCHAR(20)         NULL,
    is_verified   TINYINT(1)          NOT NULL DEFAULT 0,
    created_at    DATETIME            DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME            DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 2. PTV UNITS  (field vehicles operated by Barangay Responders)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ptv_units (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    unit_name     VARCHAR(100)        NOT NULL,               -- e.g. "PTV Alpha"
    plate_no      VARCHAR(20)         NULL,
    responder_id  INT                 NULL,                   -- FK -> users.id (role='user'), the assigned driver
    status        ENUM('Available','En Route','On Site','Returning','Offline') NOT NULL DEFAULT 'Available',
    current_lat   DECIMAL(10,7)       NULL,
    current_lng   DECIMAL(10,7)       NULL,
    last_ping_at  DATETIME            NULL,                   -- last GPS update time
    updated_at    DATETIME            DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at    DATETIME            DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_units_responder FOREIGN KEY (responder_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_units_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 3. CLIP REPORTS  (Caller · Location · Incident · Problem intake — T0)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clip_reports (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    clip_ref            VARCHAR(30)     NOT NULL UNIQUE,       -- e.g. CLIP-20260815-A1B2C

    -- C: Caller
    caller_name         VARCHAR(150)    NOT NULL,
    caller_contact      VARCHAR(20)     NULL,

    -- L: Location
    barangay            VARCHAR(100)    NOT NULL,
    sitio_purok         VARCHAR(150)    NULL,
    landmark            VARCHAR(255)    NULL,                  -- additional location details
    latitude             DECIMAL(10,7)  NULL,
    longitude            DECIMAL(10,7)  NULL,

    -- I: Incident
    incident_type       VARCHAR(50)     NOT NULL,              -- Medical Emergency, Fire, Vehicular Accident, etc.
    severity             ENUM('Critical','High','Moderate','Low') NOT NULL,

    -- P: Problem
    problem_resources   VARCHAR(255)    NOT NULL,              -- comma-separated resources needed
    problem_notes        TEXT           NULL,

    -- Lifecycle
    status               ENUM('pending','dispatched','resolved','cancelled') NOT NULL DEFAULT 'pending',
    reported_by          INT            NOT NULL,               -- FK -> users.id (role='manager')
    created_at            DATETIME      DEFAULT CURRENT_TIMESTAMP,  -- T0: Report Received
    updated_at            DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_clip_reported_by FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_clip_status (status),
    INDEX idx_clip_severity (severity),
    INDEX idx_clip_barangay (barangay),
    INDEX idx_clip_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 4. DISPATCH  (assignment + the three-timestamp ETA pipeline)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dispatch (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    clip_report_id    INT             NOT NULL,                -- FK -> clip_reports.id
    unit_id           INT             NOT NULL,                -- FK -> ptv_units.id
    dispatched_by     INT             NOT NULL,                -- FK -> users.id (role='manager')

    status            ENUM('assigned','en_route','on_site','resolved','cancelled') NOT NULL DEFAULT 'assigned',

    -- Predicted ETA (computed at dispatch time: Distance ÷ Speed + Delays)
    predicted_eta_minutes  DECIMAL(6,2)  NULL,
    distance_km             DECIMAL(6,2) NULL,

    -- Three-timestamp pipeline
    dispatched_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
    departed_at         DATETIME       NULL,                   -- responder taps "Depart Command Center"
    arrived_at           DATETIME      NULL,                   -- responder taps "Arrived at Site"
    resolved_at           DATETIME     NULL,

    CONSTRAINT fk_dispatch_clip FOREIGN KEY (clip_report_id) REFERENCES clip_reports(id) ON DELETE CASCADE,
    CONSTRAINT fk_dispatch_unit FOREIGN KEY (unit_id) REFERENCES ptv_units(id) ON DELETE RESTRICT,
    CONSTRAINT fk_dispatch_manager FOREIGN KEY (dispatched_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_dispatch_status (status),
    INDEX idx_dispatch_clip (clip_report_id),
    INDEX idx_dispatch_unit (unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 5. DELAY LOGS  (responder-reported or manager-logged delays)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS delay_logs (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    dispatch_id    INT             NOT NULL,                   -- FK -> dispatch.id
    unit_id        INT             NOT NULL,                   -- FK -> ptv_units.id (denormalized for fast queries)

    reason         ENUM(
                        'Road obstruction/traffic',
                        'Vehicle breakdown/mechanical issue',
                        'Weather/flooding',
                        'Wrong/unclear location',
                        'Fuel issue',
                        'Waiting for backup unit',
                        'Unit unreachable',
                        'Other'
                    ) NOT NULL,
    notes           VARCHAR(255)   NULL,

    logged_by       INT            NULL,                       -- FK -> users.id; NULL if auto-flagged by system
    is_manual       TINYINT(1)     NOT NULL DEFAULT 0,          -- 1 = manager logged it manually (e.g. unit unreachable)

    started_at      DATETIME       DEFAULT CURRENT_TIMESTAMP,
    resolved_at      DATETIME      NULL,

    CONSTRAINT fk_delay_dispatch FOREIGN KEY (dispatch_id) REFERENCES dispatch(id) ON DELETE CASCADE,
    CONSTRAINT fk_delay_unit FOREIGN KEY (unit_id) REFERENCES ptv_units(id) ON DELETE RESTRICT,
    CONSTRAINT fk_delay_logged_by FOREIGN KEY (logged_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_delay_dispatch (dispatch_id),
    INDEX idx_delay_resolved (resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 6. ACTIVITY LOG  (audit trail — matches includes/activity-logger.php)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT             NULL,                        -- FK -> users.id; NULL for failed/unknown logins
    email         VARCHAR(150)    NULL,
    action        VARCHAR(100)    NOT NULL,                     -- e.g. 'login', 'clip_report_created', 'dispatch_assigned'
    status        VARCHAR(20)     NOT NULL,                     -- 'success' | 'failed'
    created_at     DATETIME       DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- USEFUL VIEW: full incident timeline for Manager/Admin analytics
-- (Report Received -> Dispatched -> Departed -> Arrived, + delay count)
-- =====================================================================
CREATE OR REPLACE VIEW v_incident_timeline AS
SELECT
    c.id                    AS incident_id,
    c.clip_ref,
    c.caller_name,
    c.barangay,
    c.incident_type,
    c.severity,
    c.problem_resources,
    c.status                AS incident_status,
    c.created_at             AS report_received_at,
    d.id                    AS dispatch_id,
    d.status                 AS dispatch_status,
    u.unit_name,
    d.predicted_eta_minutes,
    d.dispatched_at,
    d.departed_at,
    d.arrived_at,
    d.resolved_at,
    TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at) AS actual_travel_seconds,
    TIMESTAMPDIFF(SECOND, c.created_at, d.arrived_at)   AS total_response_seconds,
    (SELECT COUNT(*) FROM delay_logs dl WHERE dl.dispatch_id = d.id) AS delay_count
FROM clip_reports c
LEFT JOIN dispatch d ON d.clip_report_id = c.id
LEFT JOIN ptv_units u ON u.id = d.unit_id;

-- =====================================================================
-- SEED: demo accounts (password for all = "password123")
-- Generate real hashes with: password_hash('password123', PASSWORD_DEFAULT)
-- =====================================================================
-- INSERT INTO users (name, email, password, role, is_verified) VALUES
-- ('Admin User',    'admin@hopeline.local',   '$2y$10$REPLACE_WITH_REAL_HASH', 'admin',   1),
-- ('Dispatcher One','manager@hopeline.local', '$2y$10$REPLACE_WITH_REAL_HASH', 'manager', 1),
-- ('Responder One', 'user@hopeline.local',    '$2y$10$REPLACE_WITH_REAL_HASH', 'user',    1);