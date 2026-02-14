CREATE TABLE IF NOT EXISTS virus_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME NULL,
  leaderboard_snapshot_json JSON NULL,
  PRIMARY KEY (id),
  KEY idx_virus_sessions_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS virus_player_state (
  session_id BIGINT UNSIGNED NOT NULL,
  player_id BIGINT UNSIGNED NOT NULL,
  role ENUM('virus', 'antidote') NOT NULL,
  power INT NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (session_id, player_id),
  KEY idx_virus_player_state_role (session_id, role),
  CONSTRAINT fk_virus_player_state_session FOREIGN KEY (session_id) REFERENCES virus_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_virus_player_state_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT chk_virus_power CHECK (power >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS virus_interactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  player_a BIGINT UNSIGNED NOT NULL,
  player_b BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_virus_pair_once (session_id, player_a, player_b),
  KEY idx_virus_interactions_player_a (session_id, player_a),
  KEY idx_virus_interactions_player_b (session_id, player_b),
  CONSTRAINT fk_virus_interactions_session FOREIGN KEY (session_id) REFERENCES virus_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_virus_interactions_player_a FOREIGN KEY (player_a) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_virus_interactions_player_b FOREIGN KEY (player_b) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT chk_virus_pair_order CHECK (player_a < player_b)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
