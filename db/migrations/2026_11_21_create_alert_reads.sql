CREATE TABLE alert_reads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  alert_key VARCHAR(120) NOT NULL,
  read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_alert (user_id, alert_key),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
