CREATE TABLE IF NOT EXISTS error_logs (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  level VARCHAR(10) NOT NULL,
  message TEXT NOT NULL,
  file VARCHAR(255) NULL,
  line INT NULL,
  request_id VARCHAR(40) NOT NULL,
  url TEXT NULL,
  method VARCHAR(10) NULL,
  user_agent TEXT NULL,
  ip VARCHAR(45) NULL,
  context VARCHAR(30) NULL,
  extra_json LONGTEXT NULL,
  INDEX idx_error_logs_created_at (created_at),
  INDEX idx_error_logs_level (level)
);
