ALTER TABLE dispatch
  ADD COLUMN live_eta_minutes DECIMAL(6,2) DEFAULT NULL,
  ADD COLUMN live_eta_origin_lat DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN live_eta_origin_lng DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN live_eta_distance_km DECIMAL(7,2) DEFAULT NULL,
  ADD COLUMN live_eta_source ENUM('device','unit','base') DEFAULT NULL,
  ADD COLUMN live_eta_method ENUM('osrm','estimate') DEFAULT NULL;
