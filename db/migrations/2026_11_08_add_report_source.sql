-- Existing HopeLine databases may predate report_source.
-- Run this once against the hopeline database to support CLIP report logging.
ALTER TABLE clip_reports
  ADD COLUMN report_source ENUM('Phone Call','Walk-in') NOT NULL DEFAULT 'Phone Call'
  AFTER barangay;
