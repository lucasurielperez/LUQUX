CREATE TABLE IF NOT EXISTS qr_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(32) NOT NULL,
  qr_type ENUM('game', 'secret') NOT NULL,
  game_code VARCHAR(40) NULL,
  points_delta INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qr_codes_code (code),
  KEY idx_qr_codes_type_active (qr_type, is_active),
  KEY idx_qr_codes_game_code (game_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qr_claims (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  qr_id BIGINT UNSIGNED NOT NULL,
  player_id BIGINT UNSIGNED NOT NULL,
  claimed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qr_claims_once (qr_id, player_id),
  KEY idx_qr_claims_qr (qr_id),
  KEY idx_qr_claims_player (player_id),
  CONSTRAINT fk_qr_claims_qr FOREIGN KEY (qr_id) REFERENCES qr_codes(id) ON DELETE CASCADE,
  CONSTRAINT fk_qr_claims_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
