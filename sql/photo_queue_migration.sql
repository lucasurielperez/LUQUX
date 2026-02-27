CREATE TABLE IF NOT EXISTS photo_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  file_path VARCHAR(255) NOT NULL,
  public_url VARCHAR(500) NOT NULL,
  mime VARCHAR(64) NOT NULL,
  player_id BIGINT UNSIGNED NULL,
  display_name_snapshot VARCHAR(120) NULL,
  shown_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_photo_queue_status_id (status, id),
  KEY idx_photo_queue_player_id (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
